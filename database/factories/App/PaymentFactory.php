<?php

namespace Database\Factories\App;

use App\Models\App\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        $status = $this->faker->randomElement(['pending', 'paid', 'cancelled']);
        $createdAt = $this->faker->dateTimeBetween('-60 days', 'now');

        return [
            'gateway' => 'efi',
            'method' => 'pix',
            'payment_id' => 'pay_'.Str::upper(Str::random(18)),
            'amount' => $this->faker->randomFloat(2, 99, 1999),
            'status' => $status,
            'idempotency_key' => 'idem_'.Str::uuid(),
            'expires_at' => $status === 'pending' ? $this->faker->dateTimeBetween($createdAt, 'now') : null,
            'raw_response' => [
                'status' => $status,
                'provider' => 'efi',
            ],
            'created_at' => $createdAt,
            'updated_at' => $this->faker->dateTimeBetween($createdAt, 'now'),
        ];
    }

    public function forTenant(int $tenantId): static
    {
        return $this->state(fn () => ['tenant_id' => $tenantId]);
    }
}
