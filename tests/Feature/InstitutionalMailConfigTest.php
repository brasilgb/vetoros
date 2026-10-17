<?php

namespace Tests\Feature;

use App\Mail\Concerns\AppliesSystemMailConfig;
use App\Mail\Concerns\AppliesTenantMailConfig;
use App\Mail\MaintenanceInvoiceMail;
use App\Mail\OrderBudgetFollowUpMail;
use App\Mail\OrderCreatedMail;
use App\Mail\OrderFeedbackReminderMail;
use App\Mail\OrderPaymentReminderMail;
use App\Mail\OrderStatusUpdatedMail;
use App\Mail\SaasChargeInvoiceMail;
use App\Mail\SaasFiscalDocumentMail;
use App\Mail\SaasFiscalNoticeMail;
use App\Mail\SubscriptionInvoicePaidMail;
use App\Mail\SubscriptionStatusMail;
use App\Mail\TenantFeedbackRequestMail;
use App\Mail\TenantImprovementRequestCreatedMail;
use App\Mail\TenantImprovementRequestUpdatedMail;
use App\Mail\UserRegisteredMail;
use Tests\TestCase;

/** SMTP institucional da plataforma x SMTP de cada empresa (VETOR-STABILIZE-03). */
class InstitutionalMailConfigTest extends TestCase
{
    private const VARS = [
        'ABRASIL_MAIL_HOST', 'ABRASIL_MAIL_PORT', 'ABRASIL_MAIL_ENCRYPTION', 'ABRASIL_MAIL_USERNAME', 'ABRASIL_MAIL_PASSWORD', 'ABRASIL_MAIL_FROM_ADDRESS',
        'MAIL_HOST', 'MAIL_PORT', 'MAIL_ENCRYPTION', 'MAIL_USERNAME', 'MAIL_PASSWORD', 'MAIL_FROM_ADDRESS', 'MAIL_SCHEME',
    ];

    private array $saved = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::VARS as $name) {
            $this->saved[$name] = [$_SERVER[$name] ?? null, $_ENV[$name] ?? null];
            $this->setEnv($name, null);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->saved as $name => [$server, $env]) {
            $server === null ? $this->setEnv($name, null) : ($_SERVER[$name] = $server);
            if ($env !== null) {
                $_ENV[$name] = $env;
            }
        }

        parent::tearDown();
    }

    public function test_institutional_vars_take_precedence_and_use_ssl_on_465(): void
    {
        $this->setEnv('ABRASIL_MAIL_HOST', 'smtp.hostinger.com');
        $this->setEnv('ABRASIL_MAIL_PORT', '465');
        $this->setEnv('ABRASIL_MAIL_ENCRYPTION', 'ssl');
        $this->setEnv('ABRASIL_MAIL_USERNAME', 'contato@abrasilsistemas.com.br');
        $this->setEnv('ABRASIL_MAIL_PASSWORD', 'segredo-de-teste');
        $this->setEnv('ABRASIL_MAIL_FROM_ADDRESS', 'contato@abrasilsistemas.com.br');
        $this->setEnv('MAIL_HOST', 'mailpit.local');
        $this->setEnv('MAIL_PORT', '1025');

        $mail = $this->mailConfig();

        $this->assertSame('smtp.hostinger.com', $mail['system']['host']);
        $this->assertSame('465', (string) $mail['system']['port']);
        $this->assertSame('smtps', $mail['system']['scheme']);
        $this->assertSame('contato@abrasilsistemas.com.br', $mail['system']['username']);
        $this->assertSame('segredo-de-teste', $mail['system']['password']);
        $this->assertSame('contato@abrasilsistemas.com.br', $mail['system']['from_address']);
        // Recuperação de senha e verificação de e-mail usam o mailer padrão: o mesmo institucional.
        $this->assertSame('smtp.hostinger.com', $mail['mailers']['smtp']['host']);
        $this->assertSame('smtps', $mail['mailers']['smtp']['scheme']);
        $this->assertSame('contato@abrasilsistemas.com.br', $mail['from']['address']);
    }

    public function test_mail_vars_remain_a_fallback_and_empty_counts_as_missing(): void
    {
        $this->setEnv('ABRASIL_MAIL_HOST', '');
        $this->setEnv('ABRASIL_MAIL_PASSWORD', '');
        $this->setEnv('MAIL_HOST', 'mailpit.local');
        $this->setEnv('MAIL_PORT', '587');
        $this->setEnv('MAIL_PASSWORD', 'senha-local');

        $mail = $this->mailConfig();

        $this->assertSame('mailpit.local', $mail['system']['host']);
        $this->assertSame('senha-local', $mail['system']['password']);
        $this->assertNull($mail['system']['scheme']);
    }

    public function test_versioned_example_never_carries_the_institutional_password(): void
    {
        $example = file_get_contents(base_path('.env.example'));

        $this->assertMatchesRegularExpression('/^ABRASIL_MAIL_PASSWORD=$/m', $example);
        $this->assertStringContainsString('ABRASIL_MAIL_HOST=smtp.hostinger.com', $example);
        $this->assertStringContainsString('ABRASIL_MAIL_PORT=465', $example);
    }

    public function test_platform_notices_use_institutional_smtp_and_company_mail_uses_tenant_smtp(): void
    {
        // Cobrança, vencimento/assinatura e documentos fiscais do SaaS: SMTP institucional.
        foreach ([SaasChargeInvoiceMail::class, SaasFiscalNoticeMail::class, SaasFiscalDocumentMail::class, SubscriptionStatusMail::class,
            SubscriptionInvoicePaidMail::class, TenantFeedbackRequestMail::class, UserRegisteredMail::class,
            TenantImprovementRequestCreatedMail::class, TenantImprovementRequestUpdatedMail::class] as $class) {
            $this->assertContains(AppliesSystemMailConfig::class, class_uses_recursive($class), $class);
            $this->assertNotContains(AppliesTenantMailConfig::class, class_uses_recursive($class), $class);
        }

        // Comunicação da empresa com os clientes dela: SMTP próprio da empresa, nunca o institucional.
        foreach ([OrderCreatedMail::class, OrderStatusUpdatedMail::class, OrderBudgetFollowUpMail::class, OrderPaymentReminderMail::class,
            OrderFeedbackReminderMail::class, MaintenanceInvoiceMail::class] as $class) {
            $this->assertContains(AppliesTenantMailConfig::class, class_uses_recursive($class), $class);
            $this->assertNotContains(AppliesSystemMailConfig::class, class_uses_recursive($class), $class);
        }
    }

    private function mailConfig(): array
    {
        return require config_path('mail.php');
    }

    private function setEnv(string $name, ?string $value): void
    {
        if ($value === null) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }

        $_SERVER[$name] = $_ENV[$name] = $value;
        putenv("{$name}={$value}");
    }
}
