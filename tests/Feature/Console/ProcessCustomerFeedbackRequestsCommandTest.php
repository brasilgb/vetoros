<?php

namespace Tests\Feature\Console;

use App\Mail\OrderFeedbackReminderMail;
use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\Equipment;
use App\Models\App\Order;
use App\Models\App\Other;
use App\Models\Tenant;
use App\Services\OrderNotificationService;
use App\Support\OrderStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProcessCustomerFeedbackRequestsCommandTest extends TestCase
{
    use RefreshDatabase;

    private function createTenantWithMailSettings(int $delayDays = 7): Tenant
    {
        $tenant = Tenant::factory()->create();

        Other::query()->create([
            'tenant_id' => $tenant->id,
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.example.com',
            'mail_port' => 587,
            'mail_username' => 'user@example.com',
            'mail_password' => Crypt::encryptString('secret'),
            'mail_encryption' => 'tls',
            'mail_from_address' => 'noreply@example.com',
            'mail_from_name' => 'VetorOS',
            'customer_feedback_request_delay_days' => $delayDays,
        ]);

        return $tenant;
    }

    private function createDeliveredOrder(Tenant $tenant, int $daysAgo): Order
    {
        $customer = Customer::factory()->forTenant($tenant->id)->create([
            'email' => 'cliente@example.com',
        ]);
        $equipment = Equipment::factory()->forTenant($tenant->id)->create();

        return Order::factory()->forTenant($tenant->id)->create([
            'customer_id' => $customer->id,
            'equipment_id' => $equipment->id,
            'service_status' => OrderStatus::DELIVERED,
            'delivery_date' => now()->subDays($daysAgo),
            'customer_feedback_submitted_at' => null,
            'customer_feedback_reminder_sent_at' => null,
            'customer_feedback_request_expired_at' => null,
        ]);
    }

    public function test_it_sends_one_feedback_reminder_after_configured_delay(): void
    {
        Queue::fake();
        Mail::fake();

        $tenant = $this->createTenantWithMailSettings();
        $order = $this->createDeliveredOrder($tenant, 7);

        $this->artisan('vetoros:process-customer-feedback-requests')
            ->expectsOutputToContain('Processadas: 1 | Lembretes: 1 | Expiradas: 0 | Ignoradas: 0')
            ->assertExitCode(0);

        Mail::assertSent(OrderFeedbackReminderMail::class, 1);
        $this->assertNotNull($order->fresh()->customer_feedback_reminder_sent_at);

        $this->artisan('vetoros:process-customer-feedback-requests')
            ->expectsOutputToContain('Processadas: 1 | Lembretes: 0 | Expiradas: 0 | Ignoradas: 1')
            ->assertExitCode(0);

        Mail::assertSent(OrderFeedbackReminderMail::class, 1);
    }

    public function test_it_expires_unanswered_request_after_seven_more_days(): void
    {
        Queue::fake();

        $tenant = $this->createTenantWithMailSettings();
        $order = $this->createDeliveredOrder($tenant, 14);

        $this->artisan('vetoros:process-customer-feedback-requests')
            ->expectsOutputToContain('Processadas: 1 | Lembretes: 0 | Expiradas: 1 | Ignoradas: 0')
            ->assertExitCode(0);

        Queue::assertNothingPushed();
        $this->assertNotNull($order->fresh()->customer_feedback_request_expired_at);
    }

    public function test_feedback_reminder_job_delivers_mail(): void
    {
        Mail::fake();

        $tenant = $this->createTenantWithMailSettings();
        Company::factory()->forTenant($tenant->id)->create([
            'shortname' => 'Assistência Anderson',
            'companyname' => 'Assistência Anderson LTDA',
            'logo' => null,
        ]);
        $order = $this->createDeliveredOrder($tenant, 7);

        app(OrderNotificationService::class)->deliverFeedbackReminder($order->id);

        Mail::assertSent(OrderFeedbackReminderMail::class, function (OrderFeedbackReminderMail $mail) use ($order) {
            return $mail->order->is($order)
                && $mail->companyName === 'Assistência Anderson'
                && $mail->companyLogoUrl === null;
        });
    }
}
