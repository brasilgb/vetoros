import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { isValidEan13 } from '@/components/ean13-barcode';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import InvoiceModal from '@/components/Modals/InvoiceModal';
import SelectFilter from '@/components/SelectFilter';
import { StatusBadge } from '@/components/StatusBadge';
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { WhatsAppButton } from '@/components/WhatsAppButtonProps';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { statusServico } from '@/Utils/dataSelect';
import { maskPhone } from '@/Utils/mask';
import { ORDER_STATUS, ORDER_STATUSES_READY_FOR_INVOICE } from '@/Utils/order-status';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    Barcode,
    Camera,
    Clock3,
    Edit,
    FileTextIcon,
    ImageUp,
    LinkIcon,
    Mail,
    MoreHorizontal,
    Plus,
    Printer,
    Trash2,
    Wrench,
    X,
} from 'lucide-react';
import moment from 'moment';
import { useEffect, useRef, useState } from 'react';
import ModalReceipt from '../receipts/modal-receipt';
import OrderPaymentsModal from './order-payments-modal';

declare global {
    interface Window {
        BarcodeDetector?: new (options?: { formats?: string[] }) => {
            detect: (source: CanvasImageSource) => Promise<Array<{ rawValue?: string }>>;
        };
    }
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: '/',
    },
    {
        title: 'Ordens',
        href: '/orders',
    },
];

