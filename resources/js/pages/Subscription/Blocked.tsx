import SubscriptionPaymentSuccess from '@/components/Payment/SubscriptionPaymentSuccess';
import { Head, router } from '@inertiajs/react'; // "router" substitui "Inertia" nas versoes novas
import axios from 'axios';
import moment from 'moment';
import { useEffect, useState } from 'react';

export default function Blocked({ plans, tenant, charge, active }: any) {
    const [selectedPlan, setSelectedPlan] = useState(null);
    const [loading, setLoading] = useState(false);
    const [pixData, setPixData] = useState<any>(null);
    const [paymentConfirmed, setPaymentConfirmed] = useState(false);

    // Efeito de Polling: Verifica status a cada 5 segundos se estiver aguardando pagamento
    useEffect(() => {
        let interval: any = null;

        // Só inicia o polling se houver um QR Code na tela
        if (pixData) {
            interval = setInterval(async () => {
                try {
                    const response = await axios.get(route('subscription.check_status'));

                    if (response.data.status === 'active') {
                        // 1. Limpa o intervalo imediatamente
                        clearInterval(interval);
                        setPaymentConfirmed(true);
                    }
                } catch (error) {
                    console.error('Erro ao verificar status do pagamento', error);
                    // Opcional: parar o polling se der erro 500 repetidamente
                }
            }, 5000); // 5 segundos é o padrão ideal para o Mercado Pago
        }

        // CLEANUP: Limpa o intervalo se o usuário fechar o modal ou sair da página
        return () => {
            if (interval) clearInterval(interval);
        };
    }, [pixData]);

    const handleGeneratePix = async () => {
        if (!selectedPlan && !charge) return;
        setLoading(true);

        try {
            // Contrato personalizado: o servidor cobra a parcela em aberto, sem plano.
            const response = await axios.post(route('subscription.pay'), charge ? {} : { plan_id: selectedPlan });

            setPixData(response.data);
            console.log(response);
        } catch (error) {
            alert('Erro ao gerar pagamento. Tente novamente.');
            console.log(error);
        } finally {
            setLoading(false);
        }
    };

    const handleLogout = (e: any) => {
        e.preventDefault();
        router.post(route('logout'));
    };

    const handleContinue = () => {
        // window.location.href é mais seguro para resetar todos os middlewares
        window.location.href = route('app.dashboard');
    };

    return (
        <div className="flex min-h-screen flex-col justify-center bg-gray-100 py-12 sm:px-6 lg:px-8">
            <Head title="Acesso Bloqueado" />
            <SubscriptionPaymentSuccess open={paymentConfirmed} onContinue={handleContinue} />

            {pixData && (
                <div className="mt-4 flex flex-col items-center">
                    <div className="flex animate-pulse items-center text-blue-600">
                        <svg className="mr-2 h-5 w-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span className="text-sm font-medium">Aguardando confirmação do banco...</span>
                    </div>
                    <p className="mt-1 text-xs text-gray-400 italic">Não é necessário enviar o comprovante, a liberação é automática.</p>
                </div>
            )}

            <div className="text-center sm:mx-auto sm:w-full sm:max-w-md">
                {/* Ícone de Cadeado ou Alerta */}
                <div className="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-red-100">
                    <svg className="h-6 w-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path
                            strokeLinecap="round"
                            strokeLinejoin="round"
                            strokeWidth="2"
                            d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"
                        />
                    </svg>
                </div>

                <h2 className="mt-6 text-3xl font-extrabold text-gray-900">{active ? 'Fatura em aberto' : 'Acesso Suspenso'}</h2>
                <p className="mt-2 text-sm text-gray-600">
                    Olá, <span className="font-bold">{tenant.name}</span>.{' '}
                    {charge
                        ? 'Pague a fatura do seu contrato por PIX; a liberação é automática.'
                        : plans.length > 0
                          ? 'Identificamos pendências financeiras. Para reativar seu acesso instantaneamente, escolha um plano abaixo.'
                          : 'Identificamos pendências financeiras. Fale com o atendimento da ABrasil Sistemas para regularizar.'}
                </p>
            </div>

            <div className="mt-8 sm:mx-auto sm:w-full sm:max-w-lg">
                <div className="bg-white px-4 py-8 shadow sm:rounded-lg sm:px-10">
                    {/* COBRANÇA DO CONTRATO PERSONALIZADO */}
                    {charge && !pixData ? (
                        <div className="space-y-4">
                            <div className="rounded-lg border border-gray-300 p-4 text-sm text-gray-700">
                                <p>
                                    <span className="font-medium">Contrato:</span> {charge.contract_number}
                                </p>
                                <p>
                                    <span className="font-medium">Competência:</span> {moment(charge.cycle_start).format('DD/MM/YYYY')} a{' '}
                                    {moment(charge.cycle_end).format('DD/MM/YYYY')}
                                </p>
                                <p>
                                    <span className="font-medium">Vencimento:</span> {moment(charge.due_date).format('DD/MM/YYYY')}
                                </p>
                                <p className="mt-2 text-base font-bold text-gray-900">
                                    {Number(charge.amount).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })}
                                </p>
                            </div>
                            <button
                                onClick={handleGeneratePix}
                                disabled={loading}
                                className="mt-6 flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none disabled:opacity-50"
                            >
                                {loading ? 'Gerando Cobrança...' : 'Gerar QR Code PIX'}
                            </button>
                        </div>
                    ) : /* SELEÇÃO DE PLANO */
                    !pixData ? (
                        <div className="space-y-4">
                            <label className="mb-2 block text-sm font-medium text-gray-700">Selecione uma opção de renovação:</label>

                            <div className="grid grid-cols-1 gap-4">
                                {plans.map((plan: any) => (
                                    <div
                                        key={plan.id}
                                        onClick={() => setSelectedPlan(plan.id)}
                                        className={`relative flex cursor-pointer rounded-lg border p-4 shadow-sm focus:outline-none ${selectedPlan === plan.id ? 'border-transparent bg-indigo-50 ring-2 ring-indigo-500' : 'border-gray-300 bg-white hover:bg-gray-50'}`}
                                    >
                                        <div className="flex flex-1 justify-between">
                                            <div>
                                                <span className="block text-sm font-medium text-gray-900">{plan.name}</span>
                                                <span className="block text-sm text-gray-500">{plan.description}</span>
                                            </div>
                                            <div className="text-sm font-bold text-gray-900">R$ {plan.value}</div>
                                        </div>
                                        {selectedPlan === plan.id && (
                                            <div className="absolute -top-2 -right-2 rounded-full bg-indigo-600 p-1">
                                                <svg className="h-4 w-4 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path strokeLinecap="round" strokeLinejoin="round" strokeWidth="2" d="M5 13l4 4L19 7" />
                                                </svg>
                                            </div>
                                        )}
                                    </div>
                                ))}
                            </div>

                            <button
                                onClick={handleGeneratePix}
                                disabled={!selectedPlan || loading}
                                className="mt-6 flex w-full justify-center rounded-md border border-transparent bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 focus:outline-none disabled:opacity-50"
                            >
                                {loading ? 'Gerando Cobrança...' : 'Gerar QR Code PIX'}
                            </button>
                        </div>
                    ) : (
                        /* PAGAMENTO PIX */
                        <div className="animate-fade-in-up text-center">
                            <div className="mb-4 rounded-md border border-green-200 bg-green-50 p-4">
                                <p className="font-medium text-green-800">Cobrança gerada com sucesso!</p>
                                <p className="text-sm text-green-600">Aguardando confirmação automática...</p>
                            </div>

                            <img src={`data:image/png;base64,${pixData.qr_code}`} alt="QR Code" className="mx-auto h-64 w-64 rounded border p-2" />

                            <div className="mt-4">
                                <label className="block text-sm font-medium text-gray-700">Copia e Cola</label>
                                <div className="mt-1 flex rounded-md shadow-sm">
                                    <input
                                        type="text"
                                        readOnly
                                        value={pixData.qr_code_copy_paste}
                                        className="block w-full flex-1 rounded-none rounded-l-md border-gray-300 bg-gray-50 p-2 focus:border-indigo-500 focus:ring-indigo-500 sm:text-xs"
                                    />
                                    <button
                                        onClick={() => {
                                            navigator.clipboard.writeText(pixData.qr_code_copy_paste);
                                            alert('Código copiado!');
                                        }}
                                        className="relative -ml-px inline-flex items-center space-x-2 rounded-r-md border border-gray-300 bg-gray-50 px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-100 focus:border-indigo-500 focus:ring-1 focus:ring-indigo-500 focus:outline-none"
                                    >
                                        <span>Copiar</span>
                                    </button>
                                </div>
                            </div>

                            <div className="mt-6 flex justify-center">
                                <svg
                                    className="mr-3 h-5 w-5 animate-spin text-indigo-600"
                                    xmlns="http://www.w3.org/2000/svg"
                                    fill="none"
                                    viewBox="0 0 24 24"
                                >
                                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                                    <path
                                        className="opacity-75"
                                        fill="currentColor"
                                        d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"
                                    ></path>
                                </svg>
                                <span className="text-sm text-gray-500">Verificando pagamento...</span>
                            </div>

                            <button onClick={() => setPixData(null)} className="mt-4 text-xs text-gray-400 underline hover:text-gray-600">
                                {charge ? 'Voltar' : 'Escolher outro plano'}
                            </button>
                        </div>
                    )}
                </div>

                {/* BOTÃO DE LOGOUT */}
                <div className="mt-6 text-center">
                    <form onSubmit={handleLogout}>
                        <button type="submit" className="text-sm font-medium text-gray-500 underline hover:text-gray-900">
                            Sair da conta
                        </button>
                    </form>
                </div>
            </div>
        </div>
    );
}
