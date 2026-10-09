<?php

namespace Tests\Feature\Api;

use App\Models\App\Customer;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/** VETOR-ROOT-FISCAL-02 (Fase A.4): a API dos apps não atende usuários sem empresa. */
class TenantApiAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_without_tenant_cannot_use_the_app_api(): void
    {
        $tenant = Tenant::factory()->create();
        Customer::factory()->create(['tenant_id' => $tenant->id]);

        foreach ([User::ROLE_ROOT_SYSTEM, User::ROLE_ROOT_APP, User::ROLE_ADMIN, User::ROLE_TECHNICIAN] as $role) {
            $user = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => $role]);
            Sanctum::actingAs($user);

            $this->getJson('/api/clientes')->assertForbidden()->assertJsonMissingPath('result');
            $this->getJson('/api/allorder')->assertForbidden();
            $this->getJson('/api/tecnico/agendamentos')->assertForbidden();
        }
    }

    public function test_user_without_tenant_does_not_receive_an_api_token(): void
    {
        $root = User::factory()->create([
            'tenant_id' => null,
            'user_number' => null,
            'roles' => User::ROLE_ROOT_SYSTEM,
            'password' => Hash::make('senha-do-root-123'),
        ]);

        $this->postJson('/api/loginuser', ['email' => $root->email, 'password' => 'senha-do-root-123'])
            ->assertForbidden()
            ->assertJsonMissingPath('access_token');

        $this->assertSame(0, $root->tokens()->count());
    }

    public function test_tenant_user_keeps_using_the_api(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant->id)->create([
            'roles' => User::ROLE_ADMIN,
            'password' => Hash::make('senha-do-admin-123'),
        ]);

        $this->postJson('/api/loginuser', ['email' => $user->email, 'password' => 'senha-do-admin-123'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Sanctum::actingAs($user);
        $this->getJson('/api/clientes')->assertOk()->assertJsonPath('success', true);
    }
}
