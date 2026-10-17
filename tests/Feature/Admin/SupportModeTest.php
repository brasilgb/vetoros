<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\SupportSession;
use App\Models\Admin\SupportSessionAction;
use App\Models\App\CashSession;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use App\Services\SupportSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Modo Suporte: RootAdmin no painel de uma empresa, com a própria identidade (VETOR-STABILIZE-03). */
class SupportModeTest extends TestCase
{
    use RefreshDatabase;

    private User $root;

    private Tenant $tenantA;

    private Tenant $tenantB;

    private User $adminA;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();

        $this->tenantA = Tenant::factory()->create(['company' => 'Assistência Alfa', 'expires_at' => now()->addDays(30)]);
        $this->tenantB = Tenant::factory()->create(['company' => 'Assistência Beta', 'expires_at' => now()->addDays(30)]);
        $this->root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_APP, 'password' => Hash::make('senha-do-root')]);
        $this->adminA = User::factory()->forTenant($this->tenantA->id)->create(['roles' => User::ROLE_ADMIN, 'password' => Hash::make('senha-do-cliente')]);

        foreach ([$this->tenantA, $this->tenantB] as $tenant) {
            Other::factory()->forTenant($tenant->id)->create();
        }

        Customer::factory()->forTenant($this->tenantA->id)->create(['name' => 'Cliente da Alfa']);
        Customer::factory()->forTenant($this->tenantB->id)->create(['name' => 'Cliente da Beta']);
    }

    private function asRoot(): static
    {
        // Instância nova por requisição, como em produção (a da requisição anterior pode ter o tenant do suporte em memória).
        return $this->actingAs($this->root->fresh());
    }

    private function startSupport(?Tenant $tenant = null)
    {
        return $this->asRoot()->post(route('admin.tenants.support.start', $tenant ?? $this->tenantA), [
            'reason' => 'Cliente relatou erro ao salvar a OS 123',
            'password' => 'senha-do-root',
        ]);
    }

    private function activeSession(): SupportSession
    {
        return SupportSession::query()->sole();
    }

    public function test_root_enters_the_selected_tenant_without_touching_client_credentials(): void
    {
        $passwordBefore = $this->adminA->fresh()->password;

        $this->startSupport()->assertRedirect(route('app.dashboard'));

        $session = $this->activeSession();
        $this->assertSame($this->root->id, (int) $session->root_user_id);
        $this->assertSame($this->tenantA->id, (int) $session->tenant_id);
        $this->assertSame('Cliente relatou erro ao salvar a OS 123', $session->reason);
        $this->assertNotNull($session->ip_address);
        $this->assertNull($session->ended_at);

        $this->asRoot()->get(route('app.dashboard'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('support.tenant', 'Assistência Alfa')->where('auth.user.id', $this->root->id));

        // Senha e usuário do cliente intactos; o root continua sem empresa no banco.
        $this->assertSame($passwordBefore, $this->adminA->fresh()->password);
        $this->assertNull($this->root->fresh()->tenant_id);
    }

    public function test_reauthentication_and_reason_are_required(): void
    {
        $this->asRoot()->post(route('admin.tenants.support.start', $this->tenantA), ['reason' => 'Verificar erro relatado', 'password' => 'errada'])
            ->assertSessionHasErrors('password');
        $this->asRoot()->post(route('admin.tenants.support.start', $this->tenantA), ['reason' => 'curto', 'password' => 'senha-do-root'])
            ->assertSessionHasErrors('reason');

        $this->assertSame(0, SupportSession::query()->count());
    }

    public function test_common_user_cannot_use_support_mode(): void
    {
        $this->actingAs($this->adminA)->post(route('admin.tenants.support.start', $this->tenantB), [
            'reason' => 'Tentativa de usuário comum da empresa',
            'password' => 'senha-do-cliente',
        ])->assertRedirect();

        $this->assertSame(0, SupportSession::query()->count());
        // O usuário comum continua vendo só a própria empresa.
        $this->actingAs($this->adminA)->getJson(route('app.customers.search', ['q' => 'Cliente']))
            ->assertOk()->assertJsonMissing(['name' => 'Cliente da Beta']);
    }

    public function test_session_is_limited_to_the_chosen_tenant_and_cross_access_is_refused(): void
    {
        $this->startSupport();
        $beta = Customer::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantB->id)->sole();

        $this->asRoot()->getJson(route('app.customers.search', ['q' => 'Cliente']))
            ->assertOk()->assertJsonFragment(['name' => 'Cliente da Alfa'])->assertJsonMissing(['name' => 'Cliente da Beta']);

        // Registro de outra empresa pela URL: não é encontrado.
        $this->asRoot()->get(route('app.customers.edit', $beta))->assertNotFound()->assertDontSee('Cliente da Beta');
        // Parâmetro de URL não troca a empresa.
        $this->asRoot()->getJson(route('app.customers.search', ['q' => 'Cliente', 'tenant_id' => $this->tenantB->id]))
            ->assertJsonMissing(['name' => 'Cliente da Beta']);

        // Painel do RootAdmin inacessível durante o suporte e sem sessão encadeada.
        $this->asRoot()->get(route('admin.tenants.index'))->assertRedirect(config('app.url').'/app');
        $this->startSupport($this->tenantB)->assertRedirect();
        $this->assertSame(1, SupportSession::query()->count());
        $this->assertSame($this->tenantA->id, (int) $this->activeSession()->tenant_id);
    }

    public function test_ending_support_returns_to_rootadmin_and_closes_access(): void
    {
        $this->startSupport();

        $this->asRoot()->post(route('support.end'))->assertRedirect(route('admin.tenants.index'))->assertSessionHas('success', 'Suporte encerrado.');

        $session = $this->activeSession();
        $this->assertNotNull($session->ended_at);
        $this->assertSame(SupportSession::END_MANUAL, $session->end_reason);
        $this->assertFalse(session()->has(SupportSessionService::SESSION_KEY));
        $this->asRoot()->get(route('app.dashboard'))->assertRedirect(config('app.url').'/admin');
    }

    public function test_expired_session_loses_access(): void
    {
        $this->startSupport();

        $this->travel(config('support.ttl_minutes') + 1)->minutes();

        $this->asRoot()->get(route('app.dashboard'))->assertRedirect(route('admin.tenants.index'))->assertSessionHas('error');
        $this->assertSame(SupportSession::END_EXPIRED, $this->activeSession()->end_reason);
        $this->asRoot()->get(route('app.dashboard'))->assertRedirect(config('app.url').'/admin');
    }

    public function test_allowed_correction_is_recorded_with_the_real_administrator(): void
    {
        $this->startSupport();

        $this->asRoot()->post(route('app.register-equipments.store'), ['equipment' => 'Leitor de cartão (ajuste do suporte)'])->assertRedirect();

        $equipment = Equipment::query()->withoutGlobalScopes()->where('equipment', 'Leitor de cartão (ajuste do suporte)')->sole();
        $this->assertSame($this->tenantA->id, (int) $equipment->tenant_id);

        $action = SupportSessionAction::query()->sole();
        $this->assertSame('POST', $action->method);
        $this->assertSame('app.register-equipments.store', $action->route_name);
        $this->assertFalse($action->blocked);
        $this->assertSame($this->root->id, (int) $action->session->root_user_id);
        $this->assertNull($this->root->fresh()->tenant_id);
    }

    public function test_critical_operations_are_blocked_and_audited(): void
    {
        $this->startSupport();
        $alfa = Customer::query()->withoutGlobalScopes()->where('tenant_id', $this->tenantA->id)->sole();
        $usersBefore = User::query()->withoutGlobalScopes()->count();

        // Financeiro (abrir caixa), exclusão, usuários e pagamento da assinatura.
        $this->asRoot()->post(route('app.cashier.open'), ['opening_balance' => '100.00'])->assertSessionHas('error');
        $this->asRoot()->delete(route('app.customers.destroy', $alfa))->assertSessionHas('error');
        $this->asRoot()->post(route('app.users.store'), ['name' => 'Novo', 'email' => 'novo@alfa.test', 'password' => 'x', 'roles' => 1])->assertSessionHas('error');
        $this->asRoot()->postJson(route('subscription.efi.pix'))->assertForbidden();

        $this->assertSame(0, CashSession::query()->withoutGlobalScopes()->count());
        $this->assertNotNull(Customer::query()->withoutGlobalScopes()->find($alfa->id));
        $this->assertSame($usersBefore, User::query()->withoutGlobalScopes()->count());
        $this->assertSame(4, SupportSessionAction::query()->where('blocked', true)->count());
    }

    public function test_logout_ends_the_support_session(): void
    {
        $this->startSupport();

        $this->asRoot()->post(route('logout'))->assertRedirect();

        $this->assertSame(SupportSession::END_LOGOUT, $this->activeSession()->end_reason);
    }

    public function test_support_reaches_blocked_tenants_but_their_users_stay_blocked(): void
    {
        $this->tenantA->forceFill(['expires_at' => now()->subDays(10)])->save();

        $this->actingAs($this->adminA->fresh())->get(route('app.dashboard'))->assertRedirect(route('subscription.blocked'));

        $this->startSupport();
        $this->asRoot()->get(route('app.dashboard'))->assertOk();
    }
}
