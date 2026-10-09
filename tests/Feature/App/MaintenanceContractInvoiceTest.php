<?php

namespace Tests\Feature\App;

use App\Jobs\EmitMaintenanceContractInvoice;
use App\Mail\FiscalDocumentMail;
use App\Models\App\AccountReceivable;
use App\Models\App\AccountReceivablePayment;
use App\Models\App\CashSession;
use App\Models\App\CashSessionMovement;
use App\Models\App\Company;
use App\Models\App\Customer;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalDocumentDelivery;
use App\Models\App\FiscalSetting;
use App\Models\App\MaintenanceContract;
use App\Models\App\MaintenanceContractLog;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountReceivablePaymentService;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\MaintenanceContractService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/** VETOR-FISCAL-05: NFS-e automática dos contratos de manutenção após a quitação da cobrança. */
class MaintenanceContractInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private const SANDBOX = 'https://sandbox-api.spedy.com.br/v1';

    private Tenant $tenant;

    private User $user;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('fiscal');
        $this->withoutVite();
        config([
            'services.spedy.environment' => 'sandbox',
            'services.spedy.owner_api_key' => 'owner-key-test',
            'services.spedy.webhook_secret' => null,
            'services.spedy.technical_responsible' => [],
        ]);

        $this->tenant = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $this->user = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->prepareTenant($this->tenant, 'company-key-a');
        $this->customer = Customer::factory()->forTenant($this->tenant->id)->create([
            'name' => 'Condomínio Jardim',
            'cpfcnpj' => '11.444.777/0001-61',
            'email' => 'financeiro@condominio.test',
        ]);
        $this->openCash($this->tenant, $this->user);

        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id]);
    }

    // ------------------------------------------------------------------ configuração

    public function test_new_contracts_start_with_both_options_disabled(): void
    {
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload())->assertSessionHasNoErrors();

        $contract = MaintenanceContract::query()->sole();
        $this->assertFalse($contract->auto_issue_invoice);
        $this->assertFalse($contract->auto_send_invoice);
    }

    public function test_activation_is_refused_without_valid_fiscal_configuration(): void
    {
        FiscalSetting::query()->where('tenant_id', $this->tenant->id)->update(['default_iss_rate' => null]);

        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['auto_issue_invoice' => true]))
            ->assertSessionHasErrors('auto_issue_invoice');
        $this->assertSame(0, MaintenanceContract::query()->count());

        FiscalSetting::query()->where('tenant_id', $this->tenant->id)->update(['default_iss_rate' => 2.5, 'nfse_allowed' => false]);
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['auto_issue_invoice' => true]))
            ->assertSessionHasErrors('auto_issue_invoice');
    }

    public function test_automatic_sending_requires_automatic_emission(): void
    {
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['auto_send_invoice' => true]))->assertSessionHasNoErrors();

        $this->assertFalse(MaintenanceContract::query()->sole()->auto_send_invoice);
    }

    public function test_contract_cannot_use_a_customer_of_another_tenant(): void
    {
        $other = Tenant::factory()->create();
        $foreign = Customer::factory()->forTenant($other->id)->create();

        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['customer_id' => $foreign->id]))
            ->assertSessionHasErrors('customer_id');
    }

    // ------------------------------------------------------------------ gatilho financeiro

    public function test_disabled_contract_does_not_emit_after_full_payment(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);

        $this->pay($contract, $charge, 350)->assertSessionHasNoErrors();

        $this->assertSame(AccountReceivable::STATUS_PAID, $charge->refresh()->status);
        $this->assertSame(0, FiscalDocument::query()->count());
        Http::assertNothingSent();
    }

    public function test_unpaid_and_overdue_charges_never_emit(): void
    {
        Queue::fake();
        [$contract, $charge] = $this->contractWithCharge(issue: true);

        $this->travel(40)->days();
        $this->artisan('vetoros:process-maintenance-contracts')->assertSuccessful();

        Queue::assertNotPushed(EmitMaintenanceContractInvoice::class);

        // Mesmo chamado direto, o job recusa cobrança não quitada.
        (new EmitMaintenanceContractInvoice($charge->id))->handle(app(NativeFiscalService::class));
        $this->assertSame(0, FiscalDocument::query()->count());
        $this->assertTrue(MaintenanceContractLog::query()->where('action', 'invoice_skipped')->exists());
        Http::assertNothingSent();
    }

    public function test_full_payment_emits_nfse_with_contract_data_and_sends_it_to_the_customer(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true, send: true);

        $this->pay($contract, $charge, 350)
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'NFS-e foi enviada para emissão'));

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->status);
        $this->assertSame(AccountReceivable::class, $document->documentable_type);
        $this->assertSame($charge->id, (int) $document->documentable_id);
        $this->assertSame((int) $this->tenant->id, (int) $document->tenant_id);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices'
            && $request->hasHeader('X-Api-Key', 'company-key-a')
            && $request['total']['invoiceAmount'] === 350.0
            && $request['receiver']['federalTaxNumber'] === '11444777000161'
            && $request['receiver']['name'] === 'Condomínio Jardim'
            && $request['sendEmailToCustomer'] === false
            && str_contains($request['description'], 'Contrato de manutenção nº '.$contract->contract_number));

        Mail::assertSent(FiscalDocumentMail::class, fn (FiscalDocumentMail $mail) => $mail->hasTo('financeiro@condominio.test') && count($mail->attachments()) === 2);
        $delivery = FiscalDocumentDelivery::query()->sole();
        $this->assertSame(FiscalDocumentDelivery::STATUS_SENT, $delivery->status);
        $this->assertSame(FiscalDocumentDelivery::ORIGIN_AUTOMATIC, $delivery->origin);

        $movement = CashSessionMovement::query()->sole();
        $this->assertSame(AccountReceivablePayment::query()->sole()->id, (int) $movement->source_id);
        $this->assertSame(350.0, (float) $movement->amount);

        foreach (['payment_registered', 'invoice_queued', 'invoice_authorized'] as $action) {
            $this->assertTrue(MaintenanceContractLog::query()->where('maintenance_contract_id', $contract->id)->where('action', $action)->exists(), $action);
        }
    }

    public function test_partial_payment_waits_for_full_settlement(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);

        $this->pay($contract, $charge, 100)->assertSessionHasNoErrors();
        $this->assertSame(AccountReceivable::STATUS_PARTIAL, $charge->refresh()->status);
        $this->assertSame(250.0, (float) $charge->balance_amount);
        Http::assertNothingSent();

        $this->pay($contract, $charge, 250)->assertSessionHasNoErrors();
        $this->assertSame(AccountReceivable::STATUS_PAID, $charge->refresh()->status);
        $this->assertSame(1, FiscalDocument::query()->count());
    }

    public function test_manual_payment_requires_permission_open_cash_and_valid_amount(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);

        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        // 403 web vira redirecionamento com aviso de ação não autorizada (tratamento padrão do app).
        $this->actingAs($technician)->post($this->payRoute($contract, $charge), $this->paymentPayload(350))
            ->assertRedirect()
            ->assertSessionHas('authorization_error');

        $this->actingAs($this->user);
        $this->pay($contract, $charge, 400)->assertSessionHasErrors('amount');
        $this->pay($contract, $charge, 0)->assertSessionHasErrors('amount');
        $this->post($this->payRoute($contract, $charge), [...$this->paymentPayload(10), 'paid_at' => now()->addDay()->toDateTimeString()])
            ->assertSessionHasErrors('paid_at');

        CashSession::query()->update(['status' => 'closed', 'closed_at' => now()]);
        $this->pay($contract, $charge, 350)->assertSessionHasErrors('amount');

        $this->assertSame(0, AccountReceivablePayment::query()->count());
        $this->assertSame(AccountReceivable::STATUS_PENDING, $charge->refresh()->status);
    }

    public function test_duplicate_integrated_payment_event_is_registered_once(): void
    {
        $this->fakeSpedy('authorized');
        [, $charge] = $this->contractWithCharge(issue: true);
        $service = app(AccountReceivablePaymentService::class);
        $event = ['amount' => 350, 'paid_at' => now()->toDateTimeString(), 'payment_method' => 'pix'];

        $first = $service->register($charge, $event, null, AccountReceivablePayment::SOURCE_INTEGRATION, 'evt-123');
        $second = $service->register($charge, $event, null, AccountReceivablePayment::SOURCE_INTEGRATION, 'evt-123');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AccountReceivablePayment::query()->count());
        $this->assertSame(350.0, (float) $charge->refresh()->paid_amount);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(0, CashSessionMovement::query()->count());
    }

    public function test_same_confirmation_submitted_twice_registers_the_payment_once(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);
        $key = (string) Str::uuid();

        $this->post($this->payRoute($contract, $charge), $this->paymentPayload(100, $key))->assertSessionHasNoErrors();
        $this->post($this->payRoute($contract, $charge), $this->paymentPayload(100, $key))
            ->assertSessionHas('success', 'Este pagamento já estava registrado; nada foi alterado.');

        $this->assertSame(1, AccountReceivablePayment::query()->count());
        $this->assertSame(100.0, (float) $charge->refresh()->paid_amount);
        $this->assertSame(1, CashSessionMovement::query()->count());

        $this->post($this->payRoute($contract, $charge), [...$this->paymentPayload(10), 'request_key' => 'nao-e-uuid'])
            ->assertSessionHasErrors('request_key');
    }

    public function test_spedy_failure_never_reverts_the_confirmed_payment(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response(['message' => 'Dados inválidos'], 422)]);
        [$contract, $charge] = $this->contractWithCharge(issue: true);

        $this->pay($contract, $charge, 350)->assertSessionHasNoErrors();

        $this->assertSame(AccountReceivable::STATUS_PAID, $charge->refresh()->status);
        $this->assertSame(1, AccountReceivablePayment::query()->count());
        $this->assertNull(AccountReceivablePayment::query()->sole()->reversed_at);
        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);
        $this->assertTrue(MaintenanceContractLog::query()->where('action', 'invoice_failed')->exists());
    }

    public function test_manual_emission_before_payment_is_allowed_and_payment_does_not_duplicate_it(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);

        $this->post(route('app.maintenance-contracts.charges.invoice', [$contract, $charge]))->assertSessionHas('success', 'NFS-e autorizada.');
        $this->assertSame(AccountReceivable::STATUS_PENDING, $charge->refresh()->status);

        $this->pay($contract, $charge, 350)->assertSessionHasNoErrors();

        $this->assertSame(AccountReceivable::STATUS_PAID, $charge->refresh()->status);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertCount(1, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST'));
        $this->assertFalse(MaintenanceContractLog::query()->where('action', 'invoice_failed')->exists());
    }

    // ------------------------------------------------------------------ Spedy

    public function test_concurrent_and_repeated_jobs_never_create_a_second_invoice(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $this->settle($charge);

        // Job do pagamento já rodou (sync). Novas execuções encontram a nota em processamento.
        foreach (range(1, 3) as $_) {
            dispatch_sync(new EmitMaintenanceContractInvoice($charge->id));
        }

        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, FiscalDocument::query()->sole()->status);
        Http::assertSentCount(1);
        $this->assertFalse(MaintenanceContractLog::query()->where('maintenance_contract_id', $contract->id)->where('action', 'invoice_failed')->exists());
    }

    public function test_rejection_is_recorded_and_reprocessing_reuses_the_same_invoice(): void
    {
        Http::fakeSequence(self::SANDBOX.'/service-invoices')
            ->push($this->invoice('nfse-1', 'rejected', ['processingDetail' => ['status' => 'success', 'code' => 'E01', 'message' => 'Código de serviço inválido']]))
            ->push($this->invoice('nfse-1', 'enqueued'));
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $this->settle($charge);

        $first = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_REJECTED, $first->status);
        $this->assertTrue(MaintenanceContractLog::query()->where('action', 'invoice_rejected')->exists());

        $this->post(route('app.maintenance-contracts.charges.invoice', [$contract, $charge]))->assertSessionHas('success');

        $second = FiscalDocument::query()->sole();
        $this->assertSame($first->id, $second->id);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $second->status);

        $ids = [];
        Http::assertSent(function (HttpRequest $request) use (&$ids) {
            $ids[] = $request['integrationId'];

            return true;
        });
        $this->assertCount(2, $ids);
        $this->assertCount(1, array_unique($ids));

        // Em processamento, novo pedido de emissão é recusado.
        $this->post(route('app.maintenance-contracts.charges.invoice', [$contract, $charge]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'em processamento'));
        Http::assertSentCount(2);
    }

    public function test_spedy_unavailability_keeps_invoice_processing_without_duplicates(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response(['message' => 'indisponível'], 503)]);
        [, $charge] = $this->contractWithCharge(issue: true);
        $this->settle($charge);

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->status);
        $this->assertNull($document->provider_reference);

        dispatch_sync(new EmitMaintenanceContractInvoice($charge->id));
        $this->assertSame(1, FiscalDocument::query()->count());
        Http::assertSentCount(1);
    }

    public function test_missing_customer_document_fails_without_calling_spedy(): void
    {
        $this->customer->update(['cpfcnpj' => null]);
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $this->settle($charge);

        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);
        $log = MaintenanceContractLog::query()->where('maintenance_contract_id', $contract->id)->where('action', 'invoice_failed')->sole();
        $this->assertStringContainsString('CPF ou CNPJ', $log->data['error']);
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ envio ao cliente

    public function test_email_failure_is_recorded_and_resend_never_emits_a_new_invoice(): void
    {
        $this->fakeSpedy('authorized');
        // SMTP apontando para porta fechada: o envio automático falha.
        DB::table('others')->where('tenant_id', $this->tenant->id)->update(['mail_host' => '127.0.0.1', 'mail_port' => 1]);
        [$contract, $charge] = $this->contractWithCharge(issue: true, send: true);
        $this->settle($charge);

        $document = FiscalDocument::query()->sole();
        $failed = FiscalDocumentDelivery::query()->sole();
        $this->assertSame(FiscalDocumentDelivery::STATUS_FAILED, $failed->status);
        $this->assertStringNotContainsString('127.0.0.1', (string) $failed->error);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->refresh()->status);

        Mail::fake();
        $this->post(route('app.maintenance-contracts.invoices.send', [$contract, $document]))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'financeiro@condominio.test'));

        $this->assertSame(2, FiscalDocumentDelivery::query()->count());
        $this->assertSame(FiscalDocumentDelivery::ORIGIN_MANUAL, FiscalDocumentDelivery::query()->latest('id')->first()->origin);
        $this->assertSame(1, FiscalDocument::query()->count());
        $emissions = collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'POST' && $pair[0]->url() === self::SANDBOX.'/service-invoices');
        $this->assertCount(1, $emissions);
        // PDF/XML baixados uma vez só (guarda no disco fiscal); o envio e o reenvio usam a cópia.
        $this->assertCount(2, collect(Http::recorded())->filter(fn ($pair) => $pair[0]->method() === 'GET'));
    }

    public function test_customer_without_email_is_recorded_as_failed_delivery(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        $this->customer->update(['email' => null]);
        [, $charge] = $this->contractWithCharge(issue: true, send: true);
        $this->settle($charge);

        $delivery = FiscalDocumentDelivery::query()->sole();
        $this->assertSame(FiscalDocumentDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('e-mail válido', $delivery->error);
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------------ estorno e isolamento

    public function test_reversal_after_payment_restores_balance_and_cash_without_touching_the_invoice(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $this->settle($charge);
        $payment = AccountReceivablePayment::query()->sole();

        $this->post(route('app.maintenance-contracts.payments.reverse', [$contract, $payment]), ['reason' => 'Cheque devolvido'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'contabilidade'));

        $charge->refresh();
        $this->assertSame(AccountReceivable::STATUS_PENDING, $charge->status);
        $this->assertSame(350.0, (float) $charge->balance_amount);
        $this->assertNotNull($payment->refresh()->reversed_at);
        $this->assertNotNull(CashSessionMovement::query()->sole()->cancelled_at);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::query()->sole()->status);

        $log = MaintenanceContractLog::query()->where('action', 'payment_reversed')->sole();
        $this->assertTrue($log->data['invoice_review_required']);

        $this->post(route('app.maintenance-contracts.payments.reverse', [$contract, $payment]), ['reason' => 'De novo'])->assertSessionHasErrors('reason');
    }

    public function test_reversal_after_cash_closed_registers_refund_in_the_current_cash(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);
        $this->settle($charge);
        CashSession::query()->update(['status' => 'closed', 'closed_at' => now()]);
        $this->openCash($this->tenant, $this->user, 500);

        $this->post(route('app.maintenance-contracts.payments.reverse', [$contract, AccountReceivablePayment::query()->sole()]), ['reason' => 'Pagamento em duplicidade'])
            ->assertSessionHasNoErrors();

        $withdrawal = CashSessionMovement::query()->where('type', CashSessionMovement::TYPE_WITHDRAWAL)->sole();
        $this->assertSame(350.0, (float) $withdrawal->amount);
        $this->assertNull(CashSessionMovement::query()->where('type', CashSessionMovement::TYPE_ENTRY)->sole()->cancelled_at);
    }

    public function test_tenants_are_isolated_in_charges_payments_and_fiscal_credentials(): void
    {
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);

        $other = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $otherUser = User::factory()->forTenant($other->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->prepareTenant($other, 'company-key-b');
        $this->openCash($other, $otherUser);

        $this->actingAs($otherUser)->withSession(['tenant_id' => $other->id]);
        $this->get(route('app.maintenance-contracts.charges', $contract))->assertNotFound();
        // POST com registro inexistente para o tenant volta com erro (tratamento padrão do app).
        $this->post($this->payRoute($contract, $charge), $this->paymentPayload(350))
            ->assertRedirect()
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Não foi possível encontrar'));
        $this->assertSame(0, AccountReceivablePayment::query()->withoutGlobalScopes()->count());

        $this->actingAs($this->user)->withSession(['tenant_id' => $this->tenant->id]);
        $this->settle($charge);

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices' && $request->hasHeader('X-Api-Key', 'company-key-a'));
        Http::assertNotSent(fn (HttpRequest $request) => $request->hasHeader('X-Api-Key', 'company-key-b'));
    }

    public function test_charges_page_shows_financial_and_fiscal_situation(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true, send: true);
        $this->settle($charge);

        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/maintenance-contracts/charges')
                ->where('charges.0.status', 'paid')
                ->where('charges.0.paid_amount', 350)
                ->where('charges.0.invoice.status', 'authorized')
                ->where('charges.0.invoice.number', '1234')
                ->where('charges.0.invoice.delivery.status', 'sent')
                ->where('charges.0.payments.0.amount', 350));

        $this->get(route('app.maintenance-contracts.index'))->assertOk();
    }

    // ------------------------------------------------------------------ apoio

    private function prepareTenant(Tenant $tenant, string $apiKey): void
    {
        DB::table('others')->insert([
            'tenant_id' => $tenant->id,
            'navigation' => false,
            'enable_finance' => true,
            'mail_mailer' => 'smtp',
            'mail_host' => 'smtp.empresa.test',
            'mail_port' => 587,
            'mail_username' => 'contato@empresa.test',
            'mail_password' => Crypt::encryptString('senha-smtp-teste'),
            'mail_from_address' => 'contato@empresa.test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Company::query()->create([
            'tenant_id' => $tenant->id,
            'companyname' => 'Assistência Exemplo LTDA',
            'cnpj' => '11.222.333/0001-81',
            'zip_code' => '90000-000',
            'state' => 'RS',
            'city' => 'Porto Alegre',
            'district' => 'Centro',
            'street' => 'Rua dos Andradas',
            'number' => '100',
        ]);
        FiscalSetting::query()->create([
            'tenant_id' => $tenant->id,
            'enabled' => true,
            'provider' => FiscalSetting::PROVIDER_SPEDY,
            'spedy_company_id' => 'company-'.$tenant->id,
            'api_token' => $apiKey,
            'registration_status' => FiscalSetting::REGISTRATION_REGISTERED,
            'registered_at' => now(),
            'emission_environment' => FiscalSetting::ENVIRONMENT_HOMOLOGATION,
            'certificate_expires_at' => now()->addYear(),
            'company_tax_regime' => '1',
            'nfse_enabled' => true,
            'nfse_allowed' => true,
            'service_list_item' => '14.01',
            'service_city_code' => '4314902',
            'default_iss_rate' => 2.5,
            'nfse_taxation_type' => 'taxationInMunicipality',
            'tax_settings_confirmed_at' => now(),
        ]);
    }

    private function openCash(Tenant $tenant, User $user, float $opening = 0): void
    {
        CashSession::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenant->id,
            'opened_by' => $user->id,
            'opened_at' => now(),
            'opening_balance' => $opening,
            'status' => 'open',
        ]);
    }

    /** @return array{0: MaintenanceContract, 1: AccountReceivable} */
    private function contractWithCharge(bool $issue, bool $send = false): array
    {
        $contract = MaintenanceContract::query()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'contract_number' => 7,
            'description' => 'Manutenção preventiva mensal dos elevadores',
            'monthly_amount' => 350,
            'billing_day' => 5,
            'start_date' => now()->subMonth()->toDateString(),
            'next_billing_date' => now()->toDateString(),
            'status' => MaintenanceContract::STATUS_ACTIVE,
            'auto_issue_invoice' => $issue,
            'auto_send_invoice' => $send,
        ]);
        $charge = app(MaintenanceContractService::class)->processBillingCycle($contract);

        return [$contract->refresh(), $charge];
    }

    private function settle(AccountReceivable $charge): void
    {
        $contract = MaintenanceContract::query()->findOrFail($charge->source_id);
        $this->pay($contract, $charge, (float) $charge->refresh()->balance_amount)->assertSessionHasNoErrors();
    }

    private function pay(MaintenanceContract $contract, AccountReceivable $charge, float $amount)
    {
        return $this->post($this->payRoute($contract, $charge), $this->paymentPayload($amount));
    }

    private function payRoute(MaintenanceContract $contract, AccountReceivable $charge): string
    {
        return route('app.maintenance-contracts.charges.payments.store', [$contract, $charge]);
    }

    private function paymentPayload(float $amount, ?string $requestKey = null): array
    {
        return [
            'amount' => $amount,
            'paid_at' => now()->subMinute()->toDateTimeString(),
            'payment_method' => 'pix',
            'notes' => null,
            'request_key' => $requestKey ?? (string) Str::uuid(),
        ];
    }

    private function contractPayload(array $overrides = []): array
    {
        return [
            'customer_id' => $this->customer->id,
            'description' => 'Manutenção mensal',
            'monthly_amount' => 200,
            'billing_day' => 10,
            'start_date' => now()->toDateString(),
            ...$overrides,
        ];
    }

    private function fakeSpedy(string $status): void
    {
        Http::fake([
            self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', $status)),
            self::SANDBOX.'/service-invoices/nfse-1/pdf' => Http::response('%PDF-1.4 nfse', 200, ['Content-Type' => 'application/pdf']),
            self::SANDBOX.'/service-invoices/nfse-1/xml' => Http::response('<nfse>xml</nfse>', 200, ['Content-Type' => 'application/xml']),
        ]);
    }

    private function invoice(string $id, string $status, array $extra = []): array
    {
        return [
            'id' => $id,
            'status' => $status,
            'model' => 'serviceInvoice',
            'environmentType' => 'development',
            'number' => 1234,
            'series' => '1',
            'accessKey' => 'VERIF-1234',
            'authorization' => $status === 'authorized' ? ['date' => '2026-10-09T10:00:00', 'protocol' => 'PROT-1'] : null,
            'processingDetail' => ['status' => 'success', 'message' => null, 'code' => null],
            ...$extra,
        ];
    }
}
