<?php

namespace Tests\Feature\Admin;

use App\Jobs\SendLeadsIntegrationEvent;
use App\Models\Admin\Plan;
use App\Models\LeadsIntegrationEvent;
use App\Models\LeadsIntegrationSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\LeadsIntegration\LeadsIntegrationEvents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Acompanhamento de cadastro com o VetorLeads (VETOROS-LEADS-INTEGRATION).
 */
class LeadsIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private const ENDPOINT = 'https://vetorleads.com.br/api/integrations/sales/events';

    private const REFERRAL = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ012345';

    private Plan $trialPlan;

    private Plan $paidPlan;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Bus::fake();

        $this->trialPlan = Plan::factory()->create(['name' => 'Teste gratuito', 'slug' => 'trial-14-dias']);
        $this->paidPlan = Plan::factory()->create(['name' => 'Profissional', 'slug' => 'profissional']);
    }

    private function enableApi(): void
    {
        LeadsIntegrationSetting::current()->forceFill([
            'mode' => LeadsIntegrationSetting::MODE_API,
            'endpoint_url' => self::ENDPOINT,
            'token' => str_repeat('t', 48),
        ])->save();
    }

    private function trialTenant(array $overrides = []): Tenant
    {
        return Tenant::factory()->create(array_merge([
            'plan_id' => $this->trialPlan->id,
            'expires_at' => now()->addDays(14),
            'last_payment_id' => null,
            'billing_mode' => Tenant::BILLING_PLAN,
        ], $overrides));
    }

    public function test_manual_mode_records_nothing(): void
    {
        $tenant = $this->trialTenant();

        app(LeadsIntegrationEvents::class)->tenantRegistered($tenant);

        $this->assertSame(0, LeadsIntegrationEvent::query()->count());
        Bus::assertNotDispatched(SendLeadsIntegrationEvent::class);
    }

    public function test_registration_sends_real_trial_dates_and_product_vetoros(): void
    {
        $this->enableApi();
        $tenant = $this->trialTenant(['leads_referral_code' => self::REFERRAL]);

        app(LeadsIntegrationEvents::class)->tenantRegistered($tenant);

        $registered = LeadsIntegrationEvent::query()->where('type', LeadsIntegrationEvent::TYPE_REGISTERED)->sole();
        $started = LeadsIntegrationEvent::query()->where('type', LeadsIntegrationEvent::TYPE_TRIAL_STARTED)->sole();

        $this->assertSame('vetoros', $registered->payload['product']);
        $this->assertSame($tenant->id, $registered->payload['account_id']);
        $this->assertSame(self::REFERRAL, $registered->payload['referral']['code']);
        $this->assertSame($tenant->expires_at->toIso8601String(), $started->payload['trial']['ends_at']);
        $this->assertSame($tenant->created_at->toIso8601String(), $started->payload['trial']['started_at']);
        Bus::assertDispatched(SendLeadsIntegrationEvent::class, 2);
    }

    public function test_trial_is_not_sent_for_paid_plan_tenants(): void
    {
        $this->enableApi();
        $tenant = $this->trialTenant(['plan_id' => $this->paidPlan->id]);

        app(LeadsIntegrationEvents::class)->tenantRegistered($tenant);

        $this->assertSame(0, LeadsIntegrationEvent::query()->where('type', LeadsIntegrationEvent::TYPE_TRIAL_STARTED)->count());
    }

    public function test_extension_sends_new_end_date_and_keeps_start(): void
    {
        $this->enableApi();
        $tenant = $this->trialTenant();
        $tenant->forceFill(['expires_at' => now()->addDays(30)])->save();

        app(LeadsIntegrationEvents::class)->trialChanged($tenant->refresh());

        $event = LeadsIntegrationEvent::query()->where('type', LeadsIntegrationEvent::TYPE_TRIAL_STARTED)->latest('id')->first();
        $this->assertSame($tenant->expires_at->toIso8601String(), $event->payload['trial']['ends_at']);
    }

    public function test_subscription_activated_is_sent_once(): void
    {
        $this->enableApi();
        $tenant = $this->trialTenant();

        $events = app(LeadsIntegrationEvents::class);
        $events->subscriptionActivated($tenant);
        $events->subscriptionActivated($tenant);

        $this->assertSame(1, LeadsIntegrationEvent::query()->where('type', LeadsIntegrationEvent::TYPE_SUBSCRIPTION_ACTIVATED)->count());
    }

    public function test_failure_inside_the_event_never_breaks_the_flow(): void
    {
        $this->enableApi();
        $tenant = $this->trialTenant();
        LeadsIntegrationEvent::creating(fn () => throw new \RuntimeException('falha simulada'));

        $result = app(LeadsIntegrationEvents::class)->record(LeadsIntegrationEvent::TYPE_REGISTERED, $tenant, now());

        $this->assertNull($result);
        $this->assertTrue(Tenant::query()->whereKey($tenant->id)->exists());
    }

    public function test_expire_command_records_only_unpaid_recent_trials(): void
    {
        $this->enableApi();
        $expired = $this->trialTenant(['expires_at' => now()->subDays(2)]);
        $paid = $this->trialTenant(['expires_at' => now()->subDays(2), 'last_payment_id' => 'pay-1']);
        $custom = $this->trialTenant(['expires_at' => now()->subDays(2), 'billing_mode' => Tenant::BILLING_CUSTOM]);
        $old = $this->trialTenant(['expires_at' => now()->subDays(30)]);
        $activated = $this->trialTenant(['expires_at' => now()->subDays(2)]);
        app(LeadsIntegrationEvents::class)->subscriptionActivated($activated);

        $this->artisan('vetoros:leads-integration-expire-trials')->assertSuccessful();
        $this->artisan('vetoros:leads-integration-expire-trials')->assertSuccessful();

        $recorded = LeadsIntegrationEvent::query()->where('type', LeadsIntegrationEvent::TYPE_TRIAL_EXPIRED)->pluck('tenant_id')->all();
        $this->assertSame([$expired->id], $recorded);
        $this->assertNotContains($paid->id, $recorded);
        $this->assertNotContains($custom->id, $recorded);
        $this->assertNotContains($old->id, $recorded);
        $this->assertNotContains($activated->id, $recorded);
    }

    public function test_registration_keeps_valid_referral_from_the_link(): void
    {
        $this->get('/register?ref='.self::REFERRAL)->assertOk();

        $this->post('/register', [
            'name' => 'Ana Teste',
            'company' => 'Oficina Teste Ltda',
            'cnpj' => '11222333000181',
            'phone' => '51999990000',
            'whatsapp' => '51999990000',
            'email' => 'ana.teste@example.com',
            'password' => 'Senha-forte-123',
            'password_confirmation' => 'Senha-forte-123',
            'accepted_terms' => true,
        ])->assertRedirect();

        $this->assertSame(self::REFERRAL, Tenant::query()->where('email', 'ana.teste@example.com')->value('leads_referral_code'));
    }

    public function test_root_can_open_the_leads_screen_and_regular_users_cannot(): void
    {
        $this->actingAs(User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_SYSTEM]));
        $this->get(route('admin.leads-integration.index'))->assertOk();

        $this->actingAs(User::factory()->create(['roles' => 9]));
        $this->get(route('admin.leads-integration.index'))->assertRedirect();
    }
}
