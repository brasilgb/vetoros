import EfiCheckout, { type Checkout, type CheckoutResult } from '@/components/Payment/EfiCheckout';
import { Dialog, DialogContent, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { formatBRL } from '@/lib/money';
import { cn } from '@/lib/utils';
import moment from 'moment';
import { useState } from 'react';

export type SubscriptionPlan = {
    id: number;
    name: string;
    description: string | null;
    value: string;
    billing_months: number;
    current: boolean;
    features: string[];
};
export type SubscriptionCharge = {
    contract_number: string;
    amount: string;
    due_date: string;
    cycle_start: string;
    cycle_end: string;
    periodicity: string;
};
export type SubscriptionStatus = {
    state: 'active' | 'due_soon' | 'due_today' | 'grace' | 'blocked';
    label: string;
    billing_mode: 'plan' | 'custom';
    plan_name: string | null;
    expires_at: string | null;
    grace_until: string | null;
    days_until_due: number | null;
    reason: string | null;
};

export type SubscriptionData = {
    tenant: { name: string; company: string | null };
    plans: SubscriptionPlan[];
    charge?: SubscriptionCharge | null;
    status: SubscriptionStatus;
    checkout: Checkout;
    pending_payment?: CheckoutResult | null;
    pending_plan_id?: number | null;
    previous_plan_name?: string | null;
    courtesy_expired?: boolean;
    can_manage_subscription?: boolean;
};

const date = (value: string | null) => (value ? moment(value).format('DD/MM/YYYY') : '-');
export const periodicityLabel = (months: number) =>
    months === 1 ? 'Mensal' : months === 6 ? 'Semestral' : months === 12 ? 'Anual' : `${months} meses`;

export const subscriptionTones: Record<SubscriptionStatus['state'], string> = {
    active: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950 dark:text-emerald-200',
    due_soon: 'bg-sky-100 text-sky-800 dark:bg-sky-950 dark:text-sky-200',
    due_today: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
    grace: 'bg-amber-100 text-amber-900 dark:bg-amber-950 dark:text-amber-200',
    blocked: 'bg-red-100 text-red-800 dark:bg-red-950 dark:text-red-200',
};

/**
 * Situação da assinatura, cobrança/renovação e checkout Efí (VETOR-SAAS-EFI-04). Usado no bloco
 * "Licença de uso do sistema" (Sistema e módulos) e na tela de bloqueio. Plano tradicional: renovação
 * pelo plano. Contrato personalizado: só a cobrança contratual. A liberação vem só do servidor.
 */
export default function SubscriptionPanel({
    data,
    onConfirmed,
    showStatus = true,
}: {
    data: SubscriptionData;
    onConfirmed: () => void;
    /** Esconde o quadro de situação quando quem usa o painel já mostra vencimento e prazo. */
    showStatus?: boolean;
}) {
    const { plans, charge, status, checkout, pending_payment } = data;
    const current = plans.find((plan) => plan.current) ?? null;
    const [selectedPlan, setSelectedPlan] = useState<number | null>(current?.id ?? (plans.length === 1 ? plans[0].id : null));
    const [checkoutOpen, setCheckoutOpen] = useState(Boolean(pending_payment));
    const unavailable = checkout.methods.length === 0 && !pending_payment;
    const isCustom = status.billing_mode === 'custom';
    const blocked = status.state === 'blocked';
    const selected = plans.find((plan) => plan.id === selectedPlan) ?? null;
    const pendingPlan = plans.find((plan) => plan.id === data.pending_plan_id) ?? null;
    const payable = isCustom ? Boolean(charge) : plans.length > 0 || Boolean(pending_payment);

    return (
        <div className="space-y-4 text-sm">
            {showStatus && (
                <section aria-labelledby="subscription-status" className="space-y-2">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 id="subscription-status" className="font-semibold">
                            Situação
                        </h3>
                        <span className={cn('rounded-full px-3 py-0.5 text-xs font-semibold', subscriptionTones[status.state])}>{status.label}</span>
                    </div>
                    {status.reason && <p className="text-muted-foreground">{status.reason}</p>}
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
                        <dt className="text-muted-foreground">Vencimento</dt>
                        <dd className="text-right font-medium">{date(status.expires_at)}</dd>
                        {(status.state === 'grace' || blocked) && status.grace_until && (
                            <>
                                <dt className="text-muted-foreground">Carência até</dt>
                                <dd className="text-right font-medium">{date(status.grace_until)}</dd>
                            </>
                        )}
                        <dt className="text-muted-foreground">Contratação</dt>
                        <dd className="text-right font-medium">{isCustom ? 'Contrato personalizado' : 'Plano'}</dd>
                    </dl>
                </section>
            )}
            {!showStatus && status.reason && <p className="text-muted-foreground">{status.reason}</p>}

            {data.previous_plan_name && !current && !isCustom && (
                <p className="text-muted-foreground">
                    Plano anterior: <strong>{data.previous_plan_name}</strong>.
                </p>
            )}
            {data.courtesy_expired && (
                <p className="rounded-md border p-3">
                    A cortesia terminou. A nova assinatura começa após a confirmação do pagamento, sem cobrança pelo período da cortesia.
                </p>
            )}
            {pending_payment && pendingPlan && selectedPlan !== pendingPlan.id && (
                <p role="status" className="rounded-md border p-3">
                    Há um pagamento do plano {pendingPlan.name} aguardando confirmação. Consulte esse pagamento antes de trocar de plano.
                </p>
            )}

            {charge && (
                <section aria-labelledby="subscription-charge" className="rounded-lg border p-4">
                    <h3 id="subscription-charge" className="mb-2 font-semibold">
                        Cobrança em aberto
                    </h3>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
                        <dt className="text-muted-foreground">Contrato</dt>
                        <dd className="text-right">{charge.contract_number}</dd>
                        <dt className="text-muted-foreground">Periodicidade</dt>
                        <dd className="text-right">{charge.periodicity}</dd>
                        <dt className="text-muted-foreground">Competência</dt>
                        <dd className="text-right">
                            {date(charge.cycle_start)} a {date(charge.cycle_end)}
                        </dd>
                        <dt className="text-muted-foreground">Vencimento</dt>
                        <dd className="text-right">{date(charge.due_date)}</dd>
                        <dt className="text-muted-foreground">Valor</dt>
                        <dd className="text-right text-base font-bold">{formatBRL(charge.amount)}</dd>
                    </dl>
                </section>
            )}

            {!isCustom && plans.length > 0 && (
                <fieldset className="space-y-3">
                    <legend className="mb-1 font-semibold">{current ? 'Renovação' : 'Escolha a renovação'}</legend>
                    {plans.map((plan) => (
                        <label
                            key={plan.id}
                            className={cn(
                                'flex cursor-pointer items-center justify-between gap-3 rounded-lg border p-3',
                                selectedPlan === plan.id ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted',
                            )}
                        >
                            <span className="flex items-center gap-3">
                                <input
                                    type="radio"
                                    name="plan"
                                    value={plan.id}
                                    checked={selectedPlan === plan.id}
                                    onChange={() => setSelectedPlan(plan.id)}
                                    className="h-4 w-4"
                                />
                                <span>
                                    <span className="block font-medium">
                                        {plan.name} {plan.current && <span className="text-primary text-xs font-normal">(plano atual)</span>}
                                    </span>
                                    <span className="text-muted-foreground block text-xs">
                                        {periodicityLabel(plan.billing_months)}
                                        {plan.description ? ` · ${plan.description}` : ''}
                                    </span>
                                    {plan.features.length > 0 && (
                                        <span className="text-muted-foreground block text-xs">Benefícios e limites: {plan.features.join(' · ')}</span>
                                    )}
                                </span>
                            </span>
                            <span className="font-bold whitespace-nowrap">{formatBRL(plan.value)}</span>
                        </label>
                    ))}
                </fieldset>
            )}

            {isCustom && !charge && (
                <p className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-emerald-800 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-200">
                    Nenhuma cobrança em aberto no seu contrato. A próxima fatura chega por e-mail antes do vencimento.
                </p>
            )}

            {payable && data.can_manage_subscription === false && (
                <p className="rounded-md border p-3">Peça ao administrador da empresa para concluir a renovação.</p>
            )}

            {payable &&
                data.can_manage_subscription !== false &&
                (unavailable ? (
                    <p
                        role="status"
                        className="rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-800 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200"
                    >
                        {checkout.unavailable_message ?? 'Pagamento online indisponível no momento. Fale com o atendimento da ABrasil Sistemas.'}
                    </p>
                ) : isCustom || selected || pending_payment ? (
                    <div className="space-y-2">
                        {isCustom || selected ? (
                            <p className="text-muted-foreground">
                                Resumo da contratação:{' '}
                                {isCustom
                                    ? 'cobrança do contrato'
                                    : `${selected?.name} (${periodicityLabel(selected!.billing_months).toLowerCase()})`}
                                , <strong className="text-foreground">{formatBRL(isCustom ? charge!.amount : selected!.value)}</strong>.
                            </p>
                        ) : (
                            <p className="text-muted-foreground">Consulte a cobrança pendente antes de escolher outro plano.</p>
                        )}
                        <button
                            type="button"
                            onClick={() => setCheckoutOpen(true)}
                            className="bg-primary text-primary-foreground w-full rounded-md px-4 py-2 font-medium"
                        >
                            {pending_payment ? 'Aguardando pagamento · consultar novamente' : 'Prosseguir para pagamento'}
                        </button>
                        <Dialog open={checkoutOpen} onOpenChange={setCheckoutOpen}>
                            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-lg">
                                <DialogHeader>
                                    <DialogTitle>Pagamento Efí · {isCustom ? 'contrato' : (selected?.name ?? 'cobrança pendente')}</DialogTitle>
                                </DialogHeader>
                                <EfiCheckout
                                    key={isCustom ? 'contract' : (selected?.id ?? 'pending')}
                                    checkout={checkout}
                                    planId={isCustom ? null : selected?.id}
                                    initialResult={pending_payment}
                                    onConfirmed={() => {
                                        setCheckoutOpen(false);
                                        onConfirmed();
                                    }}
                                />
                            </DialogContent>
                        </Dialog>
                    </div>
                ) : (
                    <p className="text-muted-foreground">Selecione a renovação para ver os meios de pagamento.</p>
                ))}

            {/* Só quando há o que regularizar: com a assinatura em dia, o aviso não faz sentido. */}
            {!payable && !isCustom && status.state !== 'active' && (
                <p className="text-muted-foreground">Fale com o atendimento da ABrasil Sistemas para regularizar a assinatura.</p>
            )}

            {payable && !unavailable && (
                <p className="text-muted-foreground text-xs">
                    Pague por Pix, boleto ou cartão. A liberação é automática após a confirmação do banco; não é preciso enviar comprovante.
                </p>
            )}
        </div>
    );
}
