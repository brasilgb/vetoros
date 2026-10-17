import { Link, usePage } from '@inertiajs/react';
import moment from 'moment';

type SubscriptionAlert = {
    status?: 'due_soon' | 'due_today' | 'grace_period';
    expires_at?: string;
    days_until_due?: number;
    days_overdue?: number;
    grace_days_left?: number;
};

/**
 * Aviso de assinatura (VETOR-SAAS-EFI-04): discreto perto do vencimento, destacado no dia e de atraso na
 * carência (acesso preservado). O botão leva direto à tela "Minha assinatura", com o checkout Efí.
 */
export default function SubscriptionBanner() {
    const { subscription_alert } = usePage<{ subscription_alert?: SubscriptionAlert }>().props;
    const status = subscription_alert?.status;

    if (!status) return null;

    const dueDate = subscription_alert?.expires_at ? moment(subscription_alert.expires_at).format('DD/MM/YYYY') : '';
    const daysLeft = subscription_alert?.grace_days_left ?? 0;
    const days = subscription_alert?.days_until_due ?? 0;

    const tone =
        status === 'due_soon'
            ? 'border border-sky-200 bg-sky-50 text-sky-900'
            : status === 'due_today' || daysLeft <= 1
              ? 'bg-red-600 text-white'
              : 'bg-amber-400 text-gray-800';

    const message =
        status === 'due_soon' ? (
            <>
                Sua assinatura vence em {days} {days === 1 ? 'dia' : 'dias'} ({dueDate}).
            </>
        ) : status === 'due_today' ? (
            <>
                Sua assinatura vence <strong>hoje</strong> ({dueDate}). Pague para manter o acesso sem interrupção.
            </>
        ) : daysLeft === 0 ? (
            <>Sua assinatura venceu em {dueDate}. O acesso será bloqueado a partir de amanhã.</>
        ) : (
            <>
                Sua assinatura venceu em {dueDate}. Você tem mais{' '}
                <strong>
                    {daysLeft} {daysLeft === 1 ? 'dia' : 'dias'}
                </strong>{' '}
                de acesso antes do bloqueio.
            </>
        );

    return (
        <div role={status === 'due_soon' ? 'status' : 'alert'} className={`${tone} mx-4 rounded-md px-4 py-2 shadow-sm`}>
            <div className="mx-auto flex max-w-7xl flex-col items-center justify-between gap-2 sm:flex-row">
                <span className="text-center text-sm font-medium sm:text-left">{message}</span>
                <Link
                    href={route('subscription.show')}
                    className="shrink-0 rounded-full bg-white px-4 py-1 text-xs font-bold text-gray-800 uppercase shadow-sm transition-colors hover:bg-gray-100 focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none"
                >
                    {status === 'due_soon' ? 'Ver assinatura' : 'Pagar agora'}
                </Link>
            </div>
        </div>
    );
}
