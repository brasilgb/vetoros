import SubscriptionPanel, { type SubscriptionData, subscriptionTones } from '@/components/Payment/SubscriptionPanel';
import SubscriptionPaymentSuccess from '@/components/Payment/SubscriptionPaymentSuccess';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { maskCpfCnpj } from '@/Utils/mask';
import moment from 'moment';
import { useState } from 'react';

export type License = {
    plan: string | null;
    holder: string;
    cnpj: string;
    address: string;
    days_remaining: number | null;
};

/** Prazo da licença: dias restantes, vence hoje ou vencida (o servidor manda dias com sinal). */
function deadlineLabel(days?: number | null): { text: string; className: string } | null {
    if (days === null || days === undefined) return null;
    if (days < 0) return { text: `vencida há ${-days} ${-days === 1 ? 'dia' : 'dias'}`, className: 'text-destructive' };
    if (days === 0) return { text: 'vence hoje', className: 'text-destructive' };

    return { text: `${days} ${days === 1 ? 'dia restante' : 'dias restantes'}`, className: 'text-amber-600' };
}

const date = (value: string | null | undefined) => (value ? moment(value).format('DD/MM/YYYY') : null);

/**
 * Licença de uso do sistema (Sistema e módulos, VETOR-UX-MONEY-02).
 *
 * - Sempre visível: plano, prazo, situação e titular.
 * - Detalhes (CNPJ, endereço, vencimento, carência e contratação) em modal.
 * - Pagamento Efí no próprio bloco quando há o que pagar (vencendo, vencida ou cobrança do contrato em
 *   aberto); com a assinatura em dia, a renovação antecipada fica atrás de um botão, também em modal.
 */
export default function LicenseSection({ license, subscription }: { license: License | null; subscription: SubscriptionData | null }) {
    const [detailsOpen, setDetailsOpen] = useState(false);
    const [renewOpen, setRenewOpen] = useState(false);
    const [paymentConfirmed, setPaymentConfirmed] = useState(false);
    const deadline = deadlineLabel(license?.days_remaining);
    const status = subscription?.status;
    const isCustom = status?.billing_mode === 'custom';
    const needsPayment = Boolean(subscription && (status?.state !== 'active' || (isCustom && subscription.charge)));
    const canRenewEarly = Boolean(subscription && !needsPayment && !isCustom && subscription.plans.length > 0);

    const details: [string, string | null][] = [
        ['Titular', license?.holder || null],
        ['CNPJ', license?.cnpj ? maskCpfCnpj(license.cnpj) : null],
        ['Endereço', license?.address || null],
        ['Plano', license?.plan ?? 'Sem plano'],
        ['Contratação', status ? (isCustom ? 'Contrato personalizado' : 'Plano') : null],
        ['Vencimento', date(status?.expires_at)],
        ['Carência até', status && (status.state === 'grace' || status.state === 'blocked') ? date(status.grace_until) : null],
    ];

    const onConfirmed = () => {
        setRenewOpen(false);
        setPaymentConfirmed(true);
    };

    return (
        <section id="licenca" aria-labelledby="licenca-titulo" className="mb-8 scroll-mt-24 space-y-4">
            <SubscriptionPaymentSuccess open={paymentConfirmed} onContinue={() => window.location.reload()} />

            <div className="flex flex-wrap items-start justify-between gap-3">
                <header className="min-w-0">
                    <h3 id="licenca-titulo" className="mb-0.5 flex flex-wrap items-baseline gap-x-2 text-base font-medium">
                        Licença de uso do sistema
                        <span className="font-semibold text-sky-500">{license?.plan ?? 'Sem plano'}</span>
                        {deadline && <span className={deadline.className}>· {deadline.text}</span>}
                        {status && (
                            <span className={cn('rounded-full px-2.5 py-0.5 text-xs font-semibold', subscriptionTones[status.state])}>
                                {status.label}
                            </span>
                        )}
                    </h3>
                    <p className="text-muted-foreground text-sm">Licenciado para {license?.holder || 'a empresa'}</p>
                </header>
                <div className="flex flex-wrap gap-2">
                    {canRenewEarly && (
                        <Button type="button" size="sm" onClick={() => setRenewOpen(true)}>
                            Renovar antecipadamente
                        </Button>
                    )}
                    <Button type="button" size="sm" variant="outline" onClick={() => setDetailsOpen(true)}>
                        Detalhes da licença
                    </Button>
                </div>
            </div>

            {subscription && needsPayment && (
                <div className="max-w-2xl rounded-xl border p-4">
                    <SubscriptionPanel data={subscription} showStatus={false} onConfirmed={onConfirmed} />
                </div>
            )}

            <Dialog open={detailsOpen} onOpenChange={setDetailsOpen}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>Detalhes da licença</DialogTitle>
                        <DialogDescription>{status?.reason ?? 'Dados da licença de uso do sistema.'}</DialogDescription>
                    </DialogHeader>
                    <dl className="grid grid-cols-[auto_1fr] gap-x-4 gap-y-2 text-sm">
                        {details
                            .filter(([, value]) => value)
                            .map(([label, value]) => (
                                <div key={label} className="contents">
                                    <dt className="text-muted-foreground">{label}</dt>
                                    <dd className="font-medium break-words">{value}</dd>
                                </div>
                            ))}
                    </dl>
                    {isCustom && !subscription?.charge && (
                        <p className="text-muted-foreground text-sm">
                            Nenhuma cobrança em aberto no seu contrato. A próxima fatura chega por e-mail antes do vencimento.
                        </p>
                    )}
                </DialogContent>
            </Dialog>

            {subscription && canRenewEarly && (
                <Dialog open={renewOpen} onOpenChange={setRenewOpen}>
                    <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-xl">
                        <DialogHeader>
                            <DialogTitle>Renovar antecipadamente</DialogTitle>
                            <DialogDescription>A nova vigência começa ao fim da atual. Pague por Pix, boleto ou cartão.</DialogDescription>
                        </DialogHeader>
                        <SubscriptionPanel data={subscription} showStatus={false} onConfirmed={onConfirmed} />
                    </DialogContent>
                </Dialog>
            )}
        </section>
    );
}
