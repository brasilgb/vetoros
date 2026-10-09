<?php

namespace Tests\Feature\Admin;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** VETOR-ROOT-FISCAL-02 (Fase A): /admin só para RootAdmin e cadastro de usuários sem elevação acidental. */
class RootAdminAccessTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN_ROUTES = [
        'admin.dashboard',
        'admin.reports.index',
        'admin.tenants.index',
        'admin.plans.index',
        'admin.users.index',
        'admin.users.create',
        'admin.settings.index',
        'admin.help-topics.index',
        'admin.tenant-feedbacks.index',
        'admin.tenant-improvement-requests.index',
        'admin.fiscal-documents.index',
    ];

    private User $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->root = User::factory()->create([
            'tenant_id' => null,
            'user_number' => null,
            'roles' => User::ROLE_ROOT_SYSTEM,
            'password' => Hash::make('senha-do-root-123'),
        ]);
    }

    public function test_root_admin_policy_requires_no_tenant_and_a_root_role(): void
    {
        $tenant = Tenant::factory()->create();

        $this->assertTrue($this->root->isRootAdmin());
        $this->assertTrue(User::factory()->make(['tenant_id' => null, 'roles' => User::ROLE_ROOT_APP])->isRootAdmin());
        $this->assertFalse(User::factory()->make(['tenant_id' => $tenant->id, 'roles' => User::ROLE_ROOT_SYSTEM])->isRootAdmin());

        foreach ([User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN, null] as $role) {
            $this->assertFalse(User::factory()->make(['tenant_id' => null, 'roles' => $role])->isRootAdmin());
        }
    }

    public function test_users_without_tenant_and_without_root_role_are_denied_on_every_admin_route(): void
    {
        foreach ([User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN, null] as $role) {
            $user = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => $role]);

            foreach (self::ADMIN_ROUTES as $name) {
                $this->actingAs($user)->get(route($name))->assertForbidden();
            }
            $this->actingAs($user)->getJson(route('admin.dashboard'))
                ->assertForbidden()
                ->assertJson(['message' => 'Acesso restrito ao RootAdmin.']);
            $this->actingAs($user)->get(route('admin.fiscal.integration'))->assertForbidden();
        }
    }

    public function test_tenant_users_are_sent_back_to_their_app_and_cannot_write_on_admin(): void
    {
        $tenant = Tenant::factory()->create();

        foreach ([User::ROLE_ROOT_APP, User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN] as $role) {
            $user = User::factory()->forTenant($tenant->id)->create(['roles' => $role]);

            foreach (self::ADMIN_ROUTES as $name) {
                $this->actingAs($user)->get(route($name))->assertRedirect(config('app.url').'/app');
            }

            $this->actingAs($user)->post(route('admin.users.store'), $this->payload([
                'email' => "escalada{$role}@example.com",
                'roles' => User::ROLE_ROOT_SYSTEM,
            ]))->assertRedirect(config('app.url').'/app');
            $this->assertDatabaseMissing('users', ['email' => "escalada{$role}@example.com"]);
        }
    }

    public function test_guests_are_sent_to_login(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('login'));
        $this->get(route('admin.users.index'))->assertRedirect(route('login'));
    }

    public function test_root_admin_reaches_admin_routes(): void
    {
        foreach (['admin.dashboard', 'admin.users.index', 'admin.users.create', 'admin.tenants.index'] as $name) {
            $this->actingAs($this->root)->get(route($name))->assertOk();
        }
    }

    public function test_non_root_user_requires_a_company(): void
    {
        foreach ([User::ROLE_ROOT_APP, User::ROLE_ADMIN, User::ROLE_OPERATOR, User::ROLE_TECHNICIAN] as $role) {
            $this->actingAs($this->root)
                ->post(route('admin.users.store'), $this->payload(['email' => "sem-empresa{$role}@example.com", 'roles' => $role]))
                ->assertSessionHasErrors('tenant_id');

            $this->assertDatabaseMissing('users', ['email' => "sem-empresa{$role}@example.com"]);
        }
    }

    public function test_unknown_role_is_rejected(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->root)
            ->post(route('admin.users.store'), $this->payload(['roles' => 42, 'tenant_id' => $tenant->id]))
            ->assertSessionHasErrors('roles');

        $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
    }

    public function test_root_system_cannot_belong_to_a_company(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->root)
            ->post(route('admin.users.store'), $this->payload([
                'roles' => User::ROLE_ROOT_SYSTEM,
                'tenant_id' => $tenant->id,
                'admin_password' => 'senha-do-root-123',
            ]))
            ->assertSessionHasErrors('tenant_id');

        $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);
    }

    public function test_granting_root_system_requires_the_root_admin_password(): void
    {
        $this->actingAs($this->root)
            ->post(route('admin.users.store'), $this->payload(['roles' => User::ROLE_ROOT_SYSTEM]))
            ->assertSessionHasErrors('admin_password');

        $this->actingAs($this->root)
            ->post(route('admin.users.store'), $this->payload(['roles' => User::ROLE_ROOT_SYSTEM, 'admin_password' => 'errada-123456']))
            ->assertSessionHasErrors('admin_password');

        $this->assertDatabaseMissing('users', ['email' => 'novo@example.com']);

        $this->actingAs($this->root)
            ->post(route('admin.users.store'), $this->payload(['roles' => User::ROLE_ROOT_SYSTEM, 'admin_password' => 'senha-do-root-123']))
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'novo@example.com')->firstOrFail();
        $this->assertNull($created->tenant_id);
        $this->assertTrue($created->isRootAdmin());
        $this->assertTrue(Hash::check('NovaSenha#2026', $created->password));
    }

    public function test_store_ignores_fields_outside_the_form(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->root)
            ->post(route('admin.users.store'), $this->payload([
                'roles' => User::ROLE_TECHNICIAN,
                'tenant_id' => $tenant->id,
                'can_view_all_orders' => true,
                'commission_percentage' => 50,
                'email_verified_at' => now()->toDateTimeString(),
            ]))
            ->assertSessionHasNoErrors();

        $created = User::query()->where('email', 'novo@example.com')->firstOrFail();
        $this->assertSame($tenant->id, (int) $created->tenant_id);
        $this->assertFalse((bool) $created->can_view_all_orders);
        $this->assertNull($created->commission_percentage);
        $this->assertFalse($created->isRootAdmin());
    }

    public function test_promoting_a_tenant_user_to_root_system_requires_password_and_clears_the_company(): void
    {
        $tenant = Tenant::factory()->create();
        $user = User::factory()->forTenant($tenant->id)->create(['roles' => User::ROLE_ADMIN]);

        $this->actingAs($this->root)
            ->patch(route('admin.users.update', $user), $this->payload(['email' => $user->email, 'roles' => User::ROLE_ROOT_SYSTEM, 'password' => '', 'password_confirmation' => '']))
            ->assertSessionHasErrors('admin_password');
        $this->assertFalse($user->refresh()->isRootAdmin());

        $this->actingAs($this->root)
            ->patch(route('admin.users.update', $user), $this->payload([
                'email' => $user->email,
                'roles' => User::ROLE_ROOT_SYSTEM,
                'password' => '',
                'password_confirmation' => '',
                'admin_password' => 'senha-do-root-123',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertTrue($user->refresh()->isRootAdmin());
    }

    public function test_editing_an_existing_root_system_does_not_ask_the_password_again(): void
    {
        $other = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_SYSTEM]);

        $this->actingAs($this->root)
            ->patch(route('admin.users.update', $other), $this->payload([
                'name' => 'Nome novo',
                'email' => $other->email,
                'roles' => User::ROLE_ROOT_SYSTEM,
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertSessionHasNoErrors();

        $this->assertSame('Nome novo', $other->refresh()->name);
    }

    public function test_root_admin_cannot_remove_own_access_or_delete_itself(): void
    {
        $tenant = Tenant::factory()->create();

        $this->actingAs($this->root)
            ->patch(route('admin.users.update', $this->root), $this->payload([
                'email' => $this->root->email,
                'roles' => User::ROLE_ADMIN,
                'tenant_id' => $tenant->id,
                'password' => '',
                'password_confirmation' => '',
            ]))
            ->assertSessionHasErrors('roles');
        $this->assertTrue($this->root->refresh()->isRootAdmin());

        $this->actingAs($this->root)->delete(route('admin.users.destroy', $this->root))->assertSessionHas('error');
        $this->assertModelExists($this->root);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Novo usuário',
            'email' => 'novo@example.com',
            'telephone' => '',
            'whatsapp' => '',
            'roles' => User::ROLE_ADMIN,
            'tenant_id' => '',
            'status' => true,
            'password' => 'NovaSenha#2026',
            'password_confirmation' => 'NovaSenha#2026',
        ], $overrides);
    }
}
