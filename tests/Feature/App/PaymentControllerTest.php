<?php

namespace Tests\Feature\App;

use App\Models\Admin\Plan;
use App\Models\Admin\Period;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\MercadoPagoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class PaymentControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_generates_pix_and_persists_payment(): void
    {
        config()->set('services.mercadopago.token', 'mp-test-token');
        config()->set('services.mercadopago.webhook_token', 'webhook-secret');

        $plan = Plan::factory()->create([
            'name' => 'Plano Pro',
            'slug' => 'plano-pro',
            'value' => 199.90,
            'billing_months' => 1,
        ]);

        Period::factory()->create([
            'plan_id' => $plan->id,
            'name' => 'Mensal',
            'interval' => 'month',
            'interval_count' => 1,
            'price' => 199.90,
        ]);

        $tenant = Tenant::factory()->create([
            'plan_id' => $plan->id,
            'name' => 'Empresa Teste',
            'email' => 'tenant@example.com',
            'cnpj' => '12345678000199',
        ]);

        $user = User::factory()->forTenant($tenant->id)->create();

        $fakePayment = (object) [
            'id' => 'mp-payment-1',
            'transaction_amount' => 199.90,
            'status' => 'pending',
            'date_of_expiration' => now()->addHour()->toIso8601String(),
            'point_of_interaction' => (object) [
                'transaction_data' => (object) [
                    'qr_code_base64' => 'qr-base64',
                    'qr_code' => 'pix-copy-paste',
                ],
            ],
        ];

        $service = Mockery::mock(MercadoPagoService::class);
        $service->shouldReceive('createPixPayment')
            ->once()
            ->withArgs(function (array $paymentRequest, string $idempotencyKey) use ($plan, $tenant) {
                $this->assertSame((float) $plan->value, $paymentRequest['transaction_amount']);
                $this->assertSame('pix', $paymentRequest['payment_method_id']);
                // E-mail sintético por tenant (ed638c14); não usa o e-mail do cadastro.
                $this->assertSame("pix_{$tenant->id}@vetoros.com.br", $paymentRequest['payer']['email']);
                $this->assertSame('CNPJ', $paymentRequest['payer']['identification']['type']);
                $this->assertSame(preg_replace('/\D/', '', $tenant->cnpj), $paymentRequest['payer']['identification']['number']);
                $this->assertSame(
                    ['tenant_id' => $tenant->id, 'plan_id' => $plan->id],
                    json_decode($paymentRequest['external_reference'], true)
                );
                $this->assertStringContainsString('/api/webhooks/mercadopago/webhook-secret', $paymentRequest['notification_url']);
                $this->assertNotSame('', trim($idempotencyKey));

                return true;
            })
            ->andReturn($fakePayment);

        $this->app->instance(MercadoPagoService::class, $service);

        $response = $this->actingAs($user)->postJson(route('subscription.generate_pix'), [
            'plan_id' => $plan->id,
        ]);

        $response
            ->assertOk()
            ->assertJson([
                'qr_code' => 'qr-base64',
                'qr_code_copy_paste' => 'pix-copy-paste',
                'payment_id' => 'mp-payment-1',
            ]);

        $this->assertDatabaseHas('payments', [
            'tenant_id' => $tenant->id,
            'gateway' => 'mercadopago',
            'payment_id' => 'mp-payment-1',
            'status' => 'pending',
        ]);

        $payment = Payment::query()->where('payment_id', 'mp-payment-1')->firstOrFail();
        $this->assertSame(199.90, (float) $payment->amount);
        $this->assertSame('pending', $payment->status);
        $this->assertSame('mp-payment-1', data_get($payment->raw_response, 'id'));
    }

    public function test_it_rejects_trial_or_free_plan_for_pix_generation(): void
    {
        config()->set('services.mercadopago.webhook_token', 'webhook-secret');

        $plan = Plan::factory()->create([
            'name' => 'Trial Gratis',
            'slug' => 'trial-gratis',
            'value' => 0,
        ]);

        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user = User::factory()->forTenant($tenant->id)->create();

        $service = Mockery::mock(MercadoPagoService::class);
        $service->shouldNotReceive('createPixPayment');
        $this->app->instance(MercadoPagoService::class, $service);

        $response = $this->actingAs($user)->postJson(route('subscription.generate_pix'), [
            'plan_id' => $plan->id,
        ]);

        $response
            ->assertStatus(422)
            ->assertJson([
                'error' => 'Plano inválido para cobrança PIX.',
            ]);
    }

    public function test_it_requires_webhook_token_before_generating_pix(): void
    {
        config()->set('services.mercadopago.webhook_token', null);

        $plan = Plan::factory()->create([
            'name' => 'Plano Mensal',
            'slug' => 'plano-mensal',
            'value' => 89.90,
        ]);

        $tenant = Tenant::factory()->create(['plan_id' => $plan->id]);
        $user = User::factory()->forTenant($tenant->id)->create();

        $service = Mockery::mock(MercadoPagoService::class);
        $service->shouldNotReceive('createPixPayment');
        $this->app->instance(MercadoPagoService::class, $service);

        $response = $this->actingAs($user)->postJson(route('subscription.generate_pix'), [
            'plan_id' => $plan->id,
        ]);

        $response
            ->assertStatus(500)
            ->assertJson([
                'error' => 'Webhook token não configurado.',
            ]);
    }
}
