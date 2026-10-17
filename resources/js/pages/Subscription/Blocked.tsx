import EfiCheckout, { type Checkout, type CheckoutResult } from '@/components/Payment/EfiCheckout';
import SubscriptionPaymentSuccess from '@/components/Payment/SubscriptionPaymentSuccess';
import { Head, Link, router } from '@inertiajs/react';
import moment from 'moment';
import { useState } from 'react';

type Plan = { id: number; name: string; description: string | null; value: string; billing_months: number; current: boolean };
type Charge = { contract_number: string; amount: string; due_date: string; cycle_start: string; cycle_end: string; periodicity: string };
type Status = {
    state: 'active' | 'due_soon' | 'due_today' | 'grace' | 'blocked';
    label: string;
    billing_mode: 'plan' | 'custom';
    plan_name: string | null;
    expires_at: string | null;
    grace_until: string | null;
    days_until_due: number | null;
    reason: string | null;
};

const money = (value: string | number) => Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const date = (value: string | null) => (value ? moment(value).format('DD/MM/YYYY') : '-');
const periodicity = (months: number) => (months === 1 ? 'Mensal' : months === 6 ? 'Semestral' : months === 12 ? 'Anual' : `${months} meses`);

const titles: Record<Status['state'], string> = {
    active: 'Minha assinatura',
    due_soon: 'Assinatura perto do vencimento',
    due_today: 'Assinatura vence hoje',
    grace: 'Assinatura vencida',
    blocked: 'Acesso suspenso',
};

const tones: Record<Status['state'], string> = {
    active: 'bg-emerald-100 text-emerald-800',
    due_soon: 'bg-sky-100 text-sky-800',
    due_today: 'bg-red-100 text-red-800',
    grace: 'bg-amber-100 text-amber-900',
    blocked: 'bg-red-100 text-red-800',
};

/**
 * Minha assinatura / bloqueio (VETOR-SAAS-EFI-04): situação, motivo, vencimento, valor e o checkout Efí.
 * Plano tradicional: renovação pelo plano. Contrato personalizado: só a cobrança contratual (sem
 * catálogo; o valor vem do servidor). A liberação só vem da confirmação no servidor.
 */
