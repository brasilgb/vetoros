import SubscriptionPanel, { type SubscriptionData, type SubscriptionStatus } from '@/components/Payment/SubscriptionPanel';
import SubscriptionPaymentSuccess from '@/components/Payment/SubscriptionPaymentSuccess';
import { Head, Link, router } from '@inertiajs/react';
import { useState } from 'react';

const titles: Record<SubscriptionStatus['state'], string> = {
    active: 'Minha assinatura',
    due_soon: 'Assinatura perto do vencimento',
    due_today: 'Assinatura vence hoje',
    grace: 'Assinatura vencida',
    blocked: 'Acesso suspenso',
};

/**
 * Tela de bloqueio (VETOR-SAAS-EFI-04): o único lugar que a empresa bloqueada alcança para pagar.
 * Fora do bloqueio, o mesmo painel fica no bloco "Licença de uso do sistema" de Sistema e módulos.
 */
export default function Blocked(props: SubscriptionData) {
    const { tenant, status } = props;
    const [paymentConfirmed, setPaymentConfirmed] = useState(false);
    const [showRenewal, setShowRenewal] = useState(false);
    const isCustom = status.billing_mode === 'custom';
    const blocked = status.state === 'blocked';

    const handleLogout = (e: React.FormEvent) => {
        e.preventDefault();
        router.post(route('logout'));
    };

    // replace: a tela de bloqueio não fica no histórico, e a nova carga relê a assinatura no servidor.
    const handleContinue = () => window.location.replace(route('app.dashboard'));

    return (
        <div className="bg-muted flex min-h-screen flex-col justify-center px-4 py-10 sm:px-6 lg:px-8">
            <Head title={titles[status.state]} />
            <SubscriptionPaymentSuccess open={paymentConfirmed} onContinue={handleContinue} />

            <div className="text-center sm:mx-auto sm:w-full sm:max-w-lg">
                <h1 className="text-2xl font-extrabold sm:text-3xl">{titles[status.state]}</h1>
                <p className="text-muted-foreground mt-2 text-sm">
                    {tenant.company || tenant.name}
                    {status.plan_name && !isCustom && <> · plano {status.plan_name}</>}
                </p>
            </div>

            <main className="mt-6 sm:mx-auto sm:w-full sm:max-w-lg">
                <div className="bg-card px-4 py-6 shadow sm:rounded-lg sm:px-8">
                    {blocked && !showRenewal ? (
                        <div className="space-y-4 text-center">
                            <p className="text-muted-foreground">{status.reason}</p>
                            <button
                                type="button"
                                onClick={() => setShowRenewal(true)}
                                className="bg-primary text-primary-foreground w-full rounded-md px-4 py-3 font-semibold"
                            >
                                Renovar assinatura
                            </button>
                        </div>
                    ) : (
                        <SubscriptionPanel data={props} onConfirmed={() => setPaymentConfirmed(true)} />
                    )}
                </div>

                <div className="mt-6 flex flex-col items-center gap-3 text-sm sm:flex-row sm:justify-center">
                    {!blocked && (
                        <Link href={route('app.dashboard')} className="text-primary font-medium underline">
                            Voltar ao sistema
                        </Link>
                    )}
                    <form onSubmit={handleLogout}>
                        <button type="submit" className="text-muted-foreground hover:text-foreground font-medium underline">
                            Sair da conta
                        </button>
                    </form>
                </div>
            </main>
        </div>
    );
}
