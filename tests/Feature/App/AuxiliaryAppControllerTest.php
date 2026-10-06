<?php

namespace Tests\Feature\App;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AuxiliaryAppControllerTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->tenant = Tenant::factory()->create();
        $this->app->usePublicPath(storage_path('framework/testing/auxiliary-apps-public'));
        File::deleteDirectory(public_path());
        File::ensureDirectoryExists(public_path('apk'));
    }

    protected function tearDown(): void
    {
        // Se o setUp falhar antes de usePublicPath, public_path() ainda aponta para o public/ real.
        if (str_starts_with(public_path(), storage_path('framework/testing'))) {
            File::deleteDirectory(public_path());
        }

        parent::tearDown();
    }

    public function test_operator_can_view_auxiliary_apps_and_available_files(): void
    {
        $operator = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_OPERATOR,
        ]);
        File::put(public_path('apk/vetor-imagem.apk'), 'apk-content');

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($operator)
            ->get(route('app.auxiliary-apps.index'))
            ->assertOk()
            ->assertViewHas('page.props.apps.0.name', 'Vetor Imagem')
            ->assertViewHas('page.props.apps.0.filename', 'vetor-imagem.apk')
            ->assertViewHas('page.props.apps.0.available', true)
            ->assertViewHas('page.props.apps.1.filename', 'vetor-atendimento.apk')
            ->assertViewHas('page.props.apps.1.available', false)
            ->assertViewHas('page.props.apps.2.filename', 'vetor-tecnico.apk');
    }

    public function test_technician_cannot_access_auxiliary_apps_settings_page(): void
    {
        $technician = User::factory()->forTenant($this->tenant->id)->create([
            'roles' => User::ROLE_TECHNICIAN,
        ]);

        $this->withSession(['tenant_id' => $this->tenant->id])
            ->actingAs($technician)
            ->get(route('app.auxiliary-apps.index'))
            ->assertRedirect();
    }
}