export default function Blocked({
    plans,
    tenant,
    charge,
    status,
    checkout,
    pending_payment,
}: {
    plans: Plan[];
    tenant: { name: string; company: string | null };
    charge?: Charge | null;
    status: Status;
    checkout: Checkout;
    pending_payment?: CheckoutResult | null;
}) {
    const current = plans.find((plan) => plan.current) ?? null;
    const [selectedPlan, setSelectedPlan] = useState<number | null>(current?.id ?? (plans.length === 1 ? plans[0].id : null));
    const [paymentConfirmed, setPaymentConfirmed] = useState(false);
    const unavailable = checkout.methods.length === 0;
    const isCustom = status.billing_mode === 'custom';
    const blocked = status.state === 'blocked';
    const selected = plans.find((plan) => plan.id === selectedPlan) ?? null;
    const payable = isCustom ? Boolean(charge) : plans.length > 0;

    const handleLogout = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(route('logout'));
    };

    // replace: a tela de bloqueio não fica no histórico, e a nova carga relê a assinatura no servidor.
    const handleContinue = () => window.location.replace(route('app.dashboard'));

    return (
        <div className="flex min-h-screen flex-col justify-center bg-gray-100 px-4 py-10 sm:px-6 lg:px-8">
            <Head title={titles[status.state]} />
            <SubscriptionPaymentSuccess open={paymentConfirmed} onContinue={handleContinue} />

            <div className="text-center sm:mx-auto sm:w-full sm:max-w-lg">
                <h1 className="text-2xl font-extrabold text-gray-900 sm:text-3xl">{titles[status.state]}</h1>
                <p className="mt-2 text-sm text-gray-600">
                    {tenant.company || tenant.name}
                    {status.plan_name && !isCustom && <> · plano {status.plan_name}</>}
                </p>
            </div>

            <main className="mt-6 sm:mx-auto sm:w-full sm:max-w-lg">
                <div className="space-y-4 bg-white px-4 py-6 shadow sm:rounded-lg sm:px-8">
                    <section aria-labelledby="subscription-status" className="space-y-2 text-sm text-gray-700">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <h2 id="subscription-status" className="font-semibold text-gray-900">
                                Situação
                            </h2>
                            <span className={`rounded-full px-3 py-0.5 text-xs font-semibold ${tones[status.state]}`}>{status.label}</span>
                        </div>
                        {status.reason && <p>{status.reason}</p>}
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
                            <dt className="text-gray-500">Vencimento</dt>
                            <dd className="text-right font-medium">{date(status.expires_at)}</dd>
                            {(status.state === 'grace' || blocked) && status.grace_until && (
                                <>
                                    <dt className="text-gray-500">Carência até</dt>
                                    <dd className="text-right font-medium">{date(status.grace_until)}</dd>
                                </>
                            )}
                            <dt className="text-gray-500">Contratação</dt>
                            <dd className="text-right font-medium">{isCustom ? 'Contrato personalizado' : 'Plano'}</dd>
                        </dl>
                    </section>

                    {charge && (
                        <section aria-labelledby="subscription-charge" className="rounded-lg border border-gray-300 p-4 text-sm text-gray-700">
                            <h2 id="subscription-charge" className="mb-2 font-semibold text-gray-900">
                                Cobrança em aberto
                            </h2>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-1">
                                <dt className="text-gray-500">Contrato</dt>
                                <dd className="text-right">{charge.contract_number}</dd>
                                <dt className="text-gray-500">Periodicidade</dt>
                                <dd className="text-right">{charge.periodicity}</dd>
                                <dt className="text-gray-500">Competência</dt>
                                <dd className="text-right">
                                    {date(charge.cycle_start)} a {date(charge.cycle_end)}
                                </dd>
                                <dt className="text-gray-500">Vencimento</dt>
                                <dd className="text-right">{date(charge.due_date)}</dd>
                                <dt className="text-gray-500">Valor</dt>
                                <dd className="text-right text-base font-bold text-gray-900">{money(charge.amount)}</dd>
                            </dl>
                        </section>
                    )}

                    {!isCustom && plans.length > 0 && (
                        <fieldset className="space-y-3">
                            <legend className="mb-1 text-sm font-semibold text-gray-900">{current ? 'Renovação' : 'Escolha a renovação'}</legend>
                            {plans.map((plan) => (
                                <label
                                    key={plan.id}
                                    className={`flex cursor-pointer items-center justify-between gap-3 rounded-lg border p-3 ${selectedPlan === plan.id ? 'border-indigo-500 bg-indigo-50 ring-2 ring-indigo-500' : 'border-gray-300 hover:bg-gray-50'}`}
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
                                            <span className="block text-sm font-medium text-gray-900">
                                                {plan.name}{' '}
                                                {plan.current && <span className="text-xs font-normal text-indigo-700">(plano atual)</span>}
                                            </span>
                                            <span className="block text-xs text-gray-500">
                                                {periodicity(plan.billing_months)}
                                                {plan.description ? ` · ${plan.description}` : ''}
                                            </span>
                                        </span>
                                    </span>
                                    <span className="text-sm font-bold whitespace-nowrap text-gray-900">{money(plan.value)}</span>
                                </label>
                            ))}
                        </fieldset>
                    )}

                    {isCustom && !charge && (
                        <p className="rounded-md border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-800">
                            Nenhuma cobrança em aberto no seu contrato. A próxima fatura chega por e-mail antes do vencimento.
                        </p>
                    )}

                    {payable &&
                        (unavailable ? (
                            <p role="status" className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                                {checkout.unavailable_message ??
                                    'Pagamento online indisponível no momento. Fale com o atendimento da ABrasil Sistemas.'}
                            </p>
                        ) : isCustom ? (
                            <EfiCheckout checkout={checkout} initialResult={pending_payment} onConfirmed={() => setPaymentConfirmed(true)} />
                        ) : selected ? (
                            <div className="space-y-2">
                                <p className="text-sm text-gray-600">
                                    Pagar {selected.name} ({periodicity(selected.billing_months).toLowerCase()}):{' '}
                                    <strong>{money(selected.value)}</strong>
                                </p>
                                <EfiCheckout
                                    key={selected.id}
                                    checkout={checkout}
                                    planId={selected.id}
                                    initialResult={selected.current ? pending_payment : null}
                                    onConfirmed={() => setPaymentConfirmed(true)}
                                />
                            </div>
                        ) : (
                            <p className="text-sm text-gray-600">Selecione a renovação para ver os meios de pagamento.</p>
                        ))}

                    {!payable && !isCustom && (
                        <p className="text-sm text-gray-600">Fale com o atendimento da ABrasil Sistemas para regularizar a assinatura.</p>
                    )}

                    {payable && !unavailable && (
                        <p className="text-xs text-gray-500">
                            Pague por Pix, boleto ou cartão. A liberação é automática após a confirmação do banco; não é preciso enviar comprovante.
                        </p>
                    )}
                </div>

                <div className="mt-6 flex flex-col items-center gap-3 text-sm sm:flex-row sm:justify-center">
                    {!blocked && (
                        <Link href={route('app.dashboard')} className="font-medium text-indigo-600 underline hover:text-indigo-800">
                            Voltar ao sistema
                        </Link>
                    )}
                    <form onSubmit={handleLogout}>
                        <button type="submit" className="font-medium text-gray-500 underline hover:text-gray-900">
                            Sair da conta
                        </button>
                    </form>
                </div>
            </main>
        </div>
    );
}
