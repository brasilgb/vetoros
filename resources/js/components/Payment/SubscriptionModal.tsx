import { toastSuccess } from '@/components/app-toast-messages';
import SubscriptionPaymentSuccess from '@/components/Payment/SubscriptionPaymentSuccess';
import { usePage } from '@inertiajs/react';
import axios from 'axios';
import { useEffect, useState } from 'react';

type Plan = {
    id: number;
    name: string;
    description: string;
    value: string;
};

type PixData = {
    qr_code: string;
    qr_code_copy_paste: string;
};

type SubscriptionAlert = {
    status?: string;
    days_overdue?: number;
};

type SubscriptionModalPageProps = {
    subscription_alert?: SubscriptionAlert;
    plans?: Plan[];
};

export default function SubscriptionModal() {
    const { subscription_alert, plans } = usePage<SubscriptionModalPageProps>().props;
    const [isOpen, setIsOpen] = useState(false);
    const [selectedPlan, setSelectedPlan] = useState<number | null>(null);
    const [pixData, setPixData] = useState<PixData | null>(null);
    const [loading, setLoading] = useState(false);
    const [paymentConfirmed, setPaymentConfirmed] = useState(false);

    const COOLDOWN_HOURS = 3;

    // 1. Lógica de Controle de Abertura (Cooldown + Manual)
    useEffect(() => {
        const checkAndOpen = () => {
            if (subscription_alert?.status === 'grace_period') {
                const lastShown = localStorage.getItem('subscription_modal_last_shown');
                const now = new Date().getTime();
                const cooldownMs = COOLDOWN_HOURS * 60 * 60 * 1000;

                if (!lastShown || now - parseInt(lastShown) > cooldownMs) {
                    setIsOpen(true);
                    localStorage.setItem('subscription_modal_last_shown', now.toString());
                }
            }
        };

        // Roda ao carregar ou trocar de rota (via subscription_alert)
        checkAndOpen();

        // Listener para abertura manual (vindo do Banner)
        const openManual = () => setIsOpen(true);
        window.addEventListener('open-subscription-modal', openManual);

        return () => window.removeEventListener('open-subscription-modal', openManual);
    }, [subscription_alert]); // O Inertia atualiza isso em cada navegação

    // 2. Polling do Status do Pagamento
    useEffect(() => {
        let interval: ReturnType<typeof setInterval> | null = null;

        if (pixData) {
            interval = setInterval(async () => {
                try {
                    const response = await axios.get(route('subscription.check_status'));
                    if (response.data.status === 'active') {
                        if (interval) {
                            clearInterval(interval);
                        }
                        setPaymentConfirmed(true);
                    }
                } catch (error) {
                    console.error('Erro ao verificar status', error);
                }
            }, 5000);
        }

        return () => {
            if (interval) clearInterval(interval);
        };
    }, [pixData]);

    const handleGeneratePix = async () => {
        if (!selectedPlan) return;
        setLoading(true);
        try {
            const response = await axios.post(route('subscription.generate_pix'), {
                plan_id: selectedPlan,
            });
            setPixData(response.data);
        } catch (error) {
            console.error('Erro ao gerar PIX', error);
        } finally {
            setLoading(false);
        }
    };

    const handleContinue = () => {
        window.location.href = route('app.dashboard');
    };

    // Renderização Condicional (Abaixo de todos os Hooks)
    if (!isOpen && !paymentConfirmed) return null;

    return (
        <>
            <SubscriptionPaymentSuccess open={paymentConfirmed} onContinue={handleContinue} />

            {isOpen && !paymentConfirmed && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/50 p-4">
                    <div className="relative w-full max-w-lg rounded-lg bg-white p-6 shadow-xl">
                        <h2 className="mb-2 text-xl font-bold text-red-600">Assinatura Pendente</h2>

                        <p className="mb-4 text-gray-600">
                            Sua assinatura venceu há <strong>{Math.floor(subscription_alert?.days_overdue || 0)}</strong> dias. Você tem{' '}
                            <strong>{Math.max(0, Math.ceil(3 - (subscription_alert?.days_overdue || 0)))}</strong> dias de cortesia restantes.
                        </p>

                        {pixData && (
                            <div className="mb-4 flex animate-pulse items-center justify-center rounded bg-blue-50 p-2 text-blue-600">
                                <span className="text-sm font-medium">Aguardando confirmação do banco...</span>
                            </div>
                        )}

                        {!pixData ? (
                            <div className="space-y-3">
                                <label className="text-sm font-medium text-gray-700">Escolha um plano:</label>

                                {(!plans || plans.length === 0) && (
                                    <p className="rounded bg-red-50 p-2 text-sm text-red-500">Nenhum plano disponível.</p>
                                )}

                                {plans
                                    ?.filter((p) => parseFloat(p.value) > 0)
                                    .map((plan) => (
                                        <div
                                            key={plan.id}
                                            className={`flex cursor-pointer items-center justify-between rounded border p-3 transition ${
                                                selectedPlan === plan.id
                                                    ? 'border-blue-500 bg-blue-50 ring-1 ring-blue-500'
                                                    : 'border-gray-200 hover:bg-gray-50'
                                            }`}
                                            onClick={() => setSelectedPlan(plan.id)}
                                        >
                                            <div>
                                                <div className="font-bold text-gray-800">{plan.name}</div>
                                                <div className="text-xs text-gray-500">{plan.description}</div>
                                            </div>
                                            <div className="font-semibold text-blue-600">
                                                R$ {parseFloat(plan.value).toLocaleString('pt-BR', { minimumFractionDigits: 2 })}
                                            </div>
                                        </div>
                                    ))}

                                <button
                                    onClick={handleGeneratePix}
                                    disabled={!selectedPlan || loading}
                                    className="mt-4 w-full rounded-lg bg-blue-600 py-3 font-bold text-white disabled:opacity-50"
                                >
                                    {loading ? 'Processando...' : 'Gerar PIX'}
                                </button>
                            </div>
                        ) : (
                            <div className="text-center">
                                <img src={`data:image/png;base64,${pixData.qr_code}`} alt="QR Code" className="mx-auto mb-4 h-48 w-48 border p-2" />
                                <input type="text" readOnly value={pixData.qr_code_copy_paste} className="w-full border bg-gray-50 p-2 text-xs" />
                                <button
                                    onClick={() => {
                                        navigator.clipboard.writeText(pixData.qr_code_copy_paste);
                                        toastSuccess('Código Pix copiado!');
                                    }}
                                    className="mx-auto mt-2 block text-sm text-blue-600 underline"
                                >
                                    Copiar Código PIX
                                </button>
                            </div>
                        )}

                        <button onClick={() => setIsOpen(false)} className="mt-6 w-full text-center text-sm text-gray-400 underline">
                            Fechar e pagar depois
                        </button>
                    </div>
                </div>
            )}
        </>
    );
}
