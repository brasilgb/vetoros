<?php

namespace App\Services\Commercial;

use App\Jobs\EmitSaasChargeInvoice;
use App\Mail\SaasChargeInvoiceMail;
use App\Mail\SaasFiscalNoticeMail;
use App\Models\Admin\AdminFiscalDocument;
use App\Models\Admin\AdminFiscalDocumentDelivery;
use App\Models\Admin\AdminFiscalSetting;
use App\Models\Admin\FiscalAdminAudit;
use App\Models\Admin\Period;
use App\Models\Admin\Plan;
use App\Models\Admin\TenantCommercialContract;
use App\Models\Admin\TenantSubscriptionCharge;
use App\Models\Admin\TenantSubscriptionChargeDelivery;
use App\Models\App\FiscalDocument;
use App\Models\App\Payment;
use App\Models\Tenant;
use App\Services\Fiscal\SaasInvoiceService;
use App\Services\Payments\SaasPaymentService;
use App\Support\Fiscal\FiscalDocumentLinks;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Contrato comercial personalizado do tenant (VETOR-SAAS-CONTRATO-01).
 *
 * Reaproveita o controle de assinatura existente: o acesso continua decidido por `tenants.expires_at`
 * (vencimento → 3 dias de carência → bloqueio, em Tenant::subscriptionBucket). Este serviço só mantém
 * esse campo coerente com as cobranças do contrato: o acesso vale até o vencimento da cobrança mais
 * antiga não paga; quitar libera até o fim do ciclo; estornar devolve ao vencimento.
 *
 * Toda alteração passa pelo RootAdmin (rotas do painel admin) e fica em FiscalAdminAudit com
 * usuário, horário, IP e estado anterior/posterior.
 */
class SaasContractService
{
    /** Antecedência com que a cobrança do ciclo é gerada e a fatura enviada. */
    public const INVOICE_LEAD_DAYS = 5;

    /** Máximo de ciclos atrasados gerados por contrato numa execução (recuperação após parada). */
    private const MAX_CATCH_UP = 24;

    private const INVOICE_MAX_FAILED_ATTEMPTS = 3;

    public function __construct(private readonly SaasInvoiceService $saasInvoices) {}

    // ---- Condições comerciais ------------------------------------------

