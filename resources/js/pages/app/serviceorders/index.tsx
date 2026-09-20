import { toastSuccess, toastWarning } from '@/components/app-toast-messages';
import { StatusBadge } from '@/components/StatusBadge';
import Timeline from '@/components/timeline';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Toaster } from '@/components/ui/sonner';
import { normalizeWhatsappPhone } from '@/Utils/mask';
import { ORDER_STATUS, orderStatusLabel } from '@/Utils/order-status';
import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarClock,
    CheckCircle2,
    CreditCard,
    Expand,
    ExternalLink,
    FileText,
    InfoIcon,
    MessageCircle,
    PackageSearch,
    ReceiptText,
    ShieldCheck,
    Smartphone,
    Star,
    Wrench,
} from 'lucide-react';
import { useMemo, useState } from 'react';

interface OrderPayment {
    id: number;
    amount: number;
    paid_at?: string;
    payment_method?: string;
}

interface OrderImage {
    id: number;
    filename: string;
}

interface Order {
    id: number;
    order_number: number;
    tracking_token: string;
    service_status: number;
    created_at: string;
    delivery_forecast?: string;
    delivery_date?: string;
    model?: string;
    accessories?: string;
    state_conservation?: string;
    defect?: string;
    budget_value?: number;
    budget_description?: string;
    services_performed?: string;
    parts_value?: number;
    service_value?: number;
    service_cost?: number;
    warranty_days?: number;
    warranty_expires_at?: string;
    is_warranty_return?: boolean;
    fiscal_document_number?: string;
    fiscal_document_url?: string;
    fiscal_issued_at?: string;
    fiscal_notes?: string;
    customer_notification_acknowledged_at?: string;
    customer_pickup_acknowledged_at?: string;
    customer_feedback_rating?: number;
    customer_feedback_comment?: string;
    customer_feedback_submitted_at?: string;
    customer_update_note?: string;
    customer_update_note_at?: string;
    images?: OrderImage[];
    order_payments?: OrderPayment[];
    warranty_source_order?: { order_number: number; warranty_expires_at?: string };
    customer?: { name: string };
    company?: { logo?: string; whatsapp?: string };
    equipment?: { equipment: string };
}

function formatDate(value?: string) {
    if (!value) return '-';

    const dateOnly = value.match(/^(\d{4})-(\d{2})-(\d{2})/);

    if (dateOnly) {
        const [, year, month, day] = dateOnly;

        return `${day}/${month}/${year}`;
    }

    return new Date(value).toLocaleDateString('pt-BR');
}

function formatDateTime(value?: string) {
    if (!value) return '-';
    return new Date(value).toLocaleString('pt-BR');
}