export default function Orders({ orders, whats, feedback, search, status, filter }: any) {
    const { auth, fiscalSetting, othersetting } = usePage<{
        auth?: { role?: string; permissions?: string[] };
        fiscalSetting?: {
            enabled?: boolean;
            nfse_enabled?: boolean;
        } | null;
        othersetting?: {
            automatic_follow_ups_enabled?: boolean;
            enable_finance?: boolean;
            enablesales?: boolean;
            print_label_button_after_order_create?: boolean;
            show_follow_ups_menu?: boolean;
            show_tasks_menu?: boolean;
            public_order_access_key_required?: boolean;
        };
    }>().props;
    const [openInvoiceModal, setOpenInvoiceModal] = useState(false);
    const [selectedInvoiceOrder, setSelectedInvoiceOrder] = useState<any | null>(null);
    const [receiptOrderId, setReceiptOrderId] = useState<number | null>(null);
    const [deleteOrder, setDeleteOrder] = useState<{ id: number; number: number } | null>(null);
    const barcodeForm = useForm({
        barcode: '',
    });
    const hasActiveFilters = Boolean(search || status || filter);
    const showBarcodeReader = Boolean(othersetting?.print_label_button_after_order_create);
    const [cameraOpen, setCameraOpen] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [cameraSupported, setCameraSupported] = useState(false);
    const [isMobileDevice, setIsMobileDevice] = useState(false);
    const barcodeInputRef = useRef<HTMLInputElement | null>(null);
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);
    const canManageOrders = auth?.role !== 'technician' && auth?.permissions?.includes('orders');
    const canAccessSalesModules =
        auth?.role === 'administrator' || auth?.role === 'operator' || auth?.role === 'root_app' || auth?.role === 'root_system';
    const isFinancialActive = canAccessSalesModules && Boolean(othersetting?.enable_finance) && Boolean(auth?.permissions?.includes('finance'));
    const canIssueServiceInvoice = canManageOrders && Boolean(fiscalSetting?.enabled) && Boolean(fiscalSetting?.nfse_enabled);
    const canShowPendingPaymentButton = canManageOrders && isFinancialActive;
    const feedbackWindowIds = new Set((feedback || []).map((feed: any) => feed.id));

    const handleBudgetFollowUp = (id: number) => {
        router.post(
            route('app.orders.budget-follow-up', id),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const communicationLabel = (communication: any) => {
        if (!communication) return '';

        if (communication.action === 'budget_follow_up_sent') {
            return 'acompanhamento de orçamento';
        }

        return 'cobrança';
    };

    const priorityRowClass = (params: {
        hasPendingPaymentFollowUp: boolean;
        hasBudgetFollowUp: boolean;
        isWarrantyReturn: boolean;
        isFeedbackWindowOpen: boolean;
        hasCustomerFeedback: boolean;
    }) => {
        if (params.hasPendingPaymentFollowUp) return 'bg-rose-100/70 dark:bg-rose-950/25';
        if (params.hasBudgetFollowUp) return 'bg-amber-100/70 dark:bg-amber-950/20';
        if (params.isWarrantyReturn) return 'bg-orange-100/60 dark:bg-orange-950/20';
        if (params.isFeedbackWindowOpen && !params.hasCustomerFeedback) return 'bg-sky-100/70 dark:bg-sky-950/20';

        return '';
    };

    const handleBarcodeSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        const barcode = String(barcodeInputRef.current?.value || barcodeForm.data.barcode || '').trim();
        if (!barcode) return;

        searchByBarcode(barcode);
    };

    const searchByBarcode = (barcode: string) => {
        router.get(
            route('app.orders.index'),
            {
                search: barcode,
            },
            {
                preserveState: false,
                replace: true,
            },
        );
    };

    const handleBarcodeChange = (value: string) => {
        const barcode = value.replace(/\D/g, '');
        barcodeForm.setData('barcode', barcode);

        if (isValidEan13(barcode)) searchByBarcode(barcode);
    };

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
    };

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const hasCameraApi = 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia;
        const mobileByViewport = window.matchMedia('(max-width: 768px)').matches;
        const mobileByPointer = window.matchMedia('(pointer: coarse)').matches;
        const mobileByUserAgent = /Android|iPhone|iPad|iPod|Mobile/i.test(window.navigator.userAgent);

        setCameraSupported(hasCameraApi);
        setIsMobileDevice(mobileByViewport || mobileByPointer || mobileByUserAgent);
    }, []);

    useEffect(() => {
        if (!cameraOpen || !cameraSupported) return;

        let animationFrameId = 0;
        let cancelled = false;
        const detector = window.BarcodeDetector
            ? new window.BarcodeDetector({
                  formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e'],
              })
            : null;

        const scan = async () => {
            if (cancelled || !detector || !videoRef.current) return;

            try {
                const barcodes = await detector.detect(videoRef.current);
                const value = barcodes.find((item) => item.rawValue)?.rawValue?.trim();

                if (value) {
                    barcodeForm.setData('barcode', value);
                    setCameraOpen(false);
                    stopCamera();
                    searchByBarcode(value);
                    return;
                }
            } catch {
                // Ignore intermittent detect errors from the browser scanner loop.
            }

            animationFrameId = window.requestAnimationFrame(scan);
        };

        navigator.mediaDevices
            .getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                },
            })
            .then((stream) => {
                if (cancelled) {
                    stream.getTracks().forEach((track) => track.stop());
                    return;
                }

                streamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    videoRef.current.play().catch(() => null);
                }

                animationFrameId = window.requestAnimationFrame(scan);
            })
            .catch(() => {
                setCameraError('Não foi possível acessar a câmera deste dispositivo.');
            });

        return () => {
            cancelled = true;
            if (animationFrameId) {
                window.cancelAnimationFrame(animationFrameId);
            }
            stopCamera();
        };
    }, [cameraOpen, cameraSupported, filter, status]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ordens" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Wrench} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Ordens</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:flex-wrap lg:items-center lg:justify-between">
                {/* Busca */}
                <div className="flex w-full min-w-0 flex-col gap-2 lg:max-w-[790px] lg:flex-1 lg:flex-row">
                    <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                        <InputSearch placeholder="Buscar OS, cliente, telefone, equipamento ou modelo" url="app.orders.index" />
                    </div>
                    {showBarcodeReader && (
                        <form onSubmit={handleBarcodeSubmit} className="w-full min-w-0 lg:max-w-[360px] lg:flex-1">
                            <div className="flex gap-2">
                                {isMobileDevice ? (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => {
                                            setCameraError(null);
                                            setCameraOpen(true);
                                        }}
                                        disabled={!cameraSupported}
                                        title={
                                            cameraSupported
                                                ? 'Ler código de barras com a câmera do celular'
                                                : 'Leitura por câmera indisponível neste navegador'
                                        }
                                        className="w-full"
                                    >
                                        <Camera className="mr-1 h-4 w-4" />
                                        Ler com celular
                                    </Button>
                                ) : (
                                    <div className="relative flex-1">
                                        <Input
                                            ref={barcodeInputRef}
                                            value={barcodeForm.data.barcode}
                                            onChange={(e) => handleBarcodeChange(e.target.value)}
                                            placeholder="Ler com leitor de código de barras"
                                            autoComplete="off"
                                            className="pr-12"
                                        />
                                        <Button
                                            type="submit"
                                            size="icon"
                                            className="absolute top-0 right-0 h-full rounded-l-none"
                                            title="Buscar pela leitura do leitor de código de barras"
                                            aria-label="Buscar pela leitura do leitor de código de barras"
                                        >
                                            <Barcode className="h-4 w-4" />
                                        </Button>
                                    </div>
                                )}
                            </div>
                        </form>
                    )}
                </div>

                {/* Filtro */}
                <div className="flex w-full flex-col gap-2 md:flex-row lg:w-auto lg:shrink-0">
                    <SelectFilter
                        dataStatus={statusServico}
                        specialFilters={[
                            { value: 'overdue', label: 'Prazo vencido' },
                            { value: 'unassigned', label: 'Sem técnico' },
                            { value: 'awaiting_pickup', label: 'Aguardando retirada' },
                            { value: 'feedback', label: 'Feedback pendente' },
                            { value: 'budget_follow_up', label: 'Orçamento parado' },
                            { value: 'pending_payment_follow_up', label: 'Cobrança pendente' },
                            { value: 'due_48h', label: 'Vencendo hoje e amanhã' },
                            { value: 'financial_open', label: 'Em aberto no financeiro' },
                            { value: 'active_warranty', label: 'Garantia ativa' },
                            { value: 'warranty_return', label: 'Retorno em garantia' },
                        ]}
                        url="app.orders.index"
                    />
                    {hasActiveFilters && (
                        <Button variant="outline" asChild>
                            <Link href={route('app.orders.index')}>
                                <X className="mr-1 h-4 w-4" />
                                Limpar filtro
                            </Link>
                        </Button>
                    )}
                </div>

                {/* Botões */}
                <div className="flex w-full flex-col gap-2 md:flex-row lg:w-auto lg:shrink-0 lg:justify-end">
                    {canManageOrders && (
                        <Button variant="default" asChild className="w-full whitespace-nowrap md:w-auto">
                            <Link href={route('app.orders.create')}>
                                <Plus className="mr-1 h-4 w-4" />
                                <span>Nova Ordem</span>
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <div className="p-4">
                <PaginationSummary data={orders} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[100px]">#</TableHead>
                                <TableHead>Cliente</TableHead>
                                <TableHead>Equipamento</TableHead>
                                <TableHead>Datas</TableHead>
                                <TableHead>Status</TableHead>
                                {canManageOrders && <TableHead>Feedback</TableHead>}
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {orders?.data.length > 0 ? (
                                orders?.data?.map((order: any) => {
                                    const customer = order.customer;
                                    const customerName = customer?.name || 'Cliente não vinculado';
                                    const customerPhone = customer?.phone ? maskPhone(customer.phone) : '-';
                                    const hasCustomerFeedback = Boolean(order.customer_feedback_submitted_at);
                                    const isFeedbackWindowOpen = feedbackWindowIds.has(order.id);
                                    const totalOrder = Number(order.service_cost ?? 0);
                                    const totalPaid = Number(order.total_paid ?? 0);
                                    const remaining = Math.max(0, totalOrder - totalPaid);
                                    const hasFinancialValuesFilled =
                                        order.parts_value !== null &&
                                        order.parts_value !== '' &&
                                        order.service_value !== null &&
                                        order.service_value !== '' &&
                                        totalOrder > 0;
                                    const isDelivered = Number(order.service_status) === ORDER_STATUS.DELIVERED;
                                    const hasPendingPayment = remaining > 0.009;
                                    const canIssueInvoiceWithValue = totalOrder > 0;
                                    const hasFiscalRegistered = Boolean(order?.fiscal_document_number || order?.fiscal_document_url);
                                    const hasBudgetFollowUp = Boolean(order.budget_follow_up);
                                    const hasPendingPaymentFollowUp = Boolean(order.pending_payment_follow_up);
                                    const communicationDaysPending = Number(order.communication_days_pending ?? 0);
                                    const imagesCount = Number(order.images_count ?? 0);
                                    const feedbackDaysPending =
                                        !hasCustomerFeedback && order.delivery_date
                                            ? Math.max(0, moment().diff(moment(order.delivery_date), 'days'))
                                            : 0;
                                    const whatsappContext: 'default' | 'budget_follow_up' | 'pending_payment' = hasPendingPaymentFollowUp
                                        ? 'pending_payment'
                                        : hasBudgetFollowUp
                                          ? 'budget_follow_up'
                                          : 'default';
                                    const rowClassName = priorityRowClass({
                                        hasPendingPaymentFollowUp,
                                        hasBudgetFollowUp,
                                        isWarrantyReturn: Boolean(order.is_warranty_return),
                                        isFeedbackWindowOpen,
                                        hasCustomerFeedback,
                                    });
                                    const equipmentTitle = order.equipment?.equipment ?? 'Equipamento não informado';
                                    const equipmentSubtitle = order.model || 'Modelo não informado';
                                    const statusReference = order.status_changed_at || order.updated_at || order.created_at;
                                    const statusAgeDays = Math.max(0, moment().diff(moment(statusReference), 'days'));
                                    const statusAgeLabel =
                                        Number(order.service_status) === ORDER_STATUS.CUSTOMER_NOTIFIED
                                            ? statusAgeDays === 0
                                                ? 'Pronto para retirada hoje'
                                                : `Aguardando retirada há ${statusAgeDays} ${statusAgeDays === 1 ? 'dia' : 'dias'}`
                                            : statusAgeDays === 0
                                              ? 'Atualizado hoje'
                                              : `${statusAgeDays} ${statusAgeDays === 1 ? 'dia' : 'dias'} neste status`;

                                    return (
                                        <TableRow key={order.id} className={rowClassName}>
                                            <TableCell>{order.order_number}</TableCell>
                                            <TableCell className="font-medium">
                                                <div className="space-y-1">
                                                    {customer ? (
                                                        <Link
                                                            className="flex items-center gap-2"
                                                            href={route('app.orders.index', { search: customerName })}
                                                            title={`Ordens do cliente ${customerName}`}
                                                        >
                                                            <Wrench className="h-4 w-4" />
                                                            <span>{customerName}</span>
                                                        </Link>
                                                    ) : (
                                                        <div className="flex items-center gap-2">
                                                            <Wrench className="h-4 w-4" />
                                                            <span>{customerName}</span>
                                                        </div>
                                                    )}
                                                    <div className="text-muted-foreground text-xs">{customerPhone}</div>
                                                    {order.last_communication?.created_at && (
                                                        <div className="text-muted-foreground text-xs">
                                                            Último contato: {communicationLabel(order.last_communication)}{' '}
                                                            {order.last_communication?.trigger === 'automatic' ? 'automático' : 'manual'}{' '}
                                                            {moment(order.last_communication.created_at).fromNow()}
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div className="font-medium">{equipmentTitle}</div>
                                                    <div className="text-muted-foreground text-xs">{equipmentSubtitle}</div>
                                                    <div className="text-muted-foreground text-xs">Senha: {order.password || '-'}</div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="space-y-1 text-sm">
                                                    <div>
                                                        <span className="text-muted-foreground">Entrada:</span>{' '}
                                                        {moment(order.created_at).format('DD/MM/YYYY')}
                                                    </div>
                                                    <div>
                                                        <span className="text-muted-foreground">Previsão:</span>{' '}
                                                        {order.delivery_forecast ? moment(order.delivery_forecast).format('DD/MM/YYYY') : '-'}
                                                    </div>
                                                    <div>
                                                        <span className="text-muted-foreground">Entrega:</span>{' '}
                                                        {order.delivery_date ? moment(order.delivery_date).format('DD/MM/YYYY') : '-'}
                                                    </div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <StatusBadge category="ordem" value={order.service_status} />
                                                    {!isDelivered && (
                                                        <span
                                                            className="text-muted-foreground inline-flex items-center gap-1 text-xs"
                                                            title={`Última mudança de status em ${moment(statusReference).format('DD/MM/YYYY [às] HH:mm')}`}
                                                        >
                                                            <Clock3 className="h-3.5 w-3.5" />
                                                            {statusAgeLabel}
                                                        </span>
                                                    )}
                                                    {hasPendingPaymentFollowUp && (
                                                        <Badge className="bg-rose-100 text-rose-800 hover:bg-rose-100 dark:bg-rose-500/20 dark:text-rose-200 dark:hover:bg-rose-500/20">
                                                            Cobrança pendente
                                                        </Badge>
                                                    )}
                                                    {Boolean(order.is_warranty_return) && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="w-fit bg-amber-100 text-amber-900 hover:bg-amber-100 dark:bg-amber-500/20 dark:text-amber-200 dark:hover:bg-amber-500/20"
                                                        >
                                                            Retorno garantia
                                                        </Badge>
                                                    )}
                                                    {order.warranty_expires_at && moment(order.warranty_expires_at).isSameOrAfter(moment()) && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="w-fit bg-emerald-100 text-emerald-900 hover:bg-emerald-100 dark:bg-emerald-500/20 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                                                        >
                                                            Garantia até {moment(order.warranty_expires_at).format('DD/MM/YYYY')}
                                                        </Badge>
                                                    )}
                                                    {order.warranty_expires_at && moment(order.warranty_expires_at).isBefore(moment()) && (
                                                        <Badge variant="outline" className="w-fit text-slate-500">
                                                            Garantia encerrada em {moment(order.warranty_expires_at).format('DD/MM/YYYY')}
                                                        </Badge>
                                                    )}
                                                    {hasBudgetFollowUp && (
                                                        <Tooltip>
                                                            <TooltipTrigger asChild>
                                                                <Badge className="cursor-help bg-yellow-100 text-yellow-900 hover:bg-yellow-100 dark:bg-yellow-500/20 dark:text-yellow-200 dark:hover:bg-yellow-500/20">
                                                                    <AlertTriangle className="mr-1 h-3.5 w-3.5" />
                                                                    Orçamento parado
                                                                </Badge>
                                                            </TooltipTrigger>
                                                            <TooltipContent>
                                                                Orçamento aguardando retorno há {communicationDaysPending} dias.
                                                            </TooltipContent>
                                                        </Tooltip>
                                                    )}
                                                </div>
                                            </TableCell>
                                            {canManageOrders && (
                                                <TableCell>
                                                    {hasCustomerFeedback && (
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <Badge>Avaliado</Badge>
                                                            {order.customer_feedback_rating ? (
                                                                <Badge variant="secondary">Nota {order.customer_feedback_rating}/5</Badge>
                                                            ) : null}
                                                        </div>
                                                    )}

                                                    {!hasCustomerFeedback && isFeedbackWindowOpen && (
                                                        <Badge className="bg-sky-100 text-sky-800 hover:bg-sky-100 dark:bg-sky-500/20 dark:text-sky-200 dark:hover:bg-sky-500/20">
                                                            {feedbackDaysPending > 0 ? `Aguardando ${feedbackDaysPending} dias` : 'Aguardando'}
                                                        </Badge>
                                                    )}
                                                </TableCell>
                                            )}
                                            <TableCell className="min-w-[190px]">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {canManageOrders && hasFiscalRegistered && (
                                                        <Badge
                                                            variant="secondary"
                                                            className="bg-emerald-100 text-emerald-800 hover:bg-emerald-100 dark:bg-emerald-500/20 dark:text-emerald-200 dark:hover:bg-emerald-500/20"
                                                        >
                                                            NFSe registrada
                                                        </Badge>
                                                    )}

                                                    {canShowPendingPaymentButton && isDelivered && hasFinancialValuesFilled && hasPendingPayment && (
                                                        <OrderPaymentsModal
                                                            order={order}
                                                            orderPayments={[]}
                                                            paymentSummary={null}
                                                            compactTrigger
                                                            compactTriggerClassName="bg-rose-600 text-white hover:bg-rose-700"
                                                            compactTriggerTitle="Pagamento pendente"
                                                        />
                                                    )}

                                                    {canManageOrders && (
                                                        <WhatsAppButton
                                                            phone={customer?.whatsapp ?? ''}
                                                            customerName={customerName}
                                                            orderId={order.id}
                                                            orderNumber={order.order_number}
                                                            status={order.service_status}
                                                            feedback={isFeedbackWindowOpen && !hasCustomerFeedback}
                                                            context={whatsappContext}
                                                            amountDue={remaining}
                                                            daysPending={communicationDaysPending}
                                                            financialValues={order.whatsapp_values}
                                                            whats={{
                                                                generatedbudget: whats?.generatedbudget,
                                                                servicecompleted: whats?.servicecompleted,
                                                                feedback: whats?.feedback,
                                                                defaultmessage: whats?.defaultmessage,
                                                                budgetfollowup: whats?.budgetfollowup,
                                                                pendingpayment: whats?.pendingpayment,
                                                                tracking_token: order?.tracking_token,
                                                                public_access_key: order?.public_access_key_value,
                                                                public_access_key_required: Boolean(othersetting?.public_order_access_key_required),
                                                            }}
                                                        />
                                                    )}

                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        title="Editar ordem"
                                                    >
                                                        <Link
                                                            href={route('app.orders.edit', order.id)}
                                                            data={{ page: orders.current_page, search: search, status: status, filter: filter }}
                                                            aria-label={`Editar ordem ${order.order_number}`}
                                                        >
                                                            <Edit className="h-4 w-4" />
                                                        </Link>
                                                    </Button>

                                                    {canManageOrders && (
                                                        <DropdownMenu>
                                                            <DropdownMenuTrigger asChild>
                                                                <Button
                                                                    type="button"
                                                                    size="icon"
                                                                    variant="outline"
                                                                    title="Mais ações"
                                                                    aria-label={`Mais ações da ordem ${order.order_number}`}
                                                                >
                                                                    <MoreHorizontal className="h-4 w-4" />
                                                                </Button>
                                                            </DropdownMenuTrigger>
                                                            <DropdownMenuContent align="end" className="w-56">
                                                                <DropdownMenuItem asChild>
                                                                    <a target="_blank" href={route('os.token', order?.tracking_token)}>
                                                                        <LinkIcon />
                                                                        Acompanhamento público
                                                                    </a>
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem onSelect={() => setReceiptOrderId(order.id)}>
                                                                    <Printer />
                                                                    Imprimir recibo
                                                                </DropdownMenuItem>
                                                                <DropdownMenuItem asChild>
                                                                    <Link href={route('app.images.index', { or: order.id })}>
                                                                        <ImageUp />
                                                                        Imagens
                                                                        {imagesCount > 0 && (
                                                                            <Badge variant="secondary" className="ml-auto">
                                                                                {imagesCount}
                                                                            </Badge>
                                                                        )}
                                                                    </Link>
                                                                </DropdownMenuItem>
                                                                {hasBudgetFollowUp && order.can_send_budget_follow_up && (
                                                                    <DropdownMenuItem onSelect={() => handleBudgetFollowUp(order.id)}>
                                                                        <Mail />
                                                                        Cobrar orçamento
                                                                    </DropdownMenuItem>
                                                                )}
                                                                {canIssueServiceInvoice &&
                                                                    ORDER_STATUSES_READY_FOR_INVOICE.includes(Number(order.service_status)) &&
                                                                    canIssueInvoiceWithValue && (
                                                                        <DropdownMenuItem
                                                                            onSelect={() => {
                                                                                setSelectedInvoiceOrder(order);
                                                                                setOpenInvoiceModal(true);
                                                                            }}
                                                                        >
                                                                            <FileTextIcon />
                                                                            Emitir NFSe
                                                                        </DropdownMenuItem>
                                                                    )}
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onSelect={() => setDeleteOrder({ id: order.id, number: order.order_number })}
                                                                >
                                                                    <Trash2 />
                                                                    Excluir OS
                                                                </DropdownMenuItem>
                                                            </DropdownMenuContent>
                                                        </DropdownMenu>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={canManageOrders ? 7 : 6} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={canManageOrders ? 7 : 6}>
                                    <AppPagination data={orders} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
            {selectedInvoiceOrder && (
                <InvoiceModal
                    open={openInvoiceModal}
                    onClose={() => {
                        setOpenInvoiceModal(false);
                        setSelectedInvoiceOrder(null);
                    }}
                    order={selectedInvoiceOrder}
                />
            )}
            {receiptOrderId !== null && (
                <ModalReceipt
                    orderid={receiptOrderId}
                    open
                    hideTrigger
                    onOpenChange={(open) => {
                        if (!open) setReceiptOrderId(null);
                    }}
                />
            )}
            <AlertDialog open={deleteOrder !== null} onOpenChange={(open) => !open && setDeleteOrder(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Excluir a OS #{deleteOrder?.number}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Esta ação não pode ser desfeita e removerá permanentemente os dados associados à ordem.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-red-600 text-white hover:bg-red-700"
                            onClick={() => {
                                if (!deleteOrder) return;
                                router.delete(route('app.orders.destroy', deleteOrder.id), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleteOrder(null),
                                });
                            }}
                        >
                            Excluir
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
            {cameraOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/75 p-4">
                    <div className="bg-background w-full max-w-md rounded-xl p-4 shadow-xl">
                        <div className="mb-3 flex items-center justify-between">
                            <div>
                                <h3 className="font-semibold">Ler código de barras</h3>
                                <p className="text-muted-foreground text-sm">Aponte a câmera para a etiqueta da ordem.</p>
                            </div>
                            <Button
                                type="button"
                                variant="ghost"
                                size="icon"
                                aria-label="Fechar leitor de código de barras"
                                onClick={() => {
                                    setCameraOpen(false);
                                    stopCamera();
                                }}
                            >
                                <X className="h-4 w-4" />
                            </Button>
                        </div>

                        <div className="overflow-hidden rounded-lg border bg-black">
                            <video ref={videoRef} autoPlay playsInline muted className="aspect-[3/4] w-full object-cover" />
                        </div>

                        {cameraError ? <p className="mt-3 text-sm text-rose-600">{cameraError}</p> : null}

                        <div className="mt-3 flex justify-end">
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => {
                                    setCameraOpen(false);
                                    stopCamera();
                                }}
                            >
                                Fechar
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </AppLayout>
    );
}
