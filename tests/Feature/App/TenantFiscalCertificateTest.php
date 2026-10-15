<?php

namespace Tests\Feature\App;

use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class TenantFiscalCertificateTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private User $admin;

    private FiscalSetting $setting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        config(['services.spedy.environment' => 'sandbox', 'services.spedy.owner_api_key' => 'test-owner']);
        $this->tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $this->admin = User::factory()->forTenant($this->tenant->id)->create();
        $this->actingAs($this->admin)->withSession(['tenant_id' => $this->tenant->id]);
        $this->setting = FiscalSetting::query()->create([
            'tenant_id' => $this->tenant->id,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED,
            'spedy_company_id' => 'own-company',
            'api_token' => 'own-company-key',
        ]);
    }

    private function payload(string $extension = 'pfx'): array
    {
        return [
            'certificate' => UploadedFile::fake()->createWithContent('private.'.$extension, 'test-pkcs12'),
            'password' => 'private-test-password',
            'confirm_replacement' => true,
        ];
    }

    private function accepted(): array
    {
        return ['id' => 'test-certificate', 'isActive' => true, 'subject' => 'Test company', 'expirationAt' => now()->addYear()->toIso8601String()];
    }

    public function test_admin_can_upload_both_extensions_without_saving_secrets_or_changing_root_controls(): void
    {
        foreach (['pfx', 'p12'] as $extension) {
            Http::fake(['*/companies/own-company/certificates' => Http::response($this->accepted())]);
            $this->post(route('app.fiscal-settings.certificate'), [
                ...$this->payload($extension),
                'confirm_replacement' => true,
                'api_token' => 'attacker-key', 'spedy_company_id' => 'other-company',
                'nfe_allowed' => true, 'production_released_at' => now()->toIso8601String(),
            ])->assertSessionHas('success')->assertSessionMissing('_old_input.password');
            $this->assertSame('Test company', $this->setting->refresh()->certificate_subject);
            $this->assertSame('own-company-key', $this->setting->api_token);
            $this->assertFalse((bool) $this->setting->nfe_allowed);
            $this->assertNull($this->setting->production_released_at);
            $this->assertStringNotContainsString('private-test-password', json_encode(DB::table('fiscal_settings')->first()));
            $this->assertStringNotContainsString('test-pkcs12', json_encode(DB::table('fiscal_settings')->first()));
            $this->assertStringNotContainsString('private-test-password', json_encode(session()->all()));
            Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/companies/own-company/certificates')
                && $request->hasHeader('X-Api-Key', 'test-owner') && $request->isMultipart());
        }
    }

    public function test_operator_with_settings_permission_cannot_upload(): void
    {
        $operator = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_OPERATOR]);
        $this->actingAs($operator)->postJson(route('app.fiscal-settings.certificate'), $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_platform_or_tenant_disabled_prevents_upload(): void
    {
        $this->tenant->update(['automatic_fiscal_emission_enabled' => false]);
        $this->postJson(route('app.fiscal-settings.certificate'), $this->payload())->assertForbidden();
        $this->tenant->update(['automatic_fiscal_emission_enabled' => true]);
        config(['services.spedy.owner_api_key' => null]);
        $this->postJson(route('app.fiscal-settings.certificate'), $this->payload())->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_pending_registration_has_actionable_error_without_transmission(): void
    {
        $this->setting->update(['registration_status' => FiscalSetting::REGISTRATION_PENDING, 'spedy_company_id' => null]);
        $this->post(route('app.fiscal-settings.certificate'), $this->payload())->assertSessionHasErrors('certificate');
        Http::assertNothingSent();
    }

    public function test_invalid_extension_size_and_missing_password_are_rejected_without_flashing_password(): void
    {
        foreach ([
            $this->payload('txt'),
            [...$this->payload(), 'certificate' => UploadedFile::fake()->create('large.pfx', 2049)],
            [...$this->payload(), 'password' => ''],
        ] as $payload) {
            $this->post(route('app.fiscal-settings.certificate'), $payload)
                ->assertSessionHasErrors()->assertSessionMissing('_old_input.password');
        }
        Http::assertNothingSent();
    }

    public function test_replacement_requires_confirmation_and_preserves_previous_metadata_until_success(): void
    {
        $this->setting->update(['certificate_subject' => 'Previous company', 'certificate_expires_at' => now()->addMonth()]);
        $this->post(route('app.fiscal-settings.certificate'), [...$this->payload(), 'confirm_replacement' => false])->assertSessionHasErrors('confirm_replacement');
        $this->assertSame('Previous company', $this->setting->refresh()->certificate_subject);
        Http::assertNothingSent();
        Http::fake(['*/certificates' => Http::response($this->accepted())]);
        $this->post(route('app.fiscal-settings.certificate'), [...$this->payload(), 'confirm_replacement' => true])->assertSessionHas('success');
        $this->assertSame('Test company', $this->setting->refresh()->certificate_subject);
    }

    public function test_forged_session_and_company_identifiers_cannot_target_another_tenant(): void
    {
        $other = Tenant::factory()->create();
        $otherSetting = FiscalSetting::query()->withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'spedy_company_id' => 'other-company', 'api_token' => 'other-key',
            'certificate_subject' => 'Other certificate',
        ]);
        Http::fake(['*/companies/own-company/certificates' => Http::response($this->accepted())]);
        $this->withSession(['tenant_id' => $other->id])->post(route('app.fiscal-settings.certificate'), [
            ...$this->payload(), 'tenant_id' => $other->id, 'spedy_company_id' => 'other-company', 'api_token' => 'other-key',
        ])->assertSessionHas('success');
        Http::assertSentCount(1);
        Http::assertSent(fn (HttpRequest $request) => str_ends_with($request->url(), '/companies/own-company/certificates') && $request->hasHeader('X-Api-Key', 'test-owner'));
        $this->assertSame('Other certificate', $otherSetting->refresh()->certificate_subject);
    }

    public function test_provider_errors_do_not_echo_sensitive_details_to_logs_or_session(): void
    {
        Log::spy();
        Http::fake(['*/certificates' => Http::response(['message' => 'private-test-password test-pkcs12 own-company-key'], 400)]);
        $this->post(route('app.fiscal-settings.certificate'), $this->payload())->assertSessionHas('error')->assertSessionMissing('success');
        $this->assertStringNotContainsString('private-test-password', json_encode(session()->all()));
        Log::shouldHaveReceived('warning')->withArgs(fn ($message, $context) => $context['status'] === 400 && $context['errors'] === [])->once();
        $this->assertNull($this->setting->refresh()->certificate_expires_at);
    }

    public function test_unconfirmed_inactive_expired_or_malformed_provider_responses_never_report_success(): void
    {
        foreach ([[], [...$this->accepted(), 'isActive' => false], [...$this->accepted(), 'expirationAt' => now()->subDay()->toIso8601String()],
            [...$this->accepted(), 'expirationAt' => 'invalid-date'], [...$this->accepted(), 'expirationAt' => null]] as $response) {
            session()->forget(['error', 'success']);
            Http::fake(['*/certificates' => Http::response($response)]);
            $this->post(route('app.fiscal-settings.certificate'), $this->payload())->assertSessionHas('error')->assertSessionMissing('success');
            $this->assertNull($this->setting->refresh()->certificate_expires_at);
        }
    }

    public function test_insecure_provider_url_is_rejected_before_sending_secrets(): void
    {
        config(['services.spedy.base_urls.sandbox' => 'http://unsafe.test/v1']);
        $this->post(route('app.fiscal-settings.certificate'), $this->payload())->assertSessionHas('error');
        Http::assertNothingSent();
    }

    public function test_connection_failure_server_error_and_redirect_preserve_existing_certificate(): void
    {
        $this->setting->update(['certificate_subject' => 'Previous company', 'certificate_expires_at' => now()->addMonth()]);
        foreach ([Http::failedConnection(), Http::response([], 500), Http::response('', 302, ['Location' => 'https://other.test'])] as $response) {
            session()->forget(['error', 'success']);
            Http::fake(['*/certificates' => $response]);
            $this->post(route('app.fiscal-settings.certificate'), [...$this->payload(), 'confirm_replacement' => true])
                ->assertSessionHas('error')->assertSessionMissing('success')->assertSessionMissing('_old_input.password');
            $this->assertSame('Previous company', $this->setting->refresh()->certificate_subject);
            Http::assertNotSent(fn (HttpRequest $request) => str_starts_with($request->url(), 'https://other.test'));
        }
    }

    public function test_screen_exposes_readiness_and_metadata_without_credentials_or_api_calls(): void
    {
        $this->get(route('app.fiscal-settings.show'))->assertInertia(fn (AssertableInertia $page) => $page
            ->component('app/fiscal-settings/index')->where('certificateUploadAllowed', true)
            ->missing('setting.api_token')->missing('setting.spedy_company_id'));
        $this->setting->update(['registration_status' => FiscalSetting::REGISTRATION_PENDING, 'spedy_company_id' => null]);
        $this->get(route('app.fiscal-settings.show'))->assertInertia(fn (AssertableInertia $page) => $page->where('certificateUploadAllowed', false));
        Http::assertNothingSent();
    }
}
