<?php

namespace Tests\Feature\App;

use App\Jobs\EmitMaintenanceContractInvoice;
use App\Mail\MaintenanceInvoiceMail;
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
use App\Models\App\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AccountReceivablePaymentService;
use App\Services\Fiscal\NativeFiscalService;
use App\Services\MaintenanceContractService;
use App\Services\Payments\ReceivablePaymentChannel;
use App\Support\Fiscal\FiscalDocumentLinks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * VETOR-FISCAL-05.3: cobrança recorrente, NFS-e programada por ciclo (independente do pagamento),
 * recebimento manual e fatura com links protegidos.
 */
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

    public function test_new_contracts_start_with_both_options_disabled_and_due_month_competence(): void
    {
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload())->assertSessionHasNoErrors();

        $contract = MaintenanceContract::query()->sole();
        $this->assertFalse($contract->auto_issue_invoice);
        $this->assertFalse($contract->auto_send_invoice);
        $this->assertNull($contract->auto_issue_enabled_at);
        $this->assertSame(MaintenanceContract::COMPETENCE_DUE_MONTH, $contract->invoice_competence);
    }

    public function test_activation_is_refused_without_valid_fiscal_configuration(): void
    {
        FiscalSetting::query()->where('tenant_id', $this->tenant->id)->update(['default_iss_rate' => null]);
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['auto_issue_invoice' => true]))
            ->assertSessionHasErrors('auto_issue_invoice');

        FiscalSetting::query()->where('tenant_id', $this->tenant->id)->update(['default_iss_rate' => 2.5, 'nfse_allowed' => false]);
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['auto_issue_invoice' => true]))
            ->assertSessionHasErrors('auto_issue_invoice');

        $this->assertSame(0, MaintenanceContract::query()->count());
    }

    public function test_enabling_issue_records_the_start_and_sending_requires_issue(): void
    {
        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['auto_send_invoice' => true]))->assertSessionHasNoErrors();
        $contract = MaintenanceContract::query()->sole();
        $this->assertFalse($contract->auto_send_invoice);

        $this->put(route('app.maintenance-contracts.update', $contract), $this->contractPayload(['auto_issue_invoice' => true, 'auto_send_invoice' => true]))
            ->assertSessionHasNoErrors();
        $contract->refresh();
        $this->assertTrue($contract->auto_send_invoice);
        $this->assertNotNull($contract->auto_issue_enabled_at);

        $this->put(route('app.maintenance-contracts.update', $contract), $this->contractPayload(['auto_issue_invoice' => false, 'auto_send_invoice' => true]))
            ->assertSessionHasNoErrors();
        $contract->refresh();
        $this->assertFalse($contract->auto_send_invoice);
        $this->assertNull($contract->auto_issue_enabled_at);
    }

    public function test_contract_cannot_use_a_customer_of_another_tenant(): void
    {
        $foreign = Customer::factory()->forTenant(Tenant::factory()->create()->id)->create();

        $this->post(route('app.maintenance-contracts.store'), $this->contractPayload(['customer_id' => $foreign->id]))
            ->assertSessionHasErrors('customer_id');
    }

    // ------------------------------------------------------------------ emissão programada (independente do pagamento)

    public function test_disabled_contract_keeps_billing_without_automatic_emission_but_allows_manual(): void
    {
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: false);

        $this->runScheduler();
        $this->assertSame(0, FiscalDocument::query()->count());
        Http::assertNothingSent();

        $this->post(route('app.maintenance-contracts.charges.invoice', [$contract, $charge]))->assertSessionHas('success', 'NFS-e autorizada.');
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(AccountReceivable::STATUS_PENDING, $charge->refresh()->status);
    }

    public function test_acceptance_scenario_emits_on_due_date_sends_invoice_keeps_charge_open_and_payment_never_reissues(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true, send: true);

        // Dia programado: emite sem exigir pagamento.
        $this->runScheduler();

        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->status);
        $this->assertSame($charge->id, (int) $document->documentable_id);
        $charge->refresh();
        $this->assertSame(AccountReceivable::STATUS_PENDING, $charge->status);
        $this->assertSame(0.0, (float) $charge->paid_amount);
        $this->assertSame(0, AccountReceivablePayment::query()->count());

        Http::assertSent(fn (HttpRequest $request) => $request->url() === self::SANDBOX.'/service-invoices'
            && $request->hasHeader('X-Api-Key', 'company-key-a')
            && $request['total']['invoiceAmount'] === 350.0
            && $request['receiver']['federalTaxNumber'] === '11444777000161'
            && $request['sendEmailToCustomer'] === false
            && str_contains($request['description'], 'Contrato de manutenção nº 7, ciclo 1')
            && str_contains($request['description'], 'Competência '.now()->format('m/Y')));

        // Fatura enviada com links protegidos e aviso de que nota não é pagamento.
        Mail::assertSent(MaintenanceInvoiceMail::class, function (MaintenanceInvoiceMail $mail) use ($contract) {
            $html = $mail->render();

            return $mail->hasTo('financeiro@condominio.test')
                && $mail->envelope()->subject === 'Fatura de manutenção — Contrato nº '.$contract->contract_number.' — '.now()->format('m/Y')
                && str_contains($mail->pdfUrl, 'signature=') && str_contains($mail->xmlUrl, 'signature=')
                && str_contains($html, 'A emissão da nota fiscal não representa confirmação do pagamento.')
                && str_contains($html, 'Em aberto')
                && str_contains($html, 'R$ 350,00')
                && $mail->attachments === [] && $mail->rawAttachments === [];
        });
        $this->assertSame(FiscalDocumentDelivery::STATUS_SENT, FiscalDocumentDelivery::query()->sole()->status);

        // Depois o cliente paga: quita, mantém a nota, não reemite.
        $this->pay($contract, $charge, 350)->assertSessionHas('success', 'Pagamento registrado. Cobrança quitada.');
        $this->assertSame(AccountReceivable::STATUS_PAID, $charge->refresh()->status);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->refresh()->status);

        // Agendador de novo para o mesmo ciclo: nada se repete.
        $this->runScheduler();
        $this->assertSame(1, AccountReceivable::query()->count());
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertSame(1, FiscalDocumentDelivery::query()->count());
        $this->assertCount(1, $this->emissionRequests());
    }

    public function test_charge_due_in_the_future_and_early_payment_do_not_emit(): void
    {
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $charge->forceFill(['fiscal_scheduled_for' => now()->addDays(5)->toDateString()])->save();

        $this->pay($contract, $charge, 350)->assertSessionHasNoErrors();
        $this->runScheduler();

        $this->assertSame(AccountReceivable::STATUS_PAID, $charge->refresh()->status);
        $this->assertSame(0, FiscalDocument::query()->count());
        Http::assertNothingSent();

        $this->travel(5)->days();
        $this->runScheduler();
        $this->assertSame(1, FiscalDocument::query()->count());
    }

    public function test_partial_payment_after_emission_only_changes_balance(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $this->runScheduler();
        $document = FiscalDocument::query()->sole();

        $this->pay($contract, $charge, 100)->assertSessionHasNoErrors();

        $charge->refresh();
        $this->assertSame(AccountReceivable::STATUS_PARTIAL, $charge->status);
        $this->assertSame(250.0, (float) $charge->balance_amount);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->refresh()->status);
        $this->assertCount(1, $this->emissionRequests());
    }

    public function test_manually_emitted_cycle_is_not_emitted_again_by_the_scheduler(): void
    {
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $charge->forceFill(['fiscal_scheduled_for' => now()->addDay()->toDateString()])->save();

        $this->post(route('app.maintenance-contracts.charges.invoice', [$contract, $charge]))->assertSessionHas('success', 'NFS-e autorizada.');
        $this->travel(2)->days();
        $this->runScheduler();

        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertCount(1, $this->emissionRequests());
    }

    public function test_scheduler_reruns_and_concurrent_jobs_do_not_duplicate(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);
        [, $charge] = $this->contractWithCharge(issue: true);

        Queue::fake();
        $service = app(MaintenanceContractService::class);
        $this->assertSame(1, $service->queueScheduledInvoices());
        $this->assertSame(0, $service->queueScheduledInvoices());
        Queue::assertPushed(EmitMaintenanceContractInvoice::class, 1);

        // Dois workers executando o mesmo ciclo: uma nota, uma chamada.
        $this->runEmissionJob($charge->id);
        $this->runEmissionJob($charge->id);
        $this->assertSame(1, FiscalDocument::query()->count());
        Http::assertSentCount(1);

        // Com documento, nem uma reserva antiga é refeita.
        $charge->refresh()->forceFill(['fiscal_queued_at' => now()->subHours(2)])->save();
        $this->assertSame(0, $service->queueScheduledInvoices());
    }

    public function test_lost_job_reservation_is_reconciled_after_one_hour(): void
    {
        Queue::fake();
        [, $charge] = $this->contractWithCharge(issue: true);
        $service = app(MaintenanceContractService::class);

        $this->assertSame(1, $service->queueScheduledInvoices());
        $this->assertSame(0, $service->queueScheduledInvoices());
        $charge->refresh()->forceFill(['fiscal_queued_at' => now()->subMinutes(61)])->save();
        $this->assertSame(1, $service->queueScheduledInvoices());
    }

    public function test_spedy_timeout_keeps_processing_and_reruns_never_create_another_invoice(): void
    {
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response(['message' => 'timeout'], 503)]);
        [$contract, $charge] = $this->contractWithCharge(issue: true, send: true);

        $this->runScheduler();
        $document = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $document->status);
        $this->assertNotNull($document->integration_id);

        $this->runScheduler();
        $this->runEmissionJob($charge->id);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertCount(1, $this->emissionRequests());
        $this->assertSame(0, FiscalDocumentDelivery::query()->count());

        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertInertia(fn ($page) => $page->where('charges.0.fiscal_state', 'processing')->where('charges.0.delivery_state', 'awaiting_authorization'));
    }

    public function test_rejection_is_not_retried_automatically_and_manual_reprocess_reuses_the_document(): void
    {
        Http::fakeSequence(self::SANDBOX.'/service-invoices')
            ->push($this->invoice('nfse-1', 'rejected', ['processingDetail' => ['status' => 'success', 'code' => 'E01', 'message' => 'Código de serviço inválido']]))
            ->push($this->invoice('nfse-1', 'enqueued'));
        [$contract, $charge] = $this->contractWithCharge(issue: true);

        $this->runScheduler();
        $first = FiscalDocument::query()->sole();
        $this->assertSame(FiscalDocument::STATUS_REJECTED, $first->status);

        $this->runScheduler();
        $this->assertCount(1, $this->emissionRequests());

        $this->post(route('app.maintenance-contracts.charges.invoice', [$contract, $charge]))->assertSessionHas('success');
        $second = FiscalDocument::query()->sole();
        $this->assertSame($first->id, $second->id);
        $this->assertSame(FiscalDocument::STATUS_PROCESSING, $second->status);
        $this->assertCount(2, $this->emissionRequests());
        $this->assertCount(1, $this->emissionRequests()->map(fn (HttpRequest $request) => $request['integrationId'])->unique());
    }

    public function test_competence_can_differ_from_due_date(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [, $charge] = $this->contractWithCharge(issue: true, send: true, competence: MaintenanceContract::COMPETENCE_PREVIOUS_MONTH);

        $previous = now()->startOfMonth()->subMonthNoOverflow();
        $this->assertSame($previous->toDateString(), $charge->competence_start->toDateString());
        $this->assertSame($previous->copy()->endOfMonth()->toDateString(), $charge->competence_end->toDateString());
        $this->assertSame(now()->toDateString(), $charge->due_date->toDateString());
        $this->assertSame(now()->toDateString(), $charge->fiscal_scheduled_for->toDateString());

        $this->runScheduler();

        $this->assertTrue($this->emissionRequests()->every(fn (HttpRequest $request) => str_contains((string) $request['description'], 'Competência '.$previous->format('m/Y'))));
        $this->assertCount(1, $this->emissionRequests());
        Mail::assertSent(MaintenanceInvoiceMail::class, fn (MaintenanceInvoiceMail $mail) => str_ends_with($mail->envelope()->subject, $previous->format('m/Y')));
    }

    public function test_cancelled_or_suspended_contracts_are_not_emitted_automatically(): void
    {
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $contract->update(['status' => MaintenanceContract::STATUS_SUSPENDED]);

        $this->runScheduler();
        $this->runEmissionJob($charge->id);

        $this->assertSame(0, FiscalDocument::query()->count());
        $this->assertTrue(MaintenanceContractLog::query()->where('action', 'invoice_skipped')->exists());
        Http::assertNothingSent();
    }

    public function test_historical_charges_are_not_emitted_when_the_option_is_enabled_later(): void
    {
        $this->fakeSpedy('authorized');
        [$contract, $legacy] = $this->contractWithCharge(issue: false);
        $legacy->forceFill(['fiscal_scheduled_for' => null])->save(); // cobrança anterior à migration

        AccountReceivable::query()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'source_type' => AccountReceivable::SOURCE_MAINTENANCE_CONTRACT,
            'source_id' => $contract->id, 'description' => 'Ciclo antigo', 'total_amount' => 350, 'paid_amount' => 0, 'balance_amount' => 350,
            'due_date' => now()->subMonth()->toDateString(), 'fiscal_scheduled_for' => now()->subMonth()->toDateString(),
            'status' => AccountReceivable::STATUS_PENDING, 'installment_number' => 9,
        ]);

        $contract->update(['auto_issue_invoice' => true, 'auto_issue_enabled_at' => now()]);
        $this->runScheduler();

        $this->assertSame(0, FiscalDocument::query()->count());
        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertInertia(fn ($page) => $page->where('charges', fn ($charges) => collect($charges)->every(fn ($charge) => $charge['fiscal_state'] === 'not_issued')));
    }

    public function test_missing_customer_document_fails_without_calling_spedy(): void
    {
        $this->customer->update(['cpfcnpj' => null]);
        [$contract] = $this->contractWithCharge(issue: true);

        $this->runScheduler();

        $this->assertSame(FiscalDocument::STATUS_FAILED, FiscalDocument::query()->sole()->status);
        $log = MaintenanceContractLog::query()->where('maintenance_contract_id', $contract->id)->where('action', 'invoice_failed')->sole();
        $this->assertStringContainsString('CPF ou CNPJ', $log->data['error']);
        Http::assertNothingSent();
    }

    public function test_a_failure_in_one_contract_does_not_stop_the_others(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        $broken = MaintenanceContract::query()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'contract_number' => 8, 'description' => 'Contrato com erro',
            'monthly_amount' => 100, 'billing_day' => 10, 'start_date' => now()->toDateString(), 'next_billing_date' => now()->toDateString(),
            'status' => MaintenanceContract::STATUS_ACTIVE,
        ]);
        $good = MaintenanceContract::query()->create([
            'tenant_id' => $this->tenant->id, 'customer_id' => $this->customer->id, 'contract_number' => 9, 'description' => 'Contrato saudável',
            'monthly_amount' => 350, 'billing_day' => 10, 'start_date' => now()->toDateString(), 'next_billing_date' => now()->toDateString(),
            'status' => MaintenanceContract::STATUS_ACTIVE, 'auto_issue_invoice' => true, 'auto_issue_enabled_at' => now()->subDay(),
        ]);
        // Falha simulada só na cobrança do primeiro contrato.
        AccountReceivable::creating(function (AccountReceivable $receivable) use ($broken) {
            if ((int) $receivable->source_id === $broken->id) {
                throw new \RuntimeException('falha simulada');
            }
        });

        $this->artisan('vetoros:process-maintenance-contracts')->assertSuccessful()->expectsOutputToContain('Falhas: 1');

        $this->assertSame(0, AccountReceivable::query()->where('source_id', $broken->id)->count());
        $charge = AccountReceivable::query()->where('source_id', $good->id)->sole();
        $this->assertSame(1, FiscalDocument::query()->where('documentable_id', $charge->id)->count());
        $this->assertSame(now()->toDateString(), $broken->refresh()->next_billing_date->toDateString());
    }

    // ------------------------------------------------------------------ envio da fatura

    public function test_invoice_is_only_sent_after_authorization(): void
    {
        Mail::fake();
        Http::fake([self::SANDBOX.'/service-invoices' => Http::response($this->invoice('nfse-1', 'enqueued'))]);
        [$contract] = $this->contractWithCharge(issue: true, send: true);

        $this->runScheduler();
        Mail::assertNothingSent();
        $this->assertSame(0, FiscalDocumentDelivery::query()->count());

        $this->post(route('app.maintenance-contracts.invoices.send', [$contract, FiscalDocument::query()->sole()]))
            ->assertSessionHas('error', fn (string $message) => str_contains($message, 'Somente notas autorizadas'));
    }

    public function test_smtp_failure_is_recorded_and_resend_never_emits_a_new_invoice(): void
    {
        $this->fakeSpedy('authorized');
        DB::table('others')->where('tenant_id', $this->tenant->id)->update(['mail_host' => '127.0.0.1', 'mail_port' => 1]);
        [$contract] = $this->contractWithCharge(issue: true, send: true);

        $this->runScheduler();

        $document = FiscalDocument::query()->sole();
        $failed = FiscalDocumentDelivery::query()->sole();
        $this->assertSame(FiscalDocumentDelivery::STATUS_FAILED, $failed->status);
        $this->assertStringNotContainsString('127.0.0.1', (string) $failed->error);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, $document->refresh()->status);

        Mail::fake();
        $this->post(route('app.maintenance-contracts.invoices.send', [$contract, $document]))
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'financeiro@condominio.test'));
        Mail::assertSent(MaintenanceInvoiceMail::class, 1);

        $this->assertSame(2, FiscalDocumentDelivery::query()->count());
        $this->assertSame(FiscalDocumentDelivery::ORIGIN_MANUAL, FiscalDocumentDelivery::query()->latest('id')->first()->origin);
        $this->assertSame(1, FiscalDocument::query()->count());
        $this->assertCount(1, $this->emissionRequests());
    }

    public function test_customer_without_email_is_recorded_as_failed_delivery(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        $this->customer->update(['email' => null]);
        $this->contractWithCharge(issue: true, send: true);

        $this->runScheduler();

        $delivery = FiscalDocumentDelivery::query()->sole();
        $this->assertSame(FiscalDocumentDelivery::STATUS_FAILED, $delivery->status);
        $this->assertStringContainsString('e-mail válido', $delivery->error);
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------------ links de PDF/XML

    public function test_signed_links_serve_stored_files_and_reject_expired_or_tampered_links(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract] = $this->contractWithCharge(issue: true);
        $this->runScheduler();
        $document = FiscalDocument::query()->sole()->refresh();
        $this->assertNotNull($document->pdf_path);

        $links = FiscalDocumentLinks::for($document);
        auth()->logout();
        $before = count(Http::recorded());

        $this->get($links['pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->get($links['xml'])->assertOk()->assertHeader('Content-Disposition', 'attachment; filename="nfse-1234.xml"');
        $this->assertSame($before, count(Http::recorded()), 'Os links devem usar a cópia guardada no disco fiscal.');
        $this->assertSame(2, MaintenanceContractLog::query()->where('maintenance_contract_id', $contract->id)->where('action', 'invoice_file_accessed')->count());

        $this->get(str_replace('/pdf?', '/xml?', $links['pdf']))->assertForbidden();
        $this->get(route('fiscal-documents.shared', ['document' => $document->id, 'format' => 'pdf']))->assertForbidden();
        $this->travel(config('services.fiscal_links.days') + 1)->days();
        $this->get($links['pdf'])->assertForbidden()->assertSee('Este link expirou');
    }

    public function test_fiscal_files_are_not_reachable_by_another_tenant(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract] = $this->contractWithCharge(issue: true);
        $this->runScheduler();
        $document = FiscalDocument::query()->sole();

        $other = Tenant::factory()->create(['automatic_fiscal_emission_enabled' => true]);
        $otherUser = User::factory()->forTenant($other->id)->create(['roles' => User::ROLE_ADMIN]);
        $this->prepareTenant($other, 'company-key-b');

        $this->actingAs($otherUser)->withSession(['tenant_id' => $other->id]);
        $this->get(route('app.fiscal-documents.file', ['fiscalDocument' => $document->id, 'format' => 'pdf']))->assertNotFound();
        $this->get(route('app.maintenance-contracts.charges', $contract))->assertNotFound();

        // Documento de OS/venda não vira link público, mesmo com assinatura válida.
        $orderDocument = FiscalDocument::query()->withoutGlobalScopes()->create([
            'tenant_id' => $other->id, 'documentable_type' => Order::class, 'documentable_id' => 1, 'type' => 'nfse',
            'provider' => FiscalSetting::PROVIDER_SPEDY, 'status' => FiscalDocument::STATUS_AUTHORIZED,
        ]);
        auth()->logout();
        $this->get(FiscalDocumentLinks::for($orderDocument)['pdf'])->assertNotFound();
    }

    // ------------------------------------------------------------------ recebimento

    public function test_manual_payment_requires_permission_open_cash_and_valid_amount(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);

        $technician = User::factory()->forTenant($this->tenant->id)->create(['roles' => User::ROLE_TECHNICIAN]);
        $this->actingAs($technician)->post($this->payRoute($contract, $charge), $this->paymentPayload(350))
            ->assertRedirect()->assertSessionHas('authorization_error');

        $this->actingAs($this->user);
        $this->pay($contract, $charge, 400)->assertSessionHasErrors('amount');
        $this->pay($contract, $charge, 0)->assertSessionHasErrors('amount');
        $this->post($this->payRoute($contract, $charge), [...$this->paymentPayload(10), 'paid_at' => now()->addDay()->toDateTimeString()])
            ->assertSessionHasErrors('paid_at');

        CashSession::query()->update(['status' => 'closed', 'closed_at' => now()]);
        $this->pay($contract, $charge, 350)->assertSessionHasErrors('amount');

        $this->assertSame(0, AccountReceivablePayment::query()->count());
    }

    public function test_same_confirmation_submitted_twice_registers_the_payment_once(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);
        $key = (string) Str::uuid();

        $this->post($this->payRoute($contract, $charge), $this->paymentPayload(100, $key))->assertSessionHasNoErrors();
        $this->post($this->payRoute($contract, $charge), $this->paymentPayload(100, $key))
            ->assertSessionHas('success', 'Este pagamento já estava registrado; nada foi alterado.');

        $this->assertSame(1, AccountReceivablePayment::query()->count());
        $this->assertSame(1, CashSessionMovement::query()->count());
        $this->post($this->payRoute($contract, $charge), [...$this->paymentPayload(10), 'request_key' => 'nao-e-uuid'])->assertSessionHasErrors('request_key');
    }

    public function test_future_integrated_payment_event_is_idempotent_and_never_emits(): void
    {
        [, $charge] = $this->contractWithCharge(issue: true);
        $charge->forceFill(['fiscal_scheduled_for' => now()->addMonth()->toDateString()])->save();
        $service = app(AccountReceivablePaymentService::class);
        $event = ['amount' => 350, 'paid_at' => now()->toDateTimeString(), 'payment_method' => 'pix'];

        $first = $service->register($charge, $event, null, AccountReceivablePayment::SOURCE_INTEGRATION, 'provedor:evt-123');
        $second = $service->register($charge, $event, null, AccountReceivablePayment::SOURCE_INTEGRATION, 'provedor:evt-123');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(350.0, (float) $charge->refresh()->paid_amount);
        $this->assertSame(0, CashSessionMovement::query()->count());
        $this->assertSame(0, FiscalDocument::query()->count());
        Http::assertNothingSent();
    }

    public function test_pay_button_is_blocked_in_backend_when_an_automatic_channel_is_active(): void
    {
        $this->app->bind(ReceivablePaymentChannel::class, fn () => new class implements ReceivablePaymentChannel
        {
            public function automaticChannelFor(AccountReceivable $receivable): ?string
            {
                return 'Pix do provedor';
            }
        });
        [$contract, $charge] = $this->contractWithCharge(issue: false);

        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertInertia(fn ($page) => $page->where('charges.0.payment_state', 'awaiting_automatic')->where('charges.0.automatic_channel', 'Pix do provedor'));
        $this->pay($contract, $charge, 350)->assertSessionHasErrors('amount');
        $this->assertSame(0, AccountReceivablePayment::query()->count());
    }

    public function test_reversal_after_emission_reopens_the_charge_without_touching_the_invoice(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true);
        $this->runScheduler();
        $this->pay($contract, $charge, 350)->assertSessionHasNoErrors();
        $payment = AccountReceivablePayment::query()->sole();

        $this->post(route('app.maintenance-contracts.payments.reverse', [$contract, $payment]), ['reason' => 'Cheque devolvido'])
            ->assertSessionHas('success', fn (string $message) => str_contains($message, 'continua válida'));

        $this->assertSame(AccountReceivable::STATUS_PENDING, $charge->refresh()->status);
        $this->assertNotNull(CashSessionMovement::query()->sole()->cancelled_at);
        $this->assertSame(FiscalDocument::STATUS_AUTHORIZED, FiscalDocument::query()->sole()->status);
        $log = MaintenanceContractLog::query()->where('action', 'payment_reversed')->sole();
        $this->assertTrue($log->data['has_authorized_invoice']);
        $this->assertArrayNotHasKey('invoice_review_required', $log->data);
    }

    public function test_reversal_after_cash_closed_registers_refund_in_the_current_cash(): void
    {
        [$contract, $charge] = $this->contractWithCharge(issue: false);
        $this->pay($contract, $charge, 350)->assertSessionHasNoErrors();
        CashSession::query()->update(['status' => 'closed', 'closed_at' => now()]);
        $this->openCash($this->tenant, $this->user, 500);

        $this->post(route('app.maintenance-contracts.payments.reverse', [$contract, AccountReceivablePayment::query()->sole()]), ['reason' => 'Pagamento em duplicidade'])
            ->assertSessionHasNoErrors();

        $this->assertSame(350.0, (float) CashSessionMovement::query()->where('type', CashSessionMovement::TYPE_WITHDRAWAL)->sole()->amount);
    }

    public function test_charges_page_separates_payment_fiscal_and_delivery_states(): void
    {
        Mail::fake();
        $this->fakeSpedy('authorized');
        [$contract, $charge] = $this->contractWithCharge(issue: true, send: true);

        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('app/maintenance-contracts/charges')
                ->where('charges.0.payment_state', 'pay')
                ->where('charges.0.fiscal_state', 'scheduled')
                ->where('charges.0.delivery_state', 'awaiting_authorization'));

        $this->runScheduler();
        $this->pay($contract, $charge, 100);

        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertInertia(fn ($page) => $page
                ->where('charges.0.payment_state', 'pay_balance')
                ->where('charges.0.fiscal_state', 'authorized')
                ->where('charges.0.invoice.number', '1234')
                ->where('charges.0.delivery_state', 'sent')
                ->where('charges.0.competence', now()->format('m/Y')));

        $this->travel(40)->days();
        $this->get(route('app.maintenance-contracts.charges', $contract))
            ->assertInertia(fn ($page) => $page->where('charges.0.overdue', true));
        $this->get(route('app.maintenance-contracts.index'))->assertOk();
    }

    // ------------------------------------------------------------------ apoio

    /** Executa o job de emissão diretamente (dispatch_sync passaria pela fila falsa). */
    private function runEmissionJob(int $receivableId): void
    {
        (new EmitMaintenanceContractInvoice($receivableId))->handle(app(NativeFiscalService::class), app(MaintenanceContractService::class));
    }

    private function runScheduler(): void
    {
        $this->artisan('vetoros:process-maintenance-contracts')->assertSuccessful();
    }

    /** @return Collection<int, HttpRequest> */
    private function emissionRequests(): Collection
    {
        return collect(Http::recorded())
            ->filter(fn ($pair) => $pair[0]->method() === 'POST' && $pair[0]->url() === self::SANDBOX.'/service-invoices')
            ->map(fn ($pair) => $pair[0])
            ->values();
    }

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
    private function contractWithCharge(bool $issue, bool $send = false, string $competence = MaintenanceContract::COMPETENCE_DUE_MONTH): array
    {
        $contract = MaintenanceContract::query()->create([
            'tenant_id' => $this->tenant->id,
            'customer_id' => $this->customer->id,
            'contract_number' => 7,
            'description' => 'Manutenção preventiva mensal dos elevadores',
            'monthly_amount' => 350,
            'billing_day' => 10,
            'start_date' => now()->subMonth()->toDateString(),
            'next_billing_date' => now()->toDateString(),
            'status' => MaintenanceContract::STATUS_ACTIVE,
            'auto_issue_invoice' => $issue,
            'auto_send_invoice' => $send,
            'invoice_competence' => $competence,
            'auto_issue_enabled_at' => $issue ? now()->subDay() : null,
        ]);
        $charge = app(MaintenanceContractService::class)->processBillingCycle($contract);

        return [$contract->refresh(), $charge];
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
