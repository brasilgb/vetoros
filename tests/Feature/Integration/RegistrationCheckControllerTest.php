<?php

namespace Tests\Feature\Integration;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

class RegistrationCheckControllerTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'test-crm-token-0123456789';

    private const URL = '/api/integrations/registration-check';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.crm_abrasil.registration_check_token' => self::TOKEN]);
    }

    private function check(array $body, ?string $token = self::TOKEN)
    {
        $headers = ['Accept' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer '.$token;
        }

        return $this->postJson(self::URL, $body, $headers);
    }

    private function tenant(array $attributes, string $createdAt = '2026-09-30 10:30:00'): Tenant
    {
        $tenant = Tenant::factory()->create($attributes);
        $tenant->forceFill(['created_at' => Carbon::parse($createdAt, 'UTC')])->saveQuietly();

        return $tenant;
    }

    public function test_missing_token_is_rejected(): void
    {
        $this->check(['lookup_field' => 'email', 'lookup_value' => 'a@b.com'], null)
            ->assertStatus(401)
            ->assertJsonMissingPath('registered');
    }

    public function test_invalid_token_is_rejected(): void
    {
        $this->check(['lookup_field' => 'email', 'lookup_value' => 'a@b.com'], 'wrong-token')
            ->assertStatus(401);
    }

    public function test_every_request_is_rejected_when_token_is_not_configured(): void
    {
        config(['services.crm_abrasil.registration_check_token' => null]);

        $this->check(['lookup_field' => 'email', 'lookup_value' => 'a@b.com'], '')->assertStatus(401);
    }

    public function test_invalid_lookup_field_returns_422(): void
    {
        $this->check(['lookup_field' => 'cpf', 'lookup_value' => '12345678900'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lookup_field');
    }

    public function test_missing_lookup_value_returns_422(): void
    {
        $this->check(['lookup_field' => 'email'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('lookup_value');
    }

    public function test_malformed_whatsapp_returns_422(): void
    {
        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '12345'])->assertStatus(422);
    }

    public function test_whatsapp_not_found(): void
    {
        $this->tenant(['whatsapp' => '(51) 98888-7777']);

        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5551999999999'])
            ->assertOk()
            ->assertExactJson(['registered' => false]);
    }

    public function test_whatsapp_found_against_masked_storage(): void
    {
        $this->tenant(['whatsapp' => '(51) 99999-9999']);

        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5551999999999'])
            ->assertOk()
            ->assertExactJson(['registered' => true, 'registered_at' => '2026-09-30T10:30:00Z']);
    }

    public function test_whatsapp_matches_number_stored_without_ninth_digit(): void
    {
        $this->tenant(['whatsapp' => '(51) 9999-9999']);

        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5551999999999'])
            ->assertOk()
            ->assertJsonPath('registered', true);
    }

    public function test_whatsapp_with_ddd_55_stored_without_country_code(): void
    {
        $this->tenant(['whatsapp' => '(55) 99123-4567']);

        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5555991234567'])
            ->assertOk()
            ->assertJsonPath('registered', true);
    }

    public function test_whatsapp_same_suffix_different_ddd_is_not_a_match(): void
    {
        $this->tenant(['whatsapp' => '(11) 99999-9999']);

        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5551999999999'])
            ->assertExactJson(['registered' => false]);
    }

    public function test_email_not_found(): void
    {
        $this->tenant(['email' => 'outro@example.com']);

        $this->check(['lookup_field' => 'email', 'lookup_value' => 'cliente@example.com'])
            ->assertOk()
            ->assertExactJson(['registered' => false]);
    }

    public function test_email_found(): void
    {
        $this->tenant(['email' => 'cliente@example.com']);

        $this->check(['lookup_field' => 'email', 'lookup_value' => 'cliente@example.com'])
            ->assertOk()
            ->assertExactJson(['registered' => true, 'registered_at' => '2026-09-30T10:30:00Z']);
    }

    public function test_email_is_trimmed_and_lowercased_on_both_sides(): void
    {
        $this->tenant(['email' => 'Cliente@Example.com']);

        $this->check(['lookup_field' => 'email', 'lookup_value' => '  CLIENTE@example.COM  '])
            ->assertOk()
            ->assertJsonPath('registered', true);
    }

    public function test_registered_is_a_real_boolean_and_registered_at_has_timezone(): void
    {
        $this->tenant(['email' => 'cliente@example.com']);

        $json = $this->check(['lookup_field' => 'email', 'lookup_value' => 'cliente@example.com'])->json();

        $this->assertTrue($json['registered'] === true);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(Z|[+-]\d{2}:\d{2})$/', $json['registered_at']);

        $negative = $this->check(['lookup_field' => 'email', 'lookup_value' => 'x@example.com'])->json();
        $this->assertTrue($negative['registered'] === false);
    }

    public function test_positive_response_contains_no_personal_data(): void
    {
        $tenant = $this->tenant(['email' => 'cliente@example.com', 'whatsapp' => '(51) 99999-9999']);

        $response = $this->check(['lookup_field' => 'email', 'lookup_value' => 'cliente@example.com']);

        $this->assertSame(['registered', 'registered_at'], array_keys($response->json()));
        foreach ([$tenant->name, $tenant->email, $tenant->company, $tenant->cnpj, $tenant->whatsapp] as $pii) {
            $this->assertStringNotContainsString((string) $pii, $response->getContent());
        }
    }

    public function test_endpoint_does_not_write_to_database(): void
    {
        $this->tenant(['email' => 'cliente@example.com', 'whatsapp' => '(51) 99999-9999']);

        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->check(['lookup_field' => 'email', 'lookup_value' => 'cliente@example.com'])->assertOk();
        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5551999999999'])->assertOk();

        $writes = array_filter($queries, fn (string $sql) => ! preg_match('/^\s*select\b/i', $sql)
            && ! str_contains($sql, 'cache'));
        $this->assertSame([], array_values($writes));
    }

    public function test_duplicate_registrations_return_the_earliest(): void
    {
        $this->tenant(['email' => 'cliente@example.com', 'whatsapp' => '(51) 99999-9999'], '2026-09-20 08:00:00');
        $this->tenant(['email' => 'cliente@example.com', 'whatsapp' => '(51) 99999-9999'], '2026-03-01 12:00:00');
        $this->tenant(['email' => 'cliente@example.com', 'whatsapp' => '(51) 99999-9999'], '2026-09-25 09:00:00');

        $this->check(['lookup_field' => 'email', 'lookup_value' => 'cliente@example.com'])
            ->assertJsonPath('registered_at', '2026-03-01T12:00:00Z');
        $this->check(['lookup_field' => 'whatsapp', 'lookup_value' => '5551999999999'])
            ->assertJsonPath('registered_at', '2026-03-01T12:00:00Z');
    }

    public function test_lookup_value_and_token_are_never_logged_on_failure(): void
    {
        Log::spy();
        $default = config('database.default');
        config([
            'database.connections.unavailable' => ['driver' => 'sqlite', 'database' => '/nonexistent/vetoros.sqlite'],
            'database.default' => 'unavailable',
        ]);

        $response = $this->check(['lookup_field' => 'email', 'lookup_value' => 'segredo@example.com']);
        config(['database.default' => $default]);

        $response->assertStatus(503)->assertExactJson(['message' => 'Service unavailable.']);

        Log::shouldHaveReceived('error')->withArgs(function (string $message, array $context) {
            $dump = $message.json_encode($context);

            return ! str_contains($dump, 'segredo@example.com') && ! str_contains($dump, self::TOKEN);
        });
    }

    public function test_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->check(['lookup_field' => 'email', 'lookup_value' => 'a@b.com'])->assertOk();
        }

        $this->check(['lookup_field' => 'email', 'lookup_value' => 'a@b.com'])->assertStatus(429);
    }
}
