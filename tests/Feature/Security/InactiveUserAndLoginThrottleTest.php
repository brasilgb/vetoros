<?php

namespace Tests\Feature\Security;

use App\Models\App\Other;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * VETOROS-SECURITY-FIX-01: usuário inativo não entra nem mantém sessão/token, e o login
 * da API tem limite de tentativas.
 */
class InactiveUserAndLoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('api-login');
        $this->tenant = Tenant::factory()->create();
        Other::factory()->forTenant($this->tenant->id)->create();
    }

    private function user(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'tenant_id' => $this->tenant->id,
            'email' => 'tecnico@example.test',
            'password' => bcrypt('password123'),
        ], $attributes));
    }

    public function test_inactive_user_cannot_login_on_web(): void
    {
        $this->user(['status' => 0]);

        $this->post('/login', ['email' => 'tecnico@example.test', 'password' => 'password123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_get_api_token(): void
    {
        $user = $this->user(['status' => 0]);

        $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'password123'])
            ->assertForbidden()
            ->assertJsonPath('success', false);

        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_active_and_legacy_null_status_users_still_login(): void
    {
        $this->user(['status' => 1]);
        $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'password123'])->assertOk();

        $this->user(['email' => 'legado@example.test', 'status' => null]);
        $this->postJson('/api/loginuser', ['email' => 'legado@example.test', 'password' => 'password123'])->assertOk();
    }

    public function test_existing_web_session_ends_when_user_becomes_inactive(): void
    {
        $user = $this->user(['status' => 1]);
        $this->actingAs($user)->get(route('app.dashboard'))->assertOk();

        $user->forceFill(['status' => 0])->save();

        $this->actingAs($user->fresh())->get(route('app.dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_tokens_are_revoked_and_rejected_when_user_becomes_inactive(): void
    {
        $user = $this->user(['status' => 1]);
        $token = $user->createToken('app')->plainTextToken;

        $this->withToken($token)->getJson('/api/empresa')->assertOk();

        $user->forceFill(['status' => 0])->save();

        $this->assertSame(0, $user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/empresa')->assertUnauthorized();
    }

    public function test_api_token_of_inactive_user_is_rejected_even_if_not_revoked(): void
    {
        $user = $this->user(['status' => 1]);
        $token = $user->createToken('app')->plainTextToken;
        // Inativação feita fora do Eloquent (ex.: SQL manual): o middleware ainda barra.
        User::query()->whereKey($user->id)->toBase()->update(['status' => 0]);

        $this->withToken($token)->getJson('/api/empresa')->assertForbidden();
    }

    public function test_api_login_is_rate_limited(): void
    {
        $this->user(['status' => 1]);

        $statuses = [];
        for ($i = 0; $i < 8; $i++) {
            $statuses[] = $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'errada-'.$i])->status();
        }

        $this->assertContains(429, $statuses);
        $this->assertSame(401, $statuses[0]);

        // Mesmo com a senha certa, continua bloqueado durante a janela.
        $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'password123'])->assertStatus(429);
    }

    public function test_successful_api_login_clears_attempts(): void
    {
        $this->user(['status' => 1]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'errada-'.$i])->assertUnauthorized();
        }

        $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'password123'])->assertOk();

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/loginuser', ['email' => 'tecnico@example.test', 'password' => 'errada-'.$i])->assertUnauthorized();
        }
    }
}
