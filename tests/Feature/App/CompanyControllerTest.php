<?php

namespace Tests\Feature\App;

use App\Models\App\Company;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class CompanyControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    private Company $company;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->tenant = Tenant::factory()->create();
        $this->actingAs(User::factory()->forTenant($this->tenant->id)->create())
            ->withSession(['tenant_id' => $this->tenant->id]);
        $this->company = Company::query()->create(['tenant_id' => $this->tenant->id]);
        $this->app->usePublicPath(storage_path('framework/testing/company-public'));
        File::ensureDirectoryExists(public_path('storage/logos'));
    }

    protected function tearDown(): void
    {
        // Se o setUp falhar antes de usePublicPath, public_path() ainda aponta para o public/ real.
        if (str_starts_with(public_path(), storage_path('framework/testing'))) {
            File::deleteDirectory(public_path());
        }
        parent::tearDown();
    }

    public function test_it_saves_company_fields_and_maps_only_supported_tenant_fields(): void
    {
        $this->put(route('app.company.update', $this->company), [
            'shortname' => 'Oficina',
            'companyname' => 'Oficina Exemplo',
            'telephone' => '1133334444',
            'site' => 'https://example.com',
            'number' => 123,
            'email' => 'empresa@example.com',
        ])->assertRedirect(route('app.company.index'))->assertSessionHas('success');

        $this->assertDatabaseHas('companies', [
            'id' => $this->company->id,
            'shortname' => 'Oficina',
            'site' => 'https://example.com',
            'number' => '123',
        ]);
        $this->assertDatabaseHas('tenants', [
            'id' => $this->tenant->id,
            'company' => 'Oficina Exemplo',
            'phone' => '1133334444',
            'email' => 'empresa@example.com',
        ]);
    }

    public function test_it_replaces_the_logo_and_preserves_required_tenant_fields(): void
    {
        File::put(public_path('storage/logos/old.png'), 'old-logo');
        $this->company->update(['logo' => 'old.png']);

        $this->post(route('app.company.update', $this->company), [
            '_method' => 'put',
            'logo' => UploadedFile::fake()->image('logo.png'),
            'email' => null,
            'cnpj' => null,
        ])->assertRedirect(route('app.company.index'))->assertSessionHasNoErrors();

        $logo = $this->company->fresh()->logo;
        $this->assertNotSame('old.png', $logo);
        $this->assertFileExists(public_path('storage/logos/'.$logo));
        $this->assertFileDoesNotExist(public_path('storage/logos/old.png'));
        $this->assertSame($this->tenant->email, $this->tenant->fresh()->email);
        $this->assertSame($this->tenant->cnpj, $this->tenant->fresh()->cnpj);

        $this->put(route('app.company.update', $this->company), [
            'shortname' => 'Novo nome',
            'logo' => null,
        ])->assertSessionHasNoErrors();
        $this->assertSame($logo, $this->company->fresh()->logo);
        $this->assertFileExists(public_path('storage/logos/'.$logo));
    }

    public function test_invalid_data_does_not_change_company_or_logo(): void
    {
        $this->company->update(['shortname' => 'Original', 'logo' => 'old.png']);
        File::put(public_path('storage/logos/old.png'), 'old-logo');

        $this->put(route('app.company.update', $this->company), [
            'shortname' => str_repeat('x', 151),
            'email' => 'invalid-email',
        ])->assertSessionHasErrors(['shortname', 'email']);

        $this->assertSame('Original', $this->company->fresh()->shortname);
        $this->assertFileExists(public_path('storage/logos/old.png'));
    }
}