function formatCurrency(value?: number | string | null) {
    const amount = Number(value ?? 0);

    return new Intl.NumberFormat('pt-BR', {
        style: 'currency',
        currency: 'BRL',
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(Number.isFinite(amount) ? amount : 0);
}

function getRemainingTime(deliveryDate?: string) {
    if (!deliveryDate) return null;

    const today = new Date();
    today.setHours(0, 0, 0, 0);

    const dateOnly = deliveryDate.match(/^(\d{4})-(\d{2})-(\d{2})$/);
    const delivery = dateOnly ? new Date(Number(dateOnly[1]), Number(dateOnly[2]) - 1, Number(dateOnly[3])) : new Date(deliveryDate);
    delivery.setHours(0, 0, 0, 0);

    const diff = delivery.getTime() - today.getTime();
    const days = Math.round(diff / (1000 * 60 * 60 * 24));

    if (days < 0) return 'Prazo expirado';
    if (days === 0) return 'Concluir hoje';
    return `${days} dias restantes`;
}

function feedbackLabel(rating: number) {
    switch (rating) {
        case 1:
            return 'Muito ruim';
        case 2:
            return 'Ruim';
        case 3:
            return 'Regular';
        case 4:
            return 'Bom';
        case 5:
            return 'Excelente';
        default:
            return 'Avalie seu atendimento';
    }
}

function nextStepText(order: Order, remainingAmount: number) {
    switch (order.service_status) {
        case ORDER_STATUS.OPEN:
            return 'Seu equipamento entrou na fila de análise. Nossa equipe vai avaliar o defeito e atualizar o andamento.';
        case ORDER_STATUS.BUDGET_GENERATED:
            return 'Seu orçamento já está pronto. Você pode aprovar ou reprovar diretamente nesta página.';
        case ORDER_STATUS.BUDGET_APPROVED:
            return 'Orçamento aprovado. Agora a assistência segue para execução do serviço.';
        case ORDER_STATUS.BUDGET_REJECTED:
            return 'O orçamento foi reprovado. Se desejar revisar essa decisão, entre em contato com a assistência.';
        case ORDER_STATUS.REPAIR_IN_PROGRESS:
            return 'O reparo está em andamento. Assim que houver atualização importante, ela aparecerá aqui.';
        case ORDER_STATUS.SERVICE_COMPLETED:
            return remainingAmount > 0.009
                ? 'O serviço foi concluído. Falta apenas a regularização do saldo para liberação.'
                : 'O serviço foi concluído. Agora falta apenas combinar a retirada do equipamento.';
        case ORDER_STATUS.CUSTOMER_NOTIFIED:
            return remainingAmount > 0.009
                ? 'Seu equipamento está pronto e aguardando retirada, com saldo pendente em aberto.'
                : 'Seu equipamento está pronto e aguardando retirada.';
        case ORDER_STATUS.DELIVERED:
            return 'Atendimento finalizado e equipamento entregue ao cliente.';
        case ORDER_STATUS.CANCELLED:
            return 'Esta ordem foi cancelada.';
        default:
            return 'Acompanhe as atualizações desta ordem por esta página.';
    }
}

function actionChecklist(order: Order, remainingAmount: number) {
    if (order.service_status === ORDER_STATUS.BUDGET_GENERATED) {
        return [
            'Revise a descrição e o valor do orçamento.',
            'Aprove ou reprove o orçamento por esta página.',
            'Se tiver dúvida, fale com a assistência antes de decidir.',
        ];
    }

    if (order.service_status === ORDER_STATUS.SERVICE_COMPLETED || order.service_status === ORDER_STATUS.CUSTOMER_NOTIFIED) {
        if (remainingAmount > 0.009) {
            return [
                'Consulte o saldo pendente do atendimento.',
                'Entre em contato com a assistência para combinar pagamento e retirada.',
                'Após a quitação, confirme a retirada do equipamento.',
            ];
        }

        return [
            'Seu equipamento está pronto para retirada.',
            'Entre em contato com a assistência para combinar horário.',
            'Tenha em mãos o número da ordem no momento da retirada.',
        ];
    }

    if (order.service_status === ORDER_STATUS.DELIVERED) {
        return [
            'Atendimento finalizado com sucesso.',
            'Acompanhe o prazo de garantia informado nesta página.',
            'Se houver novo problema dentro da garantia, informe a ordem de origem.',
        ];
    }

    return [
        'Acompanhe o status e a linha do tempo desta ordem.',
        'Quando houver uma nova etapa, ela aparecerá aqui.',
        'Se precisar de apoio, fale com a assistência pelo botão de contato.',
    ];
}

function ServiceOrders({ order }: { order: Order }) {
    const { company, hasChecklist } = usePage().props as any;
    const [loadingA, setLoadingA] = useState(false);
    const [loadingR, setLoadingR] = useState(false);
    const [loadingAck, setLoadingAck] = useState(false);
    const [loadingPickup, setLoadingPickup] = useState(false);
    const [loadingFeedback, setLoadingFeedback] = useState(false);
    const [feedbackRating, setFeedbackRating] = useState<number | null>(order.customer_feedback_rating ?? null);
    const [feedbackComment, setFeedbackComment] = useState(order.customer_feedback_comment ?? '');
    const [selectedImage, setSelectedImage] = useState<{ src: string; alt: string } | null>(null);
    const [budgetModalOpen, setBudgetModalOpen] = useState(false);
    const [rejectConfirmOpen, setRejectConfirmOpen] = useState(false);
    const [pickupConfirmOpen, setPickupConfirmOpen] = useState(false);

    const financialSummary = useMemo(() => {
        const total = Number(order.service_cost ?? 0);
        const paid = (order.order_payments ?? []).reduce((sum, payment) => sum + Number(payment.amount ?? 0), 0);
        const remaining = Math.max(0, total - paid);

        return {
            total,
            paid,
            remaining,
        };
    }, [order.order_payments, order.service_cost]);

    const remaining = getRemainingTime(order.delivery_forecast);
    const heroNote = nextStepText(order, financialSummary.remaining);
    const companyName = company?.shortname || company?.companyname;
    const checklist = actionChecklist(order, financialSummary.remaining);
    const canAcknowledgePickup =
        (order.service_status === ORDER_STATUS.CUSTOMER_NOTIFIED || order.service_status === ORDER_STATUS.DELIVERED) &&
        financialSummary.remaining <= 0.009;
    const imageUrls = (order.images ?? []).map((image) => ({
        id: image.id,
        src: `/storage/orders/${order.id}/${image.filename}`,
        alt: `Imagem da ordem ${order.order_number}`,
    }));
    const hasBudgetReceipt = Boolean(order.budget_description || Number(order.budget_value ?? 0) > 0);
    const hasDeliveryReceipt =
        order.service_status === ORDER_STATUS.SERVICE_COMPLETED ||
        order.service_status === ORDER_STATUS.CUSTOMER_NOTIFIED ||
        order.service_status === ORDER_STATUS.DELIVERED;
    const hasPaymentProof = (order.order_payments ?? []).length > 0;
    const hasFiscalProof = Boolean(order.fiscal_document_number || order.fiscal_document_url);
    const canSubmitFeedback = order.service_status === ORDER_STATUS.DELIVERED && !order.customer_feedback_submitted_at;
    const warrantyStatusLabel = order.warranty_expires_at
        ? new Date(order.warranty_expires_at).getTime() >= new Date().setHours(0, 0, 0, 0)
            ? `Garantia ativa até ${formatDate(order.warranty_expires_at)}`
            : `Garantia encerrada em ${formatDate(order.warranty_expires_at)}`
        : 'Garantia não informada';

    function budgetAlter(status: 4 | 5) {
        router.post(
            route('orders.budget.status', order.tracking_token),
            { status },
            {
                preserveScroll: true,
                onStart: () => (status === 4 ? setLoadingA(true) : setLoadingR(true)),
                onSuccess: () => {
                    setBudgetModalOpen(false);
                    toastSuccess('Sucesso', status === 4 ? 'Orçamento aprovado com sucesso' : 'Orçamento recusado com sucesso');
                },
                onError: () => {
                    toastWarning('Erro', 'Não foi possível atualizar o orçamento');
                },
                onFinish: () => (status === 4 ? setLoadingA(false) : setLoadingR(false)),
            },
        );
    }

    function handleApprove() {
        budgetAlter(4);
    }

    function handleReject() {
        setRejectConfirmOpen(true);
    }

    function confirmReject() {
        setRejectConfirmOpen(false);
        budgetAlter(5);
    }

    function handleAcknowledgeNotification() {
        router.post(
            route('orders.notification.acknowledge', order.tracking_token),
            {},
            {
                preserveScroll: true,
                onStart: () => setLoadingAck(true),
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Recebimento do aviso confirmado com sucesso.');
                },
                onError: () => {
                    toastWarning('Erro', 'Não foi possível confirmar o aviso neste momento.');
                },
                onFinish: () => setLoadingAck(false),
            },
        );
    }

    function handleAcknowledgePickup() {
        setPickupConfirmOpen(true);
    }

    function confirmAcknowledgePickup() {
        setPickupConfirmOpen(false);

        router.post(
            route('orders.pickup.acknowledge', order.tracking_token),
            {},
            {
                preserveScroll: true,
                onStart: () => setLoadingPickup(true),
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Retirada confirmada com sucesso.');
                },
                onError: () => {
                    toastWarning('Erro', 'Não foi possível confirmar a retirada neste momento.');
                },
                onFinish: () => setLoadingPickup(false),
            },
        );
    }

    function handleSubmitFeedback() {
        if (!feedbackRating) {
            toastWarning('Avaliação', 'Escolha uma nota de 1 a 5 antes de enviar.');
            return;
        }

        router.post(
            route('os.feedback.submit', order.tracking_token),
            {
                rating: feedbackRating,
                comment: feedbackComment,
            },
            {
                preserveScroll: true,
                onStart: () => setLoadingFeedback(true),
                onSuccess: () => {
                    toastSuccess('Obrigado!', 'Seu feedback foi enviado com sucesso.');
                },
                onError: () => {
                    toastWarning('Erro', 'Não foi possível enviar seu feedback neste momento.');
                },
                onFinish: () => setLoadingFeedback(false),
            },
        );
    }

    return (
        <>
            <Toaster />
            <Head title={`OS #${order.order_number}`} />

            <div className="border-b border-slate-800 bg-[linear-gradient(135deg,#020617_0%,#0f172a_48%,#1e293b_100%)] py-4 shadow-sm">
                <div className="mx-auto max-w-6xl px-4">
                    <Timeline status={Number(order.service_status)} theme="dark" />
                </div>
            </div>

            <div className="min-h-screen bg-[linear-gradient(180deg,#f7f4ec_0%,#f9fafb_34%,#ffffff_100%)] px-4 py-8">
                <div className="mx-auto flex max-w-6xl flex-col gap-6">
                    <section className="overflow-hidden rounded-[28px] border border-slate-200 bg-white shadow-sm">
                        {/* deixar um abaixo do outro*/}
                        <div className="grid gap-0">
                            <div className="bg-[radial-gradient(circle_at_top_left,_rgba(245,158,11,0.15),_transparent_38%),linear-gradient(135deg,#0f172a_0%,#1e293b_55%,#334155_100%)] p-6 text-white md:p-8">
                                <div className="flex flex-wrap items-start justify-between gap-4">
                                    <div className="space-y-3">
                                        {(company?.logo || companyName || company?.cnpj) && (
                                            <div className="flex flex-wrap items-center gap-3">
                                                {company?.logo && (
                                                    <img
                                                        src={`/storage/logos/${company.logo}`}
                                                        alt={companyName ? `Logo ${companyName}` : 'Logo da empresa'}
                                                        className="h-12 rounded-md bg-white/90 p-2"
                                                        onError={(event) => {
                                                            event.currentTarget.src = '/images/default.png';
                                                        }}
                                                    />
                                                )}
                                                {(companyName || company?.cnpj) && (
                                                    <div className="min-w-0">
                                                        {companyName && (
                                                            <p className="text-base leading-tight font-semibold text-white">{companyName}</p>
                                                        )}
                                                        {company?.cnpj && (
                                                            <p className="mt-1 text-xs font-medium text-slate-300">CNPJ: {company.cnpj}</p>
                                                        )}
                                                    </div>
                                                )}
                                            </div>
                                        )}
                                        <div>
                                            <p className="text-sm font-medium tracking-[0.25em] text-amber-200 uppercase">Acompanhamento online</p>
                                            <h1 className="mt-2 text-3xl font-semibold tracking-tight md:text-4xl">
                                                Ordem de serviço #{order.order_number}
                                            </h1>
                                            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-200 md:text-base">{heroNote}</p>
                                            {order.customer_update_note && (
                                                <div className="mt-4 max-w-2xl rounded-xl border border-amber-300/30 bg-amber-400/10 px-4 py-3">
                                                    <p className="text-xs font-semibold tracking-[0.15em] text-amber-200 uppercase">
                                                        Atualização da assistência
                                                        {order.customer_update_note_at ? ` • ${formatDateTime(order.customer_update_note_at)}` : ''}
                                                    </p>
                                                    <p className="mt-1 text-sm leading-6 text-amber-50">{order.customer_update_note}</p>
                                                </div>
                                            )}
                                        </div>
                                    </div>

                                    <div className="rounded-2xl border border-white/15 bg-white/10 px-4 py-3 backdrop-blur">
                                        <p className="text-xs tracking-[0.2em] text-slate-300 uppercase">Status atual</p>
                                        <div className="mt-2 flex items-center gap-2 md:flex-col md:items-stretch">
                                            <StatusBadge
                                                category="ordem"
                                                value={order.service_status}
                                                className="border-white/20 bg-white/10 px-3 py-1.5 text-white"
                                            />
                                            {hasBudgetReceipt && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() => setBudgetModalOpen(true)}
                                                    className="border border-amber-300/40 bg-amber-400 px-3 text-slate-950 hover:bg-amber-300 hover:text-slate-950"
                                                >
                                                    <FileText className="h-4 w-4" />
                                                    Orçamento
                                                </Button>
                                            )}
                                        </div>
                                    </div>
                                </div>

                                <div className="mt-6 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <p className="text-xs tracking-[0.18em] text-slate-300 uppercase">Aberta em</p>
                                        <p className="mt-2 text-sm font-medium">{formatDate(order.created_at)}</p>
                                    </div>
                                    <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <p className="text-xs tracking-[0.18em] text-slate-300 uppercase">Equipamento</p>
                                        <p className="mt-2 text-sm font-medium">{order.equipment?.equipment ?? '-'}</p>
                                    </div>
                                    <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <p className="text-xs tracking-[0.18em] text-slate-300 uppercase">Modelo</p>
                                        <p className="mt-2 text-sm font-medium">{order.model || '-'}</p>
                                    </div>
                                    <div className="rounded-2xl border border-white/10 bg-white/5 p-4">
                                        <p className="text-xs tracking-[0.18em] text-slate-300 uppercase">Previsão</p>
                                        <p className="mt-2 text-sm font-medium">{formatDate(order.delivery_forecast)}</p>
                                        {remaining && <p className="mt-1 text-xs text-amber-200">{remaining}</p>}
                                    </div>
                                </div>
                            </div>
                            <div className="bg-[#fcfbf7] p-6 md:p-8">
                                <div className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
                                    <div className="space-y-4">
                                        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                            <div className="flex items-center gap-3">
                                                <CheckCircle2 className="h-5 w-5 text-amber-700" />
                                                <div>
                                                    <p className="font-medium text-slate-900">Próximo passo</p>
                                                    <p className="text-sm text-slate-600">{heroNote}</p>
                                                </div>
                                            </div>
                                        </div>

                                        <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                            <div className="flex items-center gap-3">
                                                <ReceiptText className="h-5 w-5 text-slate-500" />
                                                <div>
                                                    <p className="font-medium text-slate-900">O que fazer agora</p>
                                                    <div className="mt-2 space-y-2">
                                                        {checklist.map((item) => (
                                                            <div key={item} className="flex items-start gap-2 text-sm text-slate-600">
                                                                <span className="mt-1 h-1.5 w-1.5 rounded-full bg-amber-500" />
                                                                <span>{item}</span>
                                                            </div>
                                                        ))}
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {order.service_status === ORDER_STATUS.BUDGET_GENERATED && (
                                            <div className="rounded-2xl border border-red-200 bg-red-50 p-4">
                                                <div className="flex items-start gap-3">
                                                    <InfoIcon className="mt-0.5 h-5 w-5 text-red-500" />
                                                    <div className="space-y-3">
                                                        <div>
                                                            <p className="font-medium text-slate-900">Aguardando sua decisão</p>
                                                            <p className="text-sm text-slate-600">
                                                                Este orçamento está disponível para aprovação ou reprovação online.
                                                            </p>
                                                        </div>
                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                onClick={handleApprove}
                                                                disabled={loadingA}
                                                                className="bg-green-600 text-white hover:bg-green-700"
                                                            >
                                                                {loadingA ? 'Aprovando...' : 'Aprovar orçamento'}
                                                            </Button>
                                                            <Button
                                                                onClick={handleReject}
                                                                disabled={loadingR}
                                                                className="bg-red-600 text-white hover:bg-red-700"
                                                            >
                                                                {loadingR ? 'Reprovando...' : 'Reprovar orçamento'}
                                                            </Button>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        )}

                                        {(order.service_status === ORDER_STATUS.SERVICE_COMPLETED ||
                                            order.service_status === ORDER_STATUS.CUSTOMER_NOTIFIED) && (
                                            <div className="rounded-2xl border border-cyan-200 bg-cyan-50 p-4">
                                                <div className="flex items-start gap-3">
                                                    <InfoIcon className="mt-0.5 h-5 w-5 text-cyan-600" />
                                                    <div className="space-y-3">
                                                        <div>
                                                            <p className="font-medium text-slate-900">Confirmação de aviso</p>
                                                            <p className="text-sm text-slate-600">
                                                                Use este botão para confirmar que você recebeu o aviso de conclusão e retirada do
                                                                equipamento.
                                                            </p>
                                                        </div>

                                                        {order.customer_notification_acknowledged_at ? (
                                                            <div className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-sm text-emerald-700">
                                                                Aviso confirmado em {formatDateTime(order.customer_notification_acknowledged_at)}.
                                                            </div>
                                                        ) : (
                                                            <Button
                                                                onClick={handleAcknowledgeNotification}
                                                                disabled={loadingAck}
                                                                className="bg-cyan-600 text-white hover:bg-cyan-700"
                                                            >
                                                                {loadingAck ? 'Confirmando...' : 'Recebi o aviso'}
                                                            </Button>
                                                        )}
                                                    </div>
                                                </div>
                                            </div>
                                        )}
                                    </div>

                                    <div className="space-y-4">
                                        <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                            <p className="text-sm font-medium text-slate-900">Ações rápidas</p>
                                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                {hasBudgetReceipt && (
                                                    <button
                                                        type="button"
                                                        onClick={() => setBudgetModalOpen(true)}
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                                    >
                                                        <FileText className="h-4 w-4" />
                                                        Ver orçamento
                                                    </button>
                                                )}
                                                {hasPaymentProof && (
                                                    <a
                                                        href={route('os.payment-proof', { token: order.tracking_token })}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                                    >
                                                        <CreditCard className="h-4 w-4" />
                                                        Ver pagamentos
                                                    </a>
                                                )}
                                                {hasDeliveryReceipt && (
                                                    <a
                                                        href={route('os.receipt', { token: order.tracking_token, type: 'orentrega', pdf: 1 })}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                                    >
                                                        <ReceiptText className="h-4 w-4" />
                                                        Recibo de entrega
                                                    </a>
                                                )}
                                                {hasChecklist && (
                                                    <a
                                                        href={route('os.receipt', { token: order.tracking_token, type: 'orchecklist', pdf: 1 })}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                                    >
                                                        <FileText className="h-4 w-4" />
                                                        Checklist de entrada
                                                    </a>
                                                )}
                                                {hasFiscalProof && (
                                                    <a
                                                        href={route('os.fiscal-proof', { token: order.tracking_token })}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                        className="inline-flex min-h-11 items-center gap-2 rounded-xl border border-slate-200 px-3 py-2 text-sm font-medium text-slate-700 transition hover:border-slate-300 hover:bg-slate-50"
                                                    >
                                                        <ExternalLink className="h-4 w-4 text-slate-500" />
                                                        Nota ou fiscal
                                                    </a>
                                                )}
                                            </div>
                                        </div>

                                        <div className="grid gap-3 sm:grid-cols-2">
                                            <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                                <div className="flex h-full items-center gap-3">
                                                    <CreditCard className="h-5 w-5 text-slate-500" />
                                                    <div>
                                                        <p className="text-sm text-slate-500">Total do atendimento</p>
                                                        <p className="text-lg font-semibold text-slate-900">
                                                            {formatCurrency(financialSummary.total)}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                            <div className="rounded-2xl border border-slate-200 bg-white p-4">
                                                <div className="flex h-full items-center gap-3">
                                                    <CalendarClock className="h-5 w-5 text-slate-500" />
                                                    <div>
                                                        <p className="text-sm text-slate-500">Saldo pendente</p>
                                                        <p
                                                            className={`text-lg font-semibold ${financialSummary.remaining > 0.009 ? 'text-rose-600' : 'text-emerald-600'}`}
                                                        >
                                                            {formatCurrency(financialSummary.remaining)}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>

                                        {order.company?.whatsapp && (
                                            <a
                                                href={`https://wa.me/${normalizeWhatsappPhone(order.company.whatsapp)}`}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="inline-flex w-full items-center justify-center gap-2 rounded-2xl bg-green-500 px-5 py-3 text-sm font-medium text-white transition hover:bg-green-600"
                                            >
                                                <MessageCircle className="h-4 w-4" />
                                                Falar com a assistência
                                            </a>
                                        )}
                                    </div>
                                </div>
                            </div>
                        </div>
                    </section>

                    <div className="grid min-w-0 gap-6 lg:grid-cols-[minmax(0,1.05fr)_minmax(0,0.95fr)]">
                        <div className="min-w-0 space-y-6">
                            <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                                <div className="mb-4 flex items-center gap-3">
                                    <Smartphone className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-lg font-semibold text-slate-900">Resumo do equipamento</h2>
                                </div>

                                <div className="grid min-w-0 gap-4 sm:grid-cols-2">
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-500">Cliente</p>
                                        <p className="font-medium break-words text-slate-900">{order.customer?.name || '-'}</p>
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-500">Tipo</p>
                                        <p className="font-medium break-words text-slate-900">{order.equipment?.equipment || '-'}</p>
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-500">Modelo</p>
                                        <p className="font-medium break-words text-slate-900">{order.model || '-'}</p>
                                    </div>
                                    <div className="min-w-0">
                                        <p className="text-sm text-slate-500">Acessórios</p>
                                        <p className="font-medium break-words text-slate-900">{order.accessories || '-'}</p>
                                    </div>
                                    <div className="min-w-0 sm:col-span-2">
                                        <p className="text-sm text-slate-500">Estado informado na entrada</p>
                                        <p className="font-medium break-words text-slate-900">{order.state_conservation || '-'}</p>
                                    </div>
                                    <div className="min-w-0 sm:col-span-2">
                                        <p className="text-sm text-slate-500">Defeito relatado</p>
                                        <p className="font-medium break-words text-slate-900">{order.defect || '-'}</p>
                                    </div>
                                </div>
                            </section>

                            {(order.budget_description || Number(order.budget_value ?? 0) > 0) && (
                                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                                    <div className="mb-4 flex items-center gap-3">
                                        <FileText className="h-5 w-5 text-slate-500" />
                                        <h2 className="text-lg font-semibold text-slate-900">Orçamento e decisão</h2>
                                    </div>

                                    <div className="grid min-w-0 gap-4 md:grid-cols-[minmax(0,1.2fr)_minmax(0,0.8fr)]">
                                        <div className="min-w-0 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 break-words text-slate-700">
                                            {order.budget_description || 'Sem descrição detalhada do orçamento.'}
                                        </div>
                                        <div className="min-w-0 rounded-2xl border border-red-200 bg-red-50 p-4">
                                            <p className="text-sm text-slate-500">Valor do orçamento</p>
                                            <p className="mt-2 text-2xl font-semibold text-red-600">{formatCurrency(order.budget_value)}</p>
                                            <p className="mt-3 text-sm break-words text-slate-600">
                                                Status atual: <span className="font-medium">{orderStatusLabel(order.service_status)}</span>
                                            </p>
                                        </div>
                                    </div>
                                </section>
                            )}

                            {(order.services_performed || Number(order.service_cost ?? 0) > 0) && (
                                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                                    <div className="mb-4 flex items-center gap-3">
                                        <Wrench className="h-5 w-5 text-slate-500" />
                                        <h2 className="text-lg font-semibold text-slate-900">Serviço executado</h2>
                                    </div>

                                    {order.services_performed && (
                                        <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm leading-6 text-slate-700">
                                            {order.services_performed}
                                        </div>
                                    )}

                                    <div className="mt-4 grid gap-4 sm:grid-cols-3">
                                        <div className="rounded-2xl border border-slate-200 p-4">
                                            <p className="text-sm text-slate-500">Peças</p>
                                            <p className="mt-2 text-lg font-semibold text-slate-900">{formatCurrency(order.parts_value)}</p>
                                        </div>
                                        <div className="rounded-2xl border border-slate-200 p-4">
                                            <p className="text-sm text-slate-500">Serviço</p>
                                            <p className="mt-2 text-lg font-semibold text-slate-900">{formatCurrency(order.service_value)}</p>
                                        </div>
                                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                            <p className="text-sm text-slate-500">Total</p>
                                            <p className="mt-2 text-lg font-semibold text-emerald-700">{formatCurrency(order.service_cost)}</p>
                                        </div>
                                    </div>
                                </section>
                            )}

                            {imageUrls.length > 0 && (
                                <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                                    <div className="mb-4 flex items-center gap-3">
                                        <PackageSearch className="h-5 w-5 text-slate-500" />
                                        <h2 className="text-lg font-semibold text-slate-900">Imagens do atendimento</h2>
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        {imageUrls.map((image) => (
                                            <button
                                                key={image.id}
                                                type="button"
                                                onClick={() => setSelectedImage({ src: image.src, alt: image.alt })}
                                                className="group relative overflow-hidden rounded-2xl border border-slate-200 text-left"
                                            >
                                                <img
                                                    src={image.src}
                                                    alt={image.alt}
                                                    className="h-52 w-full object-cover transition duration-300 group-hover:scale-[1.02]"
                                                />
                                                <div className="absolute inset-x-0 bottom-0 flex items-center justify-between bg-gradient-to-t from-slate-950/75 to-transparent px-4 py-3 text-sm text-white">
                                                    <span>Visualizar imagem</span>
                                                    <Expand className="h-4 w-4" />
                                                </div>
                                            </button>
                                        ))}
                                    </div>
                                </section>
                            )}
                        </div>

                        <div className="min-w-0 space-y-6">
                            <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                                <div className="mb-4 flex items-center gap-3">
                                    <ShieldCheck className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-lg font-semibold text-slate-900">Garantia e entrega</h2>
                                </div>

                                <div className="space-y-4">
                                    <div className="rounded-2xl border border-slate-200 p-4">
                                        <p className="text-sm text-slate-500">Previsão de entrega</p>
                                        <p className="mt-2 font-medium text-slate-900">{formatDate(order.delivery_forecast)}</p>
                                        {remaining && <p className="mt-1 text-sm text-slate-600">{remaining}</p>}
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 p-4">
                                        <p className="text-sm text-slate-500">Retirada confirmada</p>
                                        <p className="mt-2 font-medium text-slate-900">
                                            {order.customer_pickup_acknowledged_at
                                                ? formatDateTime(order.customer_pickup_acknowledged_at)
                                                : 'Aguardando confirmação do cliente'}
                                        </p>
                                        {order.delivery_date && (
                                            <p className="mt-1 text-sm text-slate-600">Entrega registrada em {formatDate(order.delivery_date)}</p>
                                        )}
                                    </div>

                                    <div className="rounded-2xl border border-slate-200 p-4">
                                        <p className="text-sm text-slate-500">Garantia</p>
                                        <p className="mt-2 font-medium text-slate-900">
                                            {order.warranty_days ? `${order.warranty_days} dia(s)` : 'Não informada'}
                                        </p>
                                        <p className="mt-1 text-sm text-slate-600">{warrantyStatusLabel}</p>
                                    </div>

                                    {order.is_warranty_return && (
                                        <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4">
                                            <p className="font-medium text-slate-900">Retorno em garantia</p>
                                            <p className="mt-1 text-sm text-slate-700">
                                                Esta ordem foi identificada como retorno em garantia
                                                {order.warranty_source_order?.order_number
                                                    ? ` da OS #${order.warranty_source_order.order_number}`
                                                    : ''}
                                                . Esse vínculo é permanente para preservar o histórico do atendimento.
                                            </p>
                                        </div>
                                    )}

                                    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                                        <p className="font-medium text-slate-900">Comprovantes disponíveis</p>
                                        <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                            {hasBudgetReceipt && (
                                                <a
                                                    href={route('os.receipt', { token: order.tracking_token, type: 'ororcamento', pdf: 1 })}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                                >
                                                    <span className="inline-flex items-center gap-2">
                                                        <FileText className="h-4 w-4" />
                                                        Orçamento ao cliente
                                                    </span>
                                                    <ExternalLink className="h-4 w-4 flex-none text-slate-500" />
                                                </a>
                                            )}

                                            {hasDeliveryReceipt && (
                                                <a
                                                    href={route('os.receipt', { token: order.tracking_token, type: 'orentrega', pdf: 1 })}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                                >
                                                    <span className="inline-flex items-center gap-2">
                                                        <ReceiptText className="h-4 w-4" />
                                                        Recibo de entrega
                                                    </span>
                                                    <ExternalLink className="h-4 w-4 flex-none text-slate-500" />
                                                </a>
                                            )}

                                            {hasPaymentProof && (
                                                <a
                                                    href={route('os.payment-proof', { token: order.tracking_token })}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                                >
                                                    <span className="inline-flex items-center gap-2">
                                                        <CreditCard className="h-4 w-4" />
                                                        Comprovante financeiro
                                                    </span>
                                                    <ExternalLink className="h-4 w-4 flex-none text-slate-500" />
                                                </a>
                                            )}

                                            {hasChecklist && (
                                                <a
                                                    href={route('os.receipt', { token: order.tracking_token, type: 'orchecklist', pdf: 1 })}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                                >
                                                    <span className="inline-flex items-center gap-2">
                                                        <FileText className="h-4 w-4" />
                                                        Checklist de entrada
                                                    </span>
                                                    <ExternalLink className="h-4 w-4 flex-none text-slate-500" />
                                                </a>
                                            )}

                                            {hasFiscalProof && (
                                                <a
                                                    href={route('os.fiscal-proof', { token: order.tracking_token })}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="inline-flex items-center justify-between gap-3 rounded-xl border border-slate-300 bg-white px-3 py-3 text-sm font-medium text-slate-700 transition hover:border-slate-400 hover:bg-slate-100"
                                                >
                                                    <span className="inline-flex items-center gap-2">
                                                        <ReceiptText className="h-4 w-4" />
                                                        Comprovante fiscal
                                                    </span>
                                                    <ExternalLink className="h-4 w-4 flex-none text-slate-500" />
                                                </a>
                                            )}
                                        </div>
                                    </div>

                                    {canAcknowledgePickup && (
                                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                                            <p className="font-medium text-slate-900">Confirmação de retirada</p>
                                            <p className="mt-1 text-sm text-slate-700">
                                                Use este botão para confirmar que o equipamento já foi retirado.
                                            </p>

                                            {order.customer_pickup_acknowledged_at ? (
                                                <div className="mt-3 rounded-xl border border-emerald-200 bg-white px-3 py-2 text-sm text-emerald-700">
                                                    Retirada confirmada em {formatDateTime(order.customer_pickup_acknowledged_at)}.
                                                </div>
                                            ) : (
                                                <Button
                                                    onClick={handleAcknowledgePickup}
                                                    disabled={loadingPickup}
                                                    className="mt-3 bg-emerald-600 text-white hover:bg-emerald-700"
                                                >
                                                    {loadingPickup ? 'Confirmando retirada...' : 'Confirmar que retirei o equipamento'}
                                                </Button>
                                            )}
                                        </div>
                                    )}

                                    {order.service_status === ORDER_STATUS.DELIVERED && (
                                        <div className="rounded-2xl border border-violet-200 bg-violet-50 p-4">
                                            <div className="flex items-start gap-3">
                                                <Star className="mt-0.5 h-5 w-5 text-violet-600" />
                                                <div className="w-full space-y-3">
                                                    <p className="font-medium text-slate-900">Como foi seu atendimento?</p>

                                                    {order.customer_feedback_submitted_at ? (
                                                        <div className="rounded-xl border border-emerald-200 bg-white px-4 py-4 text-sm text-slate-700">
                                                            <div className="flex gap-1 text-amber-500">
                                                                {[1, 2, 3, 4, 5].map((rating) => (
                                                                    <Star
                                                                        key={rating}
                                                                        className={`h-5 w-5 ${rating <= (order.customer_feedback_rating ?? 0) ? 'fill-current' : ''}`}
                                                                    />
                                                                ))}
                                                            </div>
                                                            {order.customer_feedback_comment && (
                                                                <p className="mt-3 text-slate-600">{order.customer_feedback_comment}</p>
                                                            )}
                                                        </div>
                                                    ) : (
                                                        <>
                                                            <div className="rounded-2xl border border-violet-200 bg-white p-4">
                                                                <div className="flex flex-wrap gap-2">
                                                                    {[1, 2, 3, 4, 5].map((rating) => {
                                                                        const selected = feedbackRating === rating;

                                                                        return (
                                                                            <button
                                                                                key={rating}
                                                                                type="button"
                                                                                onClick={() => setFeedbackRating(rating)}
                                                                                aria-label={`${rating} estrela${rating > 1 ? 's' : ''}`}
                                                                                className={`inline-flex items-center justify-center rounded-xl border p-3 transition ${
                                                                                    selected
                                                                                        ? 'border-violet-600 bg-violet-600 text-white'
                                                                                        : 'border-violet-200 bg-white text-violet-500 hover:border-violet-300 hover:bg-violet-100'
                                                                                }`}
                                                                            >
                                                                                <Star className={`h-5 w-5 ${selected ? 'fill-current' : ''}`} />
                                                                            </button>
                                                                        );
                                                                    })}
                                                                </div>
                                                            </div>

                                                            <textarea
                                                                value={feedbackComment}
                                                                onChange={(e) => setFeedbackComment(e.target.value)}
                                                                rows={4}
                                                                maxLength={500}
                                                                placeholder="Se quiser, deixe um comentário rápido sobre o atendimento."
                                                                className="w-full rounded-2xl border border-violet-200 bg-white px-4 py-3 text-sm text-slate-700 transition outline-none focus:border-violet-400"
                                                            />
                                                            <p className="mt-1 text-right text-xs text-slate-500">{feedbackComment.length}/500</p>

                                                            <Button
                                                                onClick={handleSubmitFeedback}
                                                                disabled={loadingFeedback || !feedbackRating}
                                                                className="bg-violet-600 text-white hover:bg-violet-700"
                                                            >
                                                                {loadingFeedback ? 'Enviando avaliação...' : 'Enviar avaliação'}
                                                            </Button>
                                                        </>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    )}
                                </div>
                            </section>

                            <section className="rounded-[24px] border border-slate-200 bg-white p-6 shadow-sm">
                                <div className="mb-4 flex items-center gap-3">
                                    <CreditCard className="h-5 w-5 text-slate-500" />
                                    <h2 className="text-lg font-semibold text-slate-900">Pagamentos e liberação</h2>
                                </div>

                                <div className="grid gap-3 sm:grid-cols-3">
                                    <div className="rounded-2xl border border-slate-200 p-4">
                                        <p className="text-sm text-slate-500">Total</p>
                                        <p className="mt-2 font-semibold text-slate-900">{formatCurrency(financialSummary.total)}</p>
                                    </div>
                                    <div className="rounded-2xl border border-slate-200 p-4">
                                        <p className="text-sm text-slate-500">Pago</p>
                                        <p className="mt-2 font-semibold text-emerald-700">{formatCurrency(financialSummary.paid)}</p>
                                    </div>
                                    <div className="rounded-2xl border border-slate-200 p-4">
                                        <p className="text-sm text-slate-500">Saldo</p>
                                        <p
                                            className={`mt-2 font-semibold ${financialSummary.remaining > 0.009 ? 'text-rose-600' : 'text-emerald-700'}`}
                                        >
                                            {formatCurrency(financialSummary.remaining)}
                                        </p>
                                    </div>
                                </div>

                                {(order.order_payments ?? []).length > 0 && (
                                    <div className="mt-4 space-y-3">
                                        {(order.order_payments ?? []).map((payment) => (
                                            <div key={payment.id} className="rounded-2xl border border-slate-200 px-4 py-3">
                                                <div className="flex items-center justify-between gap-3">
                                                    <div>
                                                        <p className="font-medium text-slate-900">{formatCurrency(payment.amount)}</p>
                                                        <p className="text-sm text-slate-500">
                                                            {payment.payment_method || 'Pagamento'} • {formatDateTime(payment.paid_at)}
                                                        </p>
                                                    </div>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                )}
                            </section>
                        </div>
                    </div>

                </div>
            </div>

            <Dialog open={Boolean(selectedImage)} onOpenChange={(open) => !open && setSelectedImage(null)}>
                <DialogContent className="max-w-4xl border-none bg-slate-950 p-3 shadow-2xl">
                    <DialogTitle className="sr-only">Imagem do atendimento</DialogTitle>
                    {selectedImage && (
                        <img src={selectedImage.src} alt={selectedImage.alt} className="max-h-[80vh] w-full rounded-xl object-contain" />
                    )}
                </DialogContent>
            </Dialog>

            <Dialog open={budgetModalOpen} onOpenChange={setBudgetModalOpen}>
                <DialogContent className="max-w-2xl overflow-hidden border-slate-200 bg-white p-0 text-slate-900 [&>button]:text-slate-600 [&>button]:hover:text-slate-950">
                    <div className="border-b border-slate-200 bg-slate-50 px-6 py-5">
                        <DialogTitle className="text-slate-950">Orçamento da OS #{order.order_number}</DialogTitle>
                        <p className="mt-1 text-sm text-slate-600">Confira os detalhes antes de tomar uma decisão.</p>
                    </div>
                    <div className="space-y-5 px-6 py-5">
                        <div>
                            <p className="text-sm font-semibold text-slate-700">Descrição do orçamento</p>
                            <p className="mt-2 rounded-xl border border-slate-200 bg-slate-50 p-4 whitespace-pre-wrap text-slate-950">
                                {order.budget_description || 'Descrição não informada.'}
                            </p>
                        </div>
                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4">
                            <p className="text-sm text-emerald-800">Valor do orçamento</p>
                            <p className="mt-1 text-2xl font-semibold text-emerald-900">{formatCurrency(order.budget_value)}</p>
                        </div>
                        <div className="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-between">
                            <Button
                                variant="outline"
                                asChild
                                className="border-slate-300 bg-white text-slate-800 hover:border-slate-400 hover:bg-slate-100 hover:text-slate-950 focus-visible:ring-slate-400"
                            >
                                <a
                                    href={route('os.receipt', { token: order.tracking_token, type: 'ororcamento', pdf: 1 })}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                >
                                    <ExternalLink className="h-4 w-4" />
                                    Abrir comprovante completo
                                </a>
                            </Button>
                            {order.service_status === ORDER_STATUS.BUDGET_GENERATED && (
                                <div className="flex gap-2">
                                    <Button variant="outline" onClick={handleReject} disabled={loadingR || loadingA}>
                                        {loadingR ? 'Recusando...' : 'Recusar'}
                                    </Button>
                                    <Button onClick={handleApprove} disabled={loadingA || loadingR}>
                                        {loadingA ? 'Aprovando...' : 'Aprovar orçamento'}
                                    </Button>
                                </div>
                            )}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <AlertDialog open={rejectConfirmOpen} onOpenChange={setRejectConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Recusar orçamento?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Ao recusar, a assistência será avisada de que você não aprovou o orçamento enviado.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction className="bg-red-600 text-white hover:bg-red-700" onClick={confirmReject}>
                            Recusar orçamento
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={pickupConfirmOpen} onOpenChange={setPickupConfirmOpen}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Confirmar retirada do equipamento?</AlertDialogTitle>
                        <AlertDialogDescription>Confirme apenas se você já retirou o equipamento na assistência técnica.</AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction onClick={confirmAcknowledgePickup}>Confirmar retirada</AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

export default ServiceOrders;
