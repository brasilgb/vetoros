<?php

namespace Tests\Feature\Admin;

use App\Models\Admin\SpedyPlatformSetting;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Support\PublicUrl;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/** VETOR-ROOT-FISCAL-02 (Fase C): HTTPS exigido pelo host, sem depender de APP_ENV. */
class PublicUrlSchemeTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_local_development_hosts_may_use_http(): void
    {
        foreach (['https://vetoros.com.br/api/webhooks/spedy', 'http://localhost/api', 'http://127.0.0.1:8000/x', 'http://vetoros.localhost/x', 'http://vetoros.test/x'] as $url) {
            $this->assertTrue(PublicUrl::isSecureOrLocal($url), $url);
        }

        foreach (['http://vetoros.com.br/api/webhooks/spedy', 'http://203.0.113.10/api', 'http://localhost.vetoros.com.br/x'] as $url) {
            $this->assertFalse(PublicUrl::isSecureOrLocal($url), $url);
        }
    }

    public function test_webhook_over_http_on_a_public_host_is_refused_even_with_app_env_local(): void
    {
        // Produção hoje roda com APP_ENV=local; a recusa não pode depender dele.
        $this->app['env'] = 'local';
        $this->withoutMiddleware(ValidateCsrfToken::class);
        Http::preventStrayRequests();
        config(['services.spedy.environment' => 'sandbox', 'services.spedy.owner_api_key' => 'env-owner-key']);
        URL::forceRootUrl('http://vetoros.com.br');
        $root = User::factory()->create(['tenant_id' => null, 'user_number' => null, 'roles' => User::ROLE_ROOT_SYSTEM]);

        $this->actingAs($root)
            ->post(route('admin.fiscal.integration.webhook'), ['password' => 'password'])
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'precisa ser HTTPS'));

        Http::assertNothingSent();
        $this->assertNull(SpedyPlatformSetting::current()->webhook_url);
    }

    public function test_https_app_url_forces_https_on_generated_urls(): void
    {
        config(['app.url' => 'https://vetoros.com.br']);
        URL::forceRootUrl('http://vetoros.com.br');

        (new AppServiceProvider($this->app))->boot();

        $this->assertStringStartsWith('https://', route('webhook.spedy'));
    }
}
