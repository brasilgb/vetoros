<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\App\Company;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Observers\CompanyIdentityObserver;
use App\Services\Fiscal\FiscalValidationException;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Fiscal\Spedy\SpedyPayloadBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia;
use RuntimeException;
use Tests\TestCase;

/** VETOR-ROOT-FISCAL-02 (Fase B): cadastro fiscal das empresas e tomador da NFS-e do SaaS. */
class CompanyFiscalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    private User $tenantAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        $this->withoutVite();
        $this->tenant = Tenant::factory()->create(['company' => 'Assistência Original', 'cnpj' => '11.222.333/0001-81']);
        $this->tenantAdmin = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->company = Company::query()->create(['tenant_id' => $this->tenant->id, 'companyname' => 'Assistência Original', 'cnpj' => '11.222.333/0001-81']);
        $this->app->usePublicPath(storage_path('framework/testing/company-identity-public'));
        File::ensureDirectoryExists(public_path('storage/logos'));
    }

    protected function tearDown(): void
    {
        if (str_starts_with(public_path(), storage_path('framework/testing'))) {
            File::deleteDirectory(public_path());
        }
        parent::tearDown();
    }

    public function test_company_screen_accepts_legal_names_up_to_150_characters_and_syncs_the_tenant(): void
    {
        $legalName = str_repeat('Razão Social Longa ', 7).'Ltda';
        $legalName = mb_substr($legalName, 0, 150);
        $street = mb_substr(str_repeat('Avenida Presidente ', 10), 0, 150);

        $this->asTenantAdmin()->put(route('app.company.update', $this->company), [
            'companyname' => $legalName,
            'street' => $street,
            'district' => str_repeat('B', 100),
            'city' => str_repeat('C', 100),
            'complement' => str_repeat('D', 100),
        ])->assertSessionHasNoErrors();

        $this->assertSame($legalName, $this->company->refresh()->companyname);
        $this->assertSame($legalName, $this->tenant->refresh()->company);
        $this->assertSame($street, $this->tenant->street);

        $this->asTenantAdmin()->put(route('app.company.update', $this->company), [
            'companyname' => str_repeat('X', 151),
        ])->assertSessionHasErrors('companyname');
    }

    public function test_cnpj_and_legal_name_changes_are_audited_from_the_company_screen(): void
    {
        $this->asTenantAdmin()->put(route('app.company.update', $this->company), [
            'companyname' => 'Assistência Nova Razão',
            'cnpj' => '11.222.333/0001-81',
            'telephone' => '1133334444',
        ])->assertSessionHasNoErrors();

        $audits = FiscalAdminAudit::query()->where('action', CompanyIdentityObserver::ACTION)->orderBy('id')->get();

        // A tela grava em companies e sincroniza tenants: as duas mudanças ficam registradas.
        $this->assertCount(2, $audits);
        $this->assertSame(['companies', 'tenants'], $audits->pluck('data.source')->all());
        foreach ($audits as $audit) {
            $this->assertSame($this->tenant->id, (int) $audit->tenant_id);
            $this->assertSame($this->tenantAdmin->id, (int) $audit->user_id);
            // assertEquals: a coluna JSON do MySQL não preserva a ordem das chaves.
            $this->assertEquals(['from' => 'Assistência Original', 'to' => 'Assistência Nova Razão'], $audit->data['changes']['legal_name']);
            $this->assertArrayNotHasKey('cnpj', $audit->data['changes']);
        }
    }

    public function test_changes_outside_cnpj_and_legal_name_are_not_audited(): void
    {
        $this->company->update(['telephone' => '1199998888', 'city' => 'Canoas']);
        $this->tenant->update(['phone' => '1199998888', 'status' => 1]);

        $this->assertSame(0, FiscalAdminAudit::query()->where('action', CompanyIdentityObserver::ACTION)->count());
    }

    public function test_tenant_issuer_legal_name_above_the_emitter_limit_blocks_instead_of_truncating(): void
    {
        $setting = new FiscalSetting(['company_tax_regime' => '1']);
        $this->company->forceFill([
            'companyname' => str_repeat('N', SpedyPayloadBuilder::MAX_LEGAL_NAME + 1),
            'street' => 'Rua A', 'number' => '1', 'district' => 'Centro', 'zip_code' => '90000000', 'city' => 'Porto Alegre', 'state' => 'RS',
        ]);

        try {
            app(SpedyPayloadBuilder::class)->company($this->company, $this->tenant, $setting);
            $this->fail('A razão social acima do limite deveria bloquear a emissão.');
        } catch (FiscalValidationException $exception) {
            $this->assertStringContainsString('até 80', implode(' ', $exception->problems));
        }

        $this->company->companyname = str_repeat('N', SpedyPayloadBuilder::MAX_LEGAL_NAME);
        $payload = app(SpedyPayloadBuilder::class)->company($this->company, $this->tenant, $setting);
        $this->assertSame(str_repeat('N', SpedyPayloadBuilder::MAX_LEGAL_NAME), $payload['legalName']);
    }

    public function test_saas_issuer_legal_name_above_the_emitter_limit_is_reported(): void
    {
        $issuer = new AdminFiscalSetting(['legal_name' => str_repeat('A', SpedyPayloadBuilder::MAX_LEGAL_NAME + 1)]);

        $this->assertNotEmpty(array_filter(
            app(SaasInvoiceService::class)->issuerProblems($issuer),
            fn (string $problem) => str_contains($problem, 'razão social do emitente passa de 80'),
        ));
    }

    public function test_root_admin_sees_the_receiver_exactly_as_sent_before_emitting(): void
    {
        $root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_SYSTEM]);
        $longName = str_repeat('Cliente Contratante ', 4);
        $this->tenant->update([
            'company' => $longName,
            'street' => 'Rua das Flores', 'number' => '10', 'district' => 'Centro', 'zip_code' => '90000-000', 'city' => 'Porto Alegre', 'state' => 'rs',
        ]);

        $this->actingAs($root)->get(route('admin.fiscal.saas.index', ['tenant_id' => $this->tenant->id]))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('selectedTenant.receiver.name', rtrim(mb_substr(trim($longName), 0, SpedyPayloadBuilder::MAX_RECEIVER_NAME)))
                ->where('selectedTenant.receiver.full_name', trim($longName))
                ->where('selectedTenant.receiver.name_truncated', true)
                ->where('selectedTenant.receiver.federal_tax_number', '11222333000181')
                ->where('selectedTenant.receiver.address.postalCode', '90000000')
                ->where('selectedTenant.receiver.address.city.state', 'RS')
                ->where('selectedTenant.receiver.identity_changes', ['legal_name'])
                ->whereNot('selectedTenant.receiver.identity_changed_at', null));
    }

    public function test_rollback_refuses_to_shrink_columns_holding_longer_values(): void
    {
        $migration = require database_path('migrations/2026_10_11_100000_widen_company_identity_fields.php');
        DB::table('companies')->where('id', $this->company->id)->update(['companyname' => str_repeat('L', 60)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('companies.companyname');

        $migration->down();
    }

    private function asTenantAdmin(): static
    {
        return $this->actingAs($this->tenantAdmin)->withSession(['tenant_id' => $this->tenant->id]);
    }
}