    /**
     * Ativa ou altera o contrato personalizado. Valor e periodicidade novos valem para os próximos
     * ciclos; cobranças já geradas não são recalculadas. O próximo vencimento não pode recair sobre
     * período já pago ou já cobrado, o que impede cobrança sobreposta na conversão do plano.
     *
     * @param  array{amount: string|float, billing_months: int, start_date: string, next_due_date: string, sales_rep?: ?string, notes?: ?string}  $data
     */
    public function saveContract(Tenant $tenant, array $data): TenantCommercialContract
    {
        return DB::transaction(function () use ($tenant, $data) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $contract = TenantCommercialContract::query()->lockForUpdate()->firstWhere('tenant_id', $tenant->id);
            $nextDue = Carbon::parse($data['next_due_date'])->startOfDay();
            $activating = ! $tenant->hasCustomContract() || ! $contract?->active;

            $floor = $this->coveredUntil($tenant, $contract, $activating);
            if ($floor && $nextDue->lt($floor)) {
                throw ValidationException::withMessages([
                    'next_due_date' => 'O próximo vencimento não pode ser anterior a '.$floor->format('d/m/Y').', fim do período já pago ou já cobrado.',
                ]);
            }

            $before = $contract ? $this->snapshot($contract, $tenant) : null;
            // O dia contratual acompanha o vencimento informado; sem mudança de vencimento ele se mantém.
            $dueChanged = $activating || ! $contract || ! $contract->next_due_date->equalTo($nextDue);
            $attributes = [
                'amount' => $this->money($data['amount']),
                'billing_months' => (int) $data['billing_months'],
                'start_date' => Carbon::parse($data['start_date'])->toDateString(),
                'next_due_date' => $nextDue->toDateString(),
                'anchor_day' => $dueChanged ? $nextDue->day : ($contract->anchor_day ?: $nextDue->day),
                'sales_rep' => filled($data['sales_rep'] ?? null) ? trim($data['sales_rep']) : null,
                'notes' => filled($data['notes'] ?? null) ? trim($data['notes']) : null,
                'active' => true,
                'deactivated_at' => null,
            ];

            if ($contract) {
                $contract->forceFill($attributes)->save();
            } else {
                $contract = TenantCommercialContract::query()->create([
                    ...$attributes,
                    'tenant_id' => $tenant->id,
                    'contract_number' => sprintf('VOS-%05d', $tenant->id),
                ]);
            }

            $tenant->forceFill(['billing_mode' => Tenant::BILLING_CUSTOM])->save();
            $this->syncCoverage($contract);

            FiscalAdminAudit::record($activating ? 'commercial.contract_activated' : 'commercial.contract_updated', $tenant->id, $contract, [
                'before' => $before,
                'after' => $this->snapshot($contract->refresh(), $tenant->refresh()),
            ]);

            return $contract;
        });
    }

    /**
     * Volta ao plano tradicional com plano válido, confirmação e data efetiva. Hoje: aplica na hora.
     * Data futura: agenda (o contrato segue valendo até lá e não gera cobrança com vencimento a partir
     * dessa data); o agendador aplica no dia (applyScheduledReverts).
     */
    public function revertToPlan(Tenant $tenant, int $planId, ?int $periodId, ?Carbon $effectiveOn = null, ?int $userId = null): Tenant
    {
        $plan = $this->revertiblePlan($planId);
        $effectiveOn = ($effectiveOn ?? now())->copy()->startOfDay();

        if ($effectiveOn->lte(now()->startOfDay())) {
            return $this->applyRevert($tenant, $plan, $periodId);
        }

        return DB::transaction(function () use ($tenant, $plan, $periodId, $effectiveOn, $userId) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $contract = $this->activeContractOrFail($tenant);
            $before = $this->snapshot($contract, $tenant);
            $period = $periodId ? Period::query()->where('plan_id', $plan->id)->find($periodId) : null;

            $contract->forceFill([
                'revert_scheduled_for' => $effectiveOn->toDateString(),
                'revert_plan_id' => $plan->id,
                'revert_period_id' => $period?->id,
                'revert_requested_by' => $userId,
            ])->save();

            FiscalAdminAudit::record('commercial.revert_scheduled', $tenant->id, $contract, [
                'before' => $before,
                'after' => $this->snapshot($contract, $tenant),
            ]);

            return $tenant;
        });
    }

    /** Desfaz a volta ao plano agendada; o contrato segue como estava. */
    public function cancelScheduledRevert(Tenant $tenant): TenantCommercialContract
    {
        return DB::transaction(function () use ($tenant) {
            $contract = $this->activeContractOrFail(Tenant::query()->lockForUpdate()->findOrFail($tenant->id));

            if (! $contract->revert_scheduled_for) {
                throw ValidationException::withMessages(['plan_id' => 'Não há volta ao plano agendada.']);
            }

            $before = $this->snapshot($contract, $contract->tenant);
            $contract->forceFill(['revert_scheduled_for' => null, 'revert_plan_id' => null, 'revert_period_id' => null, 'revert_requested_by' => null])->save();

            FiscalAdminAudit::record('commercial.revert_cancelled', $contract->tenant_id, $contract, [
                'before' => $before,
                'after' => $this->snapshot($contract, $contract->tenant),
            ]);

            return $contract;
        });
    }

    /**
     * Aplica as voltas ao plano cuja data efetiva chegou. Um bloqueio (cobrança em aberto com NFS-e
     * ativa) fica auditado e o agendamento permanece para a próxima execução.
     */
    public function applyScheduledReverts(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $applied = 0;

        TenantCommercialContract::query()
            ->where('active', true)
            ->whereNotNull('revert_scheduled_for')
            ->whereDate('revert_scheduled_for', '<=', $today->toDateString())
            ->with('tenant')
            ->orderBy('id')
            ->get()
            ->each(function (TenantCommercialContract $contract) use (&$applied) {
                try {
                    $this->applyRevert($contract->tenant, $this->revertiblePlan((int) $contract->revert_plan_id), $contract->revert_period_id);
                    $applied++;
                } catch (ValidationException $exception) {
                    FiscalAdminAudit::record('commercial.scheduled_revert_blocked', $contract->tenant_id, $contract, ['errors' => $exception->errors()]);
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

        return $applied;
    }

    /**
     * Volta efetiva ao plano. Cobranças pagas e notas ficam; cobranças em aberto sem nota ativa são
     * canceladas. O período já pago continua valendo e a próxima renovação pelo plano parte dele
     * (WebhookController soma a partir de expires_at).
     */
    private function applyRevert(Tenant $tenant, Plan $plan, ?int $periodId): Tenant
    {
        return DB::transaction(function () use ($tenant, $plan, $periodId) {
            $tenant = Tenant::query()->lockForUpdate()->findOrFail($tenant->id);
            $contract = $this->activeContractOrFail($tenant);

            $open = $contract->charges()->where('status', TenantSubscriptionCharge::STATUS_PENDING)->get();
            $withInvoice = $open->first(fn (TenantSubscriptionCharge $charge) => $this->hasActiveDocument($charge));
            if ($withInvoice) {
                throw ValidationException::withMessages([
                    'plan_id' => 'A cobrança com vencimento em '.$withInvoice->due_date->format('d/m/Y').' tem NFS-e ativa. Registre o pagamento ou cancele a nota antes de voltar ao plano.',
                ]);
            }

            $before = $this->snapshot($contract, $tenant);
            foreach ($open as $charge) {
                $charge->forceFill(['status' => TenantSubscriptionCharge::STATUS_CANCELLED, 'cancelled_at' => now()])->save();
            }

            $contract->forceFill([
                'active' => false,
                'deactivated_at' => now(),
                'revert_scheduled_for' => null,
                'revert_plan_id' => null,
                'revert_period_id' => null,
                'revert_requested_by' => null,
            ])->save();
            $period = $periodId ? Period::query()->where('plan_id', $plan->id)->find($periodId) : $plan->preferredPeriod();
            $tenant->forceFill(['billing_mode' => Tenant::BILLING_PLAN, 'plan_id' => $plan->id, 'period_id' => $period?->id])->save();

            FiscalAdminAudit::record('commercial.reverted_to_plan', $tenant->id, $contract, [
                'before' => $before,
                'after' => $this->snapshot($contract, $tenant),
                'cancelled_charges' => $open->pluck('id')->all(),
            ]);

            return $tenant;
        });
    }

    /**
     * Opção "Emitir NFS-e para este cliente SaaS", válida para plano tradicional ou contrato.
     * Só liga com o emitente da ABrasil apto e o tomador completo; vale a partir de agora.
     */
    public function setSaasInvoice(Tenant $tenant, bool $enabled): Tenant
    {
        if ($enabled && ! $tenant->saas_invoice_enabled) {
            $problems = array_values(array_filter([
                $this->saasInvoices->blocker(AdminFiscalSetting::current()),
                ...$this->saasInvoices->receiverProblems($tenant),
            ]));

            if ($problems !== []) {
                throw ValidationException::withMessages(['saas_invoice_enabled' => $problems]);
            }
        }

        if ((bool) $tenant->saas_invoice_enabled === $enabled) {
            return $tenant;
        }

        $tenant->forceFill(['saas_invoice_enabled' => $enabled, 'saas_invoice_enabled_at' => $enabled ? now() : null])->save();
        FiscalAdminAudit::record('commercial.saas_invoice_toggled', $tenant->id, $tenant, ['before' => ! $enabled, 'after' => $enabled]);

        return $tenant;
    }

    // ---- Ciclos e cobranças --------------------------------------------

    /** Gera as cobranças cujo vencimento entrou na antecedência e envia as faturas. */
    public function generateDueCharges(?Carbon $today = null): int
    {
        $limit = ($today ?? now())->copy()->startOfDay()->addDays(self::INVOICE_LEAD_DAYS);
        $generated = 0;

        TenantCommercialContract::query()
            ->where('active', true)
            ->whereDate('next_due_date', '<=', $limit->toDateString())
            ->whereHas('tenant', fn ($query) => $query->where('billing_mode', Tenant::BILLING_CUSTOM))
            ->orderBy('id')
            ->chunkById(100, function ($contracts) use ($limit, &$generated) {
                foreach ($contracts as $contract) {
                    // Falha em um contrato não interrompe os demais.
                    try {
                        for ($i = 0; $i < self::MAX_CATCH_UP; $i++) {
                            $charge = $this->generateCharge($contract, $limit);
                            if (! $charge) {
                                break;
                            }
                            $generated++;
                            // Efí ativa: boleto/Bolix da competência antes da fatura (nunca com recorrência no cartão ativa).
                            app(SaasPaymentService::class)->prepareCollection($charge);
                            $this->sendInvoice($charge);
                        }
                    } catch (\Throwable $exception) {
                        report($exception);
                    }
                }
            });

        return $generated;
    }

    /**
     * Cobrança do próximo ciclo. A trava no contrato serializa execuções simultâneas e o índice
     * único (contrato, início do ciclo) garante uma cobrança por ciclo mesmo em reexecução.
     */
    public function generateCharge(TenantCommercialContract $contract, ?Carbon $limit = null): ?TenantSubscriptionCharge
    {
        $limit ??= now()->startOfDay()->addDays(self::INVOICE_LEAD_DAYS);

        return DB::transaction(function () use ($contract, $limit) {
            $contract = TenantCommercialContract::query()->lockForUpdate()->find($contract->id);

            if (! $contract?->active || $contract->next_due_date->gt($limit)) {
                return null;
            }

            // Volta ao plano agendada: nenhum ciclo do contrato começa na data efetiva ou depois dela.
            if ($contract->revert_scheduled_for && $contract->next_due_date->gte($contract->revert_scheduled_for)) {
                return null;
            }

            $dueDate = $contract->next_due_date->copy();
            [$cycleStart, $cycleEnd] = $contract->cycleFor($dueDate);

            $charge = TenantSubscriptionCharge::query()->firstOrCreate(
                ['tenant_commercial_contract_id' => $contract->id, 'cycle_start' => $cycleStart->toDateString()],
                [
                    'tenant_id' => $contract->tenant_id,
                    'cycle_end' => $cycleEnd->toDateString(),
                    'due_date' => $dueDate->toDateString(),
                    'fiscal_scheduled_for' => $contract->fiscalScheduleFor($dueDate)->toDateString(),
                    'amount' => $contract->amount,
                    'status' => TenantSubscriptionCharge::STATUS_PENDING,
                ],
            );

            $contract->forceFill(['next_due_date' => $cycleEnd->copy()->addDay()->toDateString()])->save();

            if ($charge->wasRecentlyCreated) {
                FiscalAdminAudit::record('commercial.charge_created', $contract->tenant_id, $charge, [
                    'due_date' => $charge->due_date->toDateString(),
                    'cycle' => [$charge->cycle_start->toDateString(), $charge->cycle_end->toDateString()],
                    'amount' => (string) $charge->amount,
                ]);
            }

            $this->syncCoverage($contract);

            return $charge->wasRecentlyCreated ? $charge : null;
        });
    }

    /**
     * Quita a cobrança (pagamento confirmado na Efí ou registro manual do RootAdmin). Idempotente: o mesmo
     * pagamento repetido (webhook reenviado) não altera nada; um segundo pagamento é recusado.
     */
    public function registerPayment(TenantSubscriptionCharge $charge, string $amount, string $method, ?Payment $payment = null, ?string $note = null): TenantSubscriptionCharge
    {
        return DB::transaction(function () use ($charge, $amount, $method, $payment, $note) {
            $charge = TenantSubscriptionCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if ($charge->status === TenantSubscriptionCharge::STATUS_PAID) {
                if ($payment && (int) $charge->payment_id === (int) $payment->id) {
                    return $charge;
                }

                throw ValidationException::withMessages(['charge' => 'Esta cobrança já está paga.']);
            }
            if ($charge->status !== TenantSubscriptionCharge::STATUS_PENDING) {
                throw ValidationException::withMessages(['charge' => 'Só cobranças em aberto podem receber pagamento.']);
            }

            $paid = $this->money($amount);
            if ($this->cents($paid) < $this->cents((string) $charge->amount)) {
                throw ValidationException::withMessages(['amount' => 'O valor pago é menor que o valor da cobrança.']);
            }

            $charge->forceFill([
                'status' => TenantSubscriptionCharge::STATUS_PAID,
                'paid_at' => now(),
                'paid_amount' => $paid,
                'payment_method' => $method,
                'payment_id' => $payment?->id,
                'payment_note' => filled($note) ? Str::limit(trim($note), 255, '') : null,
            ])->save();

            $this->syncCoverage($charge->contract);

            FiscalAdminAudit::record('commercial.charge_paid', $charge->tenant_id, $charge, [
                'method' => $method,
                'amount' => $paid,
                'payment_id' => $payment?->id,
            ]);

            return $charge;
        });
    }

    /** Estorno: a cobrança fica registrada como estornada e o acesso volta ao vencimento dela. */
    public function refund(TenantSubscriptionCharge $charge, string $reason): TenantSubscriptionCharge
    {
        return DB::transaction(function () use ($charge, $reason) {
            $charge = TenantSubscriptionCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if ($charge->status === TenantSubscriptionCharge::STATUS_REFUNDED) {
                return $charge;
            }
            if ($charge->status !== TenantSubscriptionCharge::STATUS_PAID) {
                throw ValidationException::withMessages(['charge' => 'Só cobranças pagas podem ser estornadas.']);
            }

            $charge->forceFill([
                'status' => TenantSubscriptionCharge::STATUS_REFUNDED,
                'refunded_at' => now(),
                'refund_reason' => Str::limit(trim($reason), 255, ''),
            ])->save();

            $this->syncCoverage($charge->contract);

            FiscalAdminAudit::record('commercial.charge_refunded', $charge->tenant_id, $charge, [
                'amount' => (string) $charge->paid_amount,
                'reason' => $charge->refund_reason,
                'has_fiscal_document' => $this->hasActiveDocument($charge),
            ]);

            return $charge;
        });
    }

    /** Operação explícita e auditada: muda o valor de uma cobrança em aberto ainda sem nota ativa. */
    public function adjustOpenCharge(TenantSubscriptionCharge $charge, string $amount, string $reason): TenantSubscriptionCharge
    {
        return DB::transaction(function () use ($charge, $amount, $reason) {
            $charge = TenantSubscriptionCharge::query()->lockForUpdate()->findOrFail($charge->id);

            if (! $charge->isOpen() || $this->hasActiveDocument($charge)) {
                throw ValidationException::withMessages(['amount' => 'Só cobranças em aberto e sem NFS-e ativa podem ter o valor ajustado.']);
            }

            $before = (string) $charge->amount;
            $charge->forceFill(['amount' => $this->money($amount)])->save();

            FiscalAdminAudit::record('commercial.charge_adjusted', $charge->tenant_id, $charge, [
                'before' => $before,
                'after' => (string) $charge->amount,
                'reason' => Str::limit(trim($reason), 255, ''),
            ]);

            return $charge;
        });
    }

    /** Cobrança em aberto mais antiga do contrato ativo (alvo do PIX do tenant). */
    public function openChargeFor(Tenant $tenant): ?TenantSubscriptionCharge
    {
        if (! $tenant->hasCustomContract()) {
            return null;
        }

        return TenantSubscriptionCharge::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', TenantSubscriptionCharge::STATUS_PENDING)
            ->orderBy('due_date')
            ->orderBy('id')
            ->first();
    }

    /**
     * Mantém `tenants.expires_at` coerente com as cobranças: vencimento da mais antiga não paga
     * (em aberto ou estornada); sem pendência, o fim do último ciclo pago; sem cobrança, o primeiro
     * vencimento do contrato. Cobranças canceladas não contam.
     */
    public function syncCoverage(TenantCommercialContract $contract): void
    {
        $tenant = Tenant::query()->findOrFail($contract->tenant_id);
        $charges = $contract->charges()
            ->where('status', '!=', TenantSubscriptionCharge::STATUS_CANCELLED)
            ->orderBy('cycle_start')
            ->get();

        $unpaid = $charges->first(fn (TenantSubscriptionCharge $charge) => $charge->status !== TenantSubscriptionCharge::STATUS_PAID);
        $expiresAt = match (true) {
            $unpaid !== null => $unpaid->due_date->copy(),
            $charges->isNotEmpty() => $charges->last()->coverageUntil(),
            default => $contract->next_due_date->copy(),
        };

        // Na ativação, o período já pago pelo plano é preservado (o primeiro vencimento já é posterior a ele).
        $tenant->forceFill(['expires_at' => $expiresAt->startOfDay()]);
        $tenant->subscription_status = $tenant->persistedSubscriptionStatus();
        $tenant->save();
    }

    // ---- Emissão fiscal programada -------------------------------------

    /**
     * Enfileira a NFS-e dos ciclos cuja data fiscal chegou, sem olhar o pagamento. Só entram tenants
     * com a opção ligada antes da data fiscal (o histórico não é emitido) e cobranças sem documento;
     * rejeição e falha ficam para reprocessamento manual. A reserva por `fiscal_queued_at` é atômica;
     * reserva sem documento após 1 hora é refeita (job perdido).
     */
    public function queueScheduledInvoices(?Carbon $today = null): int
    {
        $today = ($today ?? now())->copy()->startOfDay();
        $queued = 0;

        TenantSubscriptionCharge::query()
            ->whereIn('status', [TenantSubscriptionCharge::STATUS_PENDING, TenantSubscriptionCharge::STATUS_PAID])
            ->whereNotNull('fiscal_scheduled_for')
            ->whereDate('fiscal_scheduled_for', '<=', $today->toDateString())
            ->where(fn ($query) => $query->whereNull('fiscal_queued_at')->orWhere('fiscal_queued_at', '<=', now()->subHour()))
            ->whereDoesntHave('fiscalDocuments')
            ->with('tenant')
            ->orderBy('id')
            ->chunkById(100, function ($charges) use (&$queued) {
                foreach ($charges as $charge) {
                    if (! $this->isAutomaticallyInvoiceable($charge)) {
                        continue;
                    }

                    $claimed = TenantSubscriptionCharge::query()
                        ->whereKey($charge->id)
                        ->where(fn ($query) => $query->whereNull('fiscal_queued_at')->orWhere('fiscal_queued_at', '<=', now()->subHour()))
                        ->update(['fiscal_queued_at' => now()]);

                    if ($claimed === 1) {
                        EmitSaasChargeInvoice::dispatch($charge->id);
                        $queued++;
                    }
                }
            });

        return $queued;
    }

    public function isAutomaticallyInvoiceable(TenantSubscriptionCharge $charge): bool
    {
        $tenant = $charge->tenant;

        return $tenant !== null
            && $tenant->saas_invoice_enabled
            && $tenant->saas_invoice_enabled_at !== null
            && in_array($charge->status, [TenantSubscriptionCharge::STATUS_PENDING, TenantSubscriptionCharge::STATUS_PAID], true)
            && $charge->fiscal_scheduled_for !== null
            && ! $charge->fiscal_scheduled_for->isFuture()
            && $charge->fiscal_scheduled_for->gte($tenant->saas_invoice_enabled_at->copy()->startOfDay());
    }

    // ---- Comunicação ---------------------------------------------------

    /**
     * Fatura do ciclo. Uma falha fica registrada e é tentada de novo pelo agendador (até 3 vezes);
     * nunca cancela a cobrança nem toca na nota. Se já houver NFS-e autorizada, os links vão junto.
     */
    public function sendInvoice(TenantSubscriptionCharge $charge, ?int $userId = null): TenantSubscriptionChargeDelivery
    {
        $charge->loadMissing('tenant', 'contract', 'latestFiscalDocument');
        $email = trim((string) $charge->tenant->email);
        $document = $charge->latestFiscalDocument?->status === FiscalDocument::STATUS_AUTHORIZED ? $charge->latestFiscalDocument : null;

        $delivery = $this->deliver($charge, TenantSubscriptionChargeDelivery::KIND_INVOICE, $email, $userId, function () use ($charge, $email, $document) {
            Mail::to($email)->send(new SaasChargeInvoiceMail(
                $charge,
                AdminFiscalSetting::current(),
                $document ? FiscalDocumentLinks::forSaas($document) : null,
                $this->paymentInstructions($charge),
            ));
        });

        if ($delivery->status === TenantSubscriptionChargeDelivery::STATUS_SENT && ! $charge->invoice_sent_at) {
            $charge->forceFill(['invoice_sent_at' => now()])->save();
        }

        return $delivery;
    }

    /** Reenvia as faturas que ainda não saíram (falha anterior de SMTP), com limite de tentativas. */
    public function retryPendingInvoices(): int
    {
        $sent = 0;

        TenantSubscriptionCharge::query()
            ->where('status', TenantSubscriptionCharge::STATUS_PENDING)
            ->whereNull('invoice_sent_at')
            ->whereHas('deliveries', fn ($query) => $query->where('kind', TenantSubscriptionChargeDelivery::KIND_INVOICE))
            // Uma tentativa por hora no máximo: não repete na mesma execução que acabou de falhar.
            ->whereDoesntHave('deliveries', fn ($query) => $query->where('kind', TenantSubscriptionChargeDelivery::KIND_INVOICE)->where('created_at', '>', now()->subHour()))
            ->orderBy('id')
            ->get()
            ->each(function (TenantSubscriptionCharge $charge) use (&$sent) {
                $failed = $charge->deliveries()->where('kind', TenantSubscriptionChargeDelivery::KIND_INVOICE)->where('status', TenantSubscriptionChargeDelivery::STATUS_FAILED)->count();

                if ($failed < self::INVOICE_MAX_FAILED_ATTEMPTS
                    && $this->sendInvoice($charge)->status === TenantSubscriptionChargeDelivery::STATUS_SENT) {
                    $sent++;
                }
            });

        return $sent;
    }

    /**
     * Comunicação fiscal: links assinados e temporários para PDF e XML de uma NFS-e autorizada.
     * Reenvio administrativo só reenvia e-mail; nunca emite outra nota.
     */
    public function sendFiscalNotice(AdminFiscalDocument $document, ?int $userId = null): ?AdminFiscalDocumentDelivery
    {
        if ($document->status !== FiscalDocument::STATUS_AUTHORIZED) {
            return null;
        }

        $document->loadMissing('tenant', 'subscriptionCharge.contract');
        $email = trim((string) $document->tenant?->email);
        $status = AdminFiscalDocumentDelivery::STATUS_SENT;
        $error = null;

        try {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('E-mail do cliente inválido para envio da nota.');
            }

            Mail::to($email)->send(new SaasFiscalNoticeMail($document, AdminFiscalSetting::current(), FiscalDocumentLinks::forSaas($document)));
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar a comunicação fiscal do SaaS.', ['admin_fiscal_document_id' => $document->id, 'exception' => $exception::class]);
            $status = AdminFiscalDocumentDelivery::STATUS_FAILED;
            $error = Str::limit($exception->getMessage(), 1000, '');
        }

        $delivery = AdminFiscalDocumentDelivery::query()->create([
            'admin_fiscal_document_id' => $document->id,
            'email' => $email ?: null,
            'status' => $status,
            'error' => $error,
            'sent_by' => $userId,
            'created_at' => now(),
        ]);

        if ($charge = $document->subscriptionCharge) {
            TenantSubscriptionChargeDelivery::query()->create([
                'tenant_subscription_charge_id' => $charge->id,
                'kind' => TenantSubscriptionChargeDelivery::KIND_FISCAL,
                'email' => $email ?: null,
                'status' => $status,
                'error' => $error,
                'sent_by' => $userId,
                'created_at' => now(),
            ]);
        }

        FiscalAdminAudit::record('saas.fiscal_notice_sent', $document->tenant_id, $document, ['status' => $status]);

        return $delivery;
    }

    public function paymentInstructions(?TenantSubscriptionCharge $charge = null): string
    {
        // Efí: recorrência no cartão, ou boleto/Bolix e Pix já gerados para a competência.
        if ($charge && ($efi = app(SaasPaymentService::class)->instructionsFor($charge))) {
            return $efi;
        }

        $configured = trim((string) config('services.saas_billing.payment_instructions'));

        return $configured !== ''
            ? $configured
            : 'Pague por Pix, boleto ou cartão acessando o VetorOS em '.rtrim((string) config('app.url'), '/').'/subscription ou fale com o atendimento da ABrasil Sistemas.';
    }

    // ---- Apoio ---------------------------------------------------------

    public function hasActiveDocument(TenantSubscriptionCharge $charge): bool
    {
        return AdminFiscalDocument::query()
            ->where('tenant_subscription_charge_id', $charge->id)
            ->whereIn('status', [FiscalDocument::STATUS_PROCESSING, FiscalDocument::STATUS_CONTINGENCY, FiscalDocument::STATUS_AUTHORIZED])
            ->exists();
    }

    private function deliver(TenantSubscriptionCharge $charge, string $kind, string $email, ?int $userId, callable $send): TenantSubscriptionChargeDelivery
    {
        $status = TenantSubscriptionChargeDelivery::STATUS_SENT;
        $error = null;

        try {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \InvalidArgumentException('E-mail do cliente inválido para envio da fatura.');
            }

            $send();
        } catch (\Throwable $exception) {
            Log::warning('Falha ao enviar a fatura do contrato SaaS.', ['charge_id' => $charge->id, 'exception' => $exception::class]);
            $status = TenantSubscriptionChargeDelivery::STATUS_FAILED;
            $error = Str::limit($exception->getMessage(), 1000, '');
        }

        return TenantSubscriptionChargeDelivery::query()->create([
            'tenant_subscription_charge_id' => $charge->id,
            'kind' => $kind,
            'email' => $email ?: null,
            'status' => $status,
            'error' => $error,
            'sent_by' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Limite inferior do próximo vencimento: fim do último ciclo cobrado (não cancelado) e, na
     * ativação, também o fim do período já pago pelo plano tradicional.
     */
    private function coveredUntil(Tenant $tenant, ?TenantCommercialContract $contract, bool $activating): ?Carbon
    {
        $lastCharge = $contract?->charges()
            ->where('status', '!=', TenantSubscriptionCharge::STATUS_CANCELLED)
            ->orderByDesc('cycle_start')
            ->first();

        $candidates = array_filter([
            $lastCharge?->coverageUntil(),
            $activating && $tenant->expires_at?->isFuture() ? $tenant->expires_at->copy()->startOfDay() : null,
        ]);

        return $candidates === [] ? null : collect($candidates)->max();
    }

    private function revertiblePlan(int $planId): Plan
    {
        $plan = Plan::query()->with('periods')->find($planId);

        if (! $plan || $plan->isTrial() || $plan->billingMonths() <= 0) {
            throw ValidationException::withMessages(['plan_id' => 'Selecione um plano pago com periodicidade definida.']);
        }

        return $plan;
    }

    private function activeContractOrFail(Tenant $tenant): TenantCommercialContract
    {
        $contract = TenantCommercialContract::query()->lockForUpdate()->firstWhere('tenant_id', $tenant->id);

        if (! $tenant->hasCustomContract() || ! $contract?->active) {
            throw ValidationException::withMessages(['plan_id' => 'A empresa já está no plano tradicional.']);
        }

        return $contract;
    }

    private function snapshot(TenantCommercialContract $contract, Tenant $tenant): array
    {
        return [
            'billing_mode' => $tenant->billing_mode,
            'plan_id' => $tenant->plan_id,
            'amount' => (string) $contract->amount,
            'billing_months' => $contract->billing_months,
            'start_date' => $contract->start_date?->toDateString(),
            'next_due_date' => $contract->next_due_date?->toDateString(),
            'anchor_day' => $contract->anchor_day,
            'revert_scheduled_for' => $contract->revert_scheduled_for?->toDateString(),
            'revert_plan_id' => $contract->revert_plan_id,
            'sales_rep' => $contract->sales_rep,
            'notes' => $contract->notes,
            'active' => $contract->active,
            'expires_at' => $tenant->expires_at?->toDateString(),
        ];
    }

    /** Valor monetário como string decimal com 2 casas (sem float). */
    private function money(string|float|int $value): string
    {
        $value = str_replace(',', '.', trim((string) $value));

        if (! preg_match('/^\d{1,10}(\.\d{1,2})?$/', $value)) {
            throw ValidationException::withMessages(['amount' => 'Informe um valor monetário válido.']);
        }

        [$integer, $fraction] = array_pad(explode('.', $value), 2, '');

        return (ltrim($integer, '0') ?: '0').'.'.str_pad($fraction, 2, '0');
    }

    /** Centavos inteiros de um decimal com 2 casas, para comparar sem float. */
    private function cents(string $value): int
    {
        [$integer, $fraction] = array_pad(explode('.', $this->money($value)), 2, '00');

        return (int) $integer * 100 + (int) $fraction;
    }
}
