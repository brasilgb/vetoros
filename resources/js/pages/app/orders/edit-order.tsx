import { toastSuccess } from '@/components/app-toast-messages';
import AsyncResourceSelect from '@/components/async-resource-select';
import { DatePicker } from '@/components/date-picker';
import FormFieldHelp from '@/components/form-field-help';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import InvoiceModal from '@/components/Modals/InvoiceModal';
import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, OptionType } from '@/types';
import { statusServico } from '@/Utils/dataSelect';
import { maskMoney, maskMoneyDot } from '@/Utils/mask';
import { ORDER_STATUS, ORDER_STATUSES_READY_FOR_INVOICE } from '@/Utils/order-status';
import selectStyles from '@/Utils/selectStyles';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Copy, FileTextIcon, KeyRound, Mail, MessageSquareText, Printer, Save, Wrench, X } from 'lucide-react';
import moment from 'moment';
import { useEffect, useState, type FormEvent } from 'react';
import Select from 'react-select';
import AddPartsModal from './add-parts';
import CustomerEquipmentField from './customer-equipment-field';
import OrderPreBudgetFields from './order-pre-budget-fields';
import OrderPaymentsModal from './order-payments-modal';

function formatRelativeTimePtBr(value?: string | Date | null) {
    if (!value) return '-';

    const date = value instanceof Date ? value : new Date(value);
    const diffMs = date.getTime() - Date.now();
    const diffSeconds = Math.round(diffMs / 1000);
    const absSeconds = Math.abs(diffSeconds);
    const rtf = new Intl.RelativeTimeFormat('pt-BR', { numeric: 'auto' });

    if (absSeconds < 60) return rtf.format(diffSeconds, 'second');

    const diffMinutes = Math.round(diffSeconds / 60);
    if (Math.abs(diffMinutes) < 60) return rtf.format(diffMinutes, 'minute');

    const diffHours = Math.round(diffMinutes / 60);
    if (Math.abs(diffHours) < 24) return rtf.format(diffHours, 'hour');

    const diffDays = Math.round(diffHours / 24);
    if (Math.abs(diffDays) < 30) return rtf.format(diffDays, 'day');

    const diffMonths = Math.round(diffDays / 30);
    if (Math.abs(diffMonths) < 12) return rtf.format(diffMonths, 'month');

    const diffYears = Math.round(diffDays / 365);
    return rtf.format(diffYears, 'year');
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Ordens',
        href: route('app.orders.index'),
    },
    {
        title: 'Editar',
        href: '#',
    },
];

export default function EditOrder({
    order,
    technicals,
    equipments,
    parts,
    orderparts,
    orderPayments,
    paymentSummary,
    equipmentHistory,
    warrantySourceOrders,
    publicAccessKey,
    page,
    search,
    status,
    filter,
}: any) {
    const budgetFollowUpForm = useForm({});
    const [customerUpdateOpen, setCustomerUpdateOpen] = useState(false);
    const customerUpdateForm = useForm({ note: '' });
    const [selectedCustomer, setSelectedCustomer] = useState<OptionType | null>(
        order?.customer ? { value: order.customer.id, label: order.customer.name } : null,
    );

    const handleSendCustomerUpdate = (e: FormEvent) => {
        e.preventDefault();
        customerUpdateForm.post(route('app.orders.customer-update', order.id), {
            preserveScroll: true,
            onSuccess: () => {
                setCustomerUpdateOpen(false);
                customerUpdateForm.reset('note');
            },
        });
    };

    const communicationLabel = (communication: any) => {
        if (!communication) return '';

        if (communication.action === 'budget_follow_up_sent') {
            return 'Acompanhamento de orçamento';
        }

        return 'Cobrança';
    };

    const toMoneyNumber = (value: unknown): number => {
        if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
        const raw = String(value ?? '').trim();
        if (!raw) return 0;

        const normalized = raw.includes(',') ? raw.replace(/\./g, '').replace(',', '.') : raw.replace(/,/g, '');

        const parsed = Number(normalized);
        return Number.isFinite(parsed) ? parsed : 0;
    };

    const { othersetting, auth, fiscalSetting } = usePage().props as any;
    const canManageOrders = auth?.role !== 'technician' && auth?.permissions?.includes('orders');
    const canAccessSalesModules =
        auth?.role === 'administrator' || auth?.role === 'operator' || auth?.role === 'root_app' || auth?.role === 'root_system';
    const canManagePayments = canManageOrders && canAccessSalesModules && Boolean(othersetting?.enable_finance) && Boolean(auth?.permissions?.includes('finance'));
    const canIssueServiceInvoice = canManageOrders && Boolean(fiscalSetting?.enabled) && Boolean(fiscalSetting?.nfse_enabled);
    const [partsData, setPartsData] = useState<any>([]);


    const optionsTechnical = technicals.map((technical: any) => ({
        value: technical.id,
        label: technical.name,
    }));

    const optionsEquipment = equipments.map((equipment: any) => ({
        value: equipment.id,
        label: equipment.equipment,
    }));

    const { data, post, setData, patch, progress, processing, reset, errors } = useForm({
        order_type: 'equipment',
        customer_id: order?.customer_id,
        equipment_id: order?.equipment_id, // equipamento
        customer_equipment_id: order?.customer_equipment_id ? String(order.customer_equipment_id) : '',
        user_id: order?.user_id,
        model: order?.model,
        password: order?.password,
        defect: order?.defect,
        service_type: '',
        service_details: '',
        materials_used: '',
        state_conservation: order?.state_conservation, //estado de conservação
        accessories: order?.accessories,
        budget_description: order?.budget_description, // descrição do orçamento
        budget_value: order?.budget_value, // valor do orçamento
        budget_link: order?.budget_link ?? '',
        services_performed: order.services_performed, // servicos executados
        parts_value: order.parts_value,
        service_value: order.service_value,
        service_cost: order.service_cost, // custo
        delivery_date: order.delivery_date,
        warranty_days: order?.warranty_days ?? '',
        is_warranty_return: Boolean(order?.is_warranty_return),
        warranty_source_order_id: order?.warranty_source_order_id ? String(order.warranty_source_order_id) : '',
        service_status: order?.service_status,
        delivery_forecast: order?.delivery_forecast, // previsao de entrega
        observations: order?.observations,
        allparts: '',
    });

    const [openInvoiceModal, setOpenInvoiceModal] = useState(false);
    const [publicKeyModalOpen, setPublicKeyModalOpen] = useState(false);
    const eligibleWarrantyOrders = (warrantySourceOrders ?? []).filter(
        (item: any) => String(item.customer_id) === String(data.customer_id) && String(item.equipment_id) === String(data.equipment_id),
    );

    const handleModalSubmit = (modalParts: any) => {
        setPartsData((currentLocalParts: any) => {
            const partsMap = new Map(currentLocalParts.map((p: any) => [p.id, p]));
            modalParts.forEach((part: any) => {
                partsMap.set(part.id, part); // Adiciona ou atualiza a peça
            });
            return Array.from(partsMap.values());
        });
    };

    const handleSubmit = async (e: any) => {
        e.preventDefault();

        patch(route('app.orders.update', { order: order.id, page, search, status, filter }), {
            onSuccess: () => {
                setPartsData([]);
            },
        });
    };

    useEffect(() => {
        const finalPartsMap = new Map();
        (orderparts || []).forEach((p: any) => finalPartsMap.set(p.id, { id: p.id, sale_price: p.sale_price, quantity: p.pivot.quantity }));
        (partsData || []).forEach((p: any) => finalPartsMap.set(p.id, { id: p.id, sale_price: p.sale_price, quantity: p.quantity }));

        const finalListWithDetails = Array.from(finalPartsMap.values());
        const allpartsPayload = finalListWithDetails.map((p) => ({ part_id: p.id, quantity: p.quantity }));

        // Calcula o total. Se não houver peças, o total é 0.
        const totalValue = finalListWithDetails.reduce((acc, p) => acc + toMoneyNumber(p.sale_price) * Number(p.quantity || 0), 0);

        setData((currentData: any) => ({
            ...currentData,
            allparts: allpartsPayload,
            // Se o total for 0 e não houver peças, fixa 0.
            // Removido a condição "totalValue ? totalValue : order?.parts_value"
            // que causava a insistência no valor antigo do banco.
            parts_value: totalValue.toFixed(2),
        }));
    }, [partsData, orderparts]);

    useEffect(() => {
        // Converte para número, mas garante que se for vazio ou NaN, vire 0
        const pValue = toMoneyNumber(data?.parts_value);
        const sValue = toMoneyNumber(data?.service_value);

        const total = pValue + sValue;

        // Atualiza o custo total formatado
        setData((prev: any) => ({
            ...prev,
            service_cost: total.toFixed(2),
        }));

    }, [data.parts_value, data.service_value]);

    useEffect(() => {
        const status = Number(data.service_status);

        if (status === ORDER_STATUS.DELIVERED && !data.delivery_date) {
            setData((currentData: any) => ({ ...currentData, delivery_date: moment().format('YYYY-MM-DD') }));
        }

        if (status !== ORDER_STATUS.DELIVERED && data.delivery_date) {
            setData((currentData: any) => ({ ...currentData, delivery_date: '' }));
        }
    }, [data.service_status]);

    const changeCustomer = (selected: any) => {
        setData((current) => ({
            ...current,
            customer_id: selected?.value || '',
            customer_equipment_id: '',
            equipment_id: '',
            model: '',
        }));
        setSelectedCustomer(selected ?? null);
    };

    const changeServiceStatus = (selected: any) => {
        setData('service_status', selected?.value);
    };

    const copyBudgetLink = async () => {
        const link = String(data.budget_link ?? '').trim();

        if (!link || typeof navigator === 'undefined' || !navigator.clipboard) {
            return;
        }

        await navigator.clipboard.writeText(link);
        toastSuccess('Copiado', 'Link do orçamento copiado para a área de transferência');
    };

    const changeResponsibleTechnician = (selected: any) => {
        setData('user_id', selected?.value);
    };

    const selectedEquipment = optionsEquipment?.find((o: any) => String(o.value) === String(data.equipment_id)) ?? null;
    const statusDefault = statusServico
        ?.filter((o: any) => o.value == order?.service_status)
        .map((opt: any) => ({ value: opt.value, label: opt.label }));
    const defaultTechnical = optionsTechnical
        ?.filter((o: any) => o.value == order?.user_id)
        .map((opt: any) => ({ value: opt.value, label: opt.label }));

    const handleRemovePartsOrder = (e: any, id: number) => {
        e.preventDefault();
        post(route('app.orders.removePart', { order_id: order.id, part_id: id }));
        // const calcTotal =
        setData((data: any) => ({ ...data, parts_value: '0' }));
        setData((data: any) => ({ ...data, service_value: '0' }));
        setData((data: any) => ({ ...data, service_cost: '0' }));
        setData((data: any) => ({ ...data, allparts: [] }));
        setPartsData([]);
    };

    const handleRemovePart = (partId: any) => {
        setPartsData((currentLocalParts: any) => currentLocalParts.filter((part: any) => part.id !== partId));
    };

    const combinedParts = [
        ...(orderparts || []).map((part: any) => ({
            id: part.id,
            name: part.name, // 🔥 essencial
            sale_price: part.sale_price,
            quantity: part.pivot.quantity,
            source: 'database',
        })),
        ...(partsData || []).map((part: any) => ({
            id: part.id,
            name: part.name,
            sale_price: part.sale_price,
            quantity: part.quantity,
            source: 'local',
        })),
    ];

    const currentStatusLabel = statusServico.find((item: any) => Number(item.value) === Number(data.service_status))?.label ?? 'Status';
    const isDeliveryStatus = Number(data.service_status) === ORDER_STATUS.DELIVERED;
    const canIssueInvoiceWithValue = Number(order.service_cost ?? 0) > 0;
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ordens" />
            {canIssueServiceInvoice && <InvoiceModal open={openInvoiceModal} onClose={() => setOpenInvoiceModal(false)} order={order} summary={paymentSummary} />}
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Wrench} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Ordens</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <Button variant={'default'} asChild>
                        <Link href={route('app.orders.index', { page: page, search: search, status: status, filter: filter })} preserveState={false}>
                            <ArrowLeft className="h-4 w-4" />
                            <span>Voltar</span>
                        </Link>
                    </Button>
                </div>
                <div className="flex flex-wrap items-center justify-start gap-2 md:justify-end">
                    {Boolean(othersetting?.print_label_button_after_order_create) && (
                        <Button type="button" variant="outline" asChild>
                            <a
                                href={route('app.label-printing.print', {
                                    initialorder: order.order_number,
                                    quantity: 1,
                                    format: 'thermal',
                                })}
                                target="_blank"
                                rel="noopener noreferrer"
                            >
                                <Printer className="h-4 w-4" />
                                Imprimir etiqueta
                            </a>
                        </Button>
                    )}
                    {canIssueServiceInvoice && ORDER_STATUSES_READY_FOR_INVOICE.includes(Number(order.service_status)) && canIssueInvoiceWithValue && (
                        <Button onClick={() => setOpenInvoiceModal(true)} className="rounded-lg py-2 text-sm font-medium">
                            <FileTextIcon className="h-4 w-4" />
                            Emitir NFSe
                        </Button>
                    )}
                    {canManageOrders && order.can_send_budget_follow_up && (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={budgetFollowUpForm.processing}
                            onClick={() =>
                                budgetFollowUpForm.post(route('app.orders.budget-follow-up', order.id), {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <Mail className="h-4 w-4" />
                            {budgetFollowUpForm.processing ? 'Enviando...' : 'Cobrar orçamento'}
                        </Button>
                    )}
                    {canManageOrders && Number(order.service_status) !== ORDER_STATUS.DELIVERED && (
                        <Button type="button" variant="outline" onClick={() => setCustomerUpdateOpen(true)}>
                            <MessageSquareText className="h-4 w-4" />
                            Enviar atualização ao cliente
                        </Button>
                    )}
                    {canManagePayments && (
                        <OrderPaymentsModal
                            order={order}
                            orderPayments={orderPayments}
                            paymentSummary={paymentSummary}
                            defaultOpen={typeof window !== 'undefined' && new URLSearchParams(window.location.search).get('open_payments') === '1'}
                        />
                    )}
                    <AddPartsModal onSubmit={handleModalSubmit} parts={parts} />
                </div>
            </div>

            <div className="p-4">
                <div className="rounded-lg border p-2">
                    <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                        <div className="bg-background flex flex-col gap-2 rounded-lg p-4 shadow-sm">
                            <div className="flex items-center gap-2">
                                <span className="text-foreground text-2xl font-bold tracking-tight">Ordem N° {order.order_number}</span>
                                <Badge variant="outline">{currentStatusLabel}</Badge>
                            </div>

                            <div className="text-muted-foreground flex flex-col gap-2 text-sm sm:flex-row sm:items-center sm:gap-6">
                                <div className="flex items-center gap-1">
                                    <Icon iconNode={Save} className="text-muted-foreground h-4 w-4" />
                                    <span>Criada em: {moment(order.created_at).format('DD/MM/YYYY [às] HH:mm')}</span>
                                </div>

                                {order.updated_at && (
                                    <div className="border-muted-foreground/30 flex items-center gap-1 border-l pl-4">
                                        <span>Última atualização: {formatRelativeTimePtBr(order.updated_at)}</span>
                                    </div>
                                )}
                            </div>

                            {order.last_communication?.created_at && (
                                <div className="text-muted-foreground flex flex-wrap items-center gap-2 text-sm">
                                    <Badge variant="outline">Último contato</Badge>
                                    <span>
                                        {communicationLabel(order.last_communication)}{' '}
                                        {order.last_communication?.trigger === 'automatic' ? 'automático' : 'manual'} por e-mail{' '}
                                        {moment(order.last_communication.created_at).format('DD/MM/YYYY [às] HH:mm')}
                                    </span>
                                </div>
                            )}
                        </div>

                        <Tabs defaultValue="details" className="space-y-4">
                            <TabsList>
                                <TabsTrigger value="details">Detalhes</TabsTrigger>
                                <TabsTrigger value="history">Histórico</TabsTrigger>
                            </TabsList>

                            <TabsContent value="details" className="space-y-6">
                                <Card>
                                    <CardTitle className="border-b px-6 pb-4">Cliente e equipamento</CardTitle>
                                    <CardContent className="space-y-4 pt-6">
                                        <div className="grid gap-4 md:grid-cols-10">
                                            <div className="grid gap-2 md:col-span-2">
                                                <Label htmlFor="customer_id">Cliente</Label>
                                                <div className="flex min-w-0 items-center gap-2">
                                                    <AsyncResourceSelect
                                                        inputId="customer_id"
                                                        searchUrl={route('app.customers.search')}
                                                        value={selectedCustomer}
                                                        onChange={changeCustomer}
                                                        placeholder="Digite o nome do cliente..."
                                                        className="min-w-0 flex-1"
                                                    />
                                                    {publicAccessKey && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="icon"
                                                            title="Ver chave da página pública"
                                                            aria-label="Ver chave da página pública"
                                                            onClick={() => setPublicKeyModalOpen(true)}
                                                        >
                                                            <KeyRound className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                                <InputError className="mt-2" message={errors.customer_id} />
                                            </div>

                                            <div className="grid gap-2 md:col-span-2">
                                                <Label htmlFor="customer_equipment_id">Equipamento do cliente *</Label>
                                                <CustomerEquipmentField
                                                    customerId={data.customer_id}
                                                    equipmentTypes={equipments}
                                                    initialDevice={order?.customer_equipment ?? null}
                                                    onChange={(customerEquipmentId, device) => {
                                                        setData('customer_equipment_id', customerEquipmentId);
                                                        if (device) {
                                                            setData('equipment_id', String(device.equipment_id ?? ''));
                                                            setData('model', [device.brand, device.model].filter(Boolean).join(' '));
                                                        }
                                                    }}
                                                />
                                                <InputError message={errors.customer_equipment_id} />
                                            </div>

                                            <div className="grid gap-2 md:col-span-2">
                                                <Label htmlFor="equipment">Tipo de equipamento</Label>
                                                <Input
                                                    id="equipment"
                                                    value={selectedEquipment?.label ?? ''}
                                                    placeholder="Selecione o equipamento do cliente"
                                                    disabled
                                                />
                                                {errors.equipment_id && <div className="text-sm text-red-500">{errors.equipment_id}</div>}
                                            </div>

                                            <div className="grid gap-2 md:col-span-2">
                                                <Label htmlFor="model">Marca e Modelo</Label>
                                                <Input
                                                    type="text"
                                                    id="model"
                                                    value={data.model ?? ''}
                                                    onChange={(e) => setData('model', e.target.value)}
                                                    placeholder="Digite a marca e o modelo"
                                                />
                                                <InputError message={errors.model} />
                                            </div>

                                            <div className="grid gap-2 md:col-span-2">
                                                <Label htmlFor="password">Senha</Label>
                                                <Input
                                                    type="text"
                                                    id="password"
                                                    value={data.password}
                                                    onChange={(e) => setData('password', e.target.value)}
                                                />
                                                {errors.password && <div className="text-sm text-red-500">{errors.password}</div>}
                                            </div>
                                        </div>

                                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                                            <div className="grid gap-2">
                                                <Label htmlFor="defect">Defeito relatado</Label>
                                                <Textarea id="defect" value={data.defect} onChange={(e) => setData('defect', e.target.value)} />
                                                {errors.defect && <div className="text-sm text-red-500">{errors.defect}</div>}
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="state_conservation">Estado de conservação</Label>
                                                <Textarea
                                                    id="state_conservation"
                                                    value={data.state_conservation}
                                                    onChange={(e) => setData('state_conservation', e.target.value)}
                                                />
                                                {errors.state_conservation && <div>{errors.state_conservation}</div>}
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="accessories">Acessórios</Label>
                                                <Textarea
                                                    id="accessories"
                                                    value={data.accessories}
                                                    onChange={(e) => setData('accessories', e.target.value)}
                                                />
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <Card>
                                    <CardTitle className="border-b px-6 pb-4">Orçamento</CardTitle>
                                    <CardContent className="space-y-4 pt-6">
                                        <div className="grid gap-4 md:grid-cols-3">
                                            <OrderPreBudgetFields
                                                data={data}
                                                setData={setData}
                                                errors={errors}
                                                descriptionLabel="Descrição do orcamento"
                                                valueLabel="Valor orçamento"
                                            />
                                        </div>

                                        <div className="mt-4 grid gap-2">
                                            <Label htmlFor="budget_link">Link orçamento de peças</Label>
                                            <div className="flex gap-2">
                                                <Input
                                                    type="text"
                                                    id="budget_link"
                                                    name="budget_link"
                                                    value={data.budget_link}
                                                    onChange={(e) => setData('budget_link', e.target.value)}
                                                    placeholder="Cole aqui o link do orçamento ou produto"
                                                />
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="outline"
                                                    onClick={copyBudgetLink}
                                                    disabled={!String(data.budget_link ?? '').trim()}
                                                    title="Copiar link do orçamento"
                                                    aria-label="Copiar link do orçamento"
                                                >
                                                    <Copy className="h-4 w-4" />
                                                </Button>
                                            </div>
                                            {errors.budget_link && <div className="text-sm text-red-500">{errors.budget_link}</div>}
                                        </div>
                                    </CardContent>
                                </Card>

                                {combinedParts.length > 0 && (
                                    <Card className="mb-4">
                                        <CardContent className="p-0">
                                            <Accordion type="single" collapsible className="w-full">
                                                <AccordionItem value="parts" className="border-b-0">
                                                    <AccordionTrigger className="px-4 py-4 hover:no-underline">
                                                        <div className="flex items-center gap-2">
                                                            <span className="font-semibold">Peças adicionadas</span>
                                                            <Badge variant="outline">{combinedParts.length}</Badge>
                                                        </div>
                                                    </AccordionTrigger>
                                                    <AccordionContent className="px-0 pb-0">
                                                        <div className="divide-y border-t">
                                                            {combinedParts.map((part: any, index: number) => {
                                                                const unitPrice = toMoneyNumber(part.sale_price);
                                                                const total = unitPrice * Number(part.quantity || 0);

                                                                return (
                                                                    <div
                                                                        key={`${part.source}-${part.id}-${index}`}
                                                                        className="hover:bg-muted/50 flex flex-col gap-3 p-3 transition sm:flex-row sm:items-center sm:justify-between"
                                                                    >
                                                                        <div className="flex flex-col">
                                                                            <span className="text-sm font-medium">{part.name}</span>

                                                                            <span className="text-muted-foreground text-xs">
                                                                                {maskMoney(String(unitPrice))} × {part.quantity}
                                                                            </span>
                                                                        </div>

                                                                        <div className="flex flex-wrap items-center gap-2 sm:justify-end">
                                                                            <span className="text-sm font-semibold">{maskMoney(String(total))}</span>

                                                                            <Badge variant={part.source === 'database' ? 'outline' : 'default'}>
                                                                                {part.source === 'database' ? 'Salvo' : 'Novo'}
                                                                            </Badge>

                                                                            <Button
                                                                                type="button"
                                                                                size="icon"
                                                                                variant="ghost"
                                                                                onClick={(e) =>
                                                                                    part.source === 'database'
                                                                                        ? handleRemovePartsOrder(e, part.id)
                                                                                        : handleRemovePart(part.id)
                                                                                }
                                                                            >
                                                                                <X className="h-4 w-4" />
                                                                            </Button>
                                                                        </div>
                                                                    </div>
                                                                );
                                                            })}
                                                        </div>
                                                    </AccordionContent>
                                                </AccordionItem>
                                            </Accordion>
                                        </CardContent>
                                    </Card>
                                )}

                                <Card>
                                    <CardTitle className="border-b px-6 pb-4">Financeiro e execução</CardTitle>
                                    <CardContent className="space-y-4 pt-6">
                                        <div className="grid gap-4 md:grid-cols-3">
                                            <div className="grid gap-2">
                                                <Label htmlFor="parts_value">Valor das peças</Label>
                                                <Input
                                                    type="text"
                                                    id="parts_value"
                                                    name="parts_value"
                                                    value={maskMoney(data.parts_value ?? 0)}
                                                    onChange={(e) => setData('parts_value', maskMoneyDot(e.target.value))}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="service_value">Valor do serviço</Label>
                                                <Input
                                                    type="text"
                                                    id="service_value"
                                                    name="service_value"
                                                    value={maskMoney(data.service_value ?? '0')}
                                                    onChange={(e) => setData('service_value', maskMoneyDot(e.target.value))}
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="service_cost">Valor total</Label>
                                                <Input
                                                    type="text"
                                                    id="service_cost"
                                                    value={maskMoney(data.service_cost)}
                                                    onChange={(e) => setData('service_cost', e.target.value)}
                                                />
                                            </div>
                                        </div>

                                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                                            <div className="grid gap-2">
                                                <Label htmlFor="service_status">Técnico responsável</Label>
                                                <Select
                                                    menuPosition="fixed"
                                                    defaultValue={defaultTechnical}
                                                    options={optionsTechnical}
                                                    onChange={changeResponsibleTechnician}
                                                    placeholder="Selecione o técnico"
                                                    className="min-w-0"
                                                    styles={selectStyles}
                                                />
                                                {errors.user_id && <div className="text-sm text-red-500">{errors.user_id}</div>}
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="service_status">Status da ordem</Label>
                                                <Select
                                                    menuPosition="fixed"
                                                    defaultValue={statusDefault}
                                                    options={statusServico}
                                                    onChange={changeServiceStatus}
                                                    placeholder="Selecione o status"
                                                    className="min-w-0"
                                                    styles={selectStyles}
                                                />
                                                <InputError message={errors.service_status} />
                                            </div>
                                            <div className="grid gap-2">
                                                <FormFieldHelp
                                                    label="Data de entrega"
                                                    content="Preenchida somente ao marcar a OS como entregue ao cliente. Em qualquer outro status, permanece vazia."
                                                />
                                                {isDeliveryStatus ? (
                                                    <DatePicker
                                                        mode="single"
                                                        date={data.delivery_date}
                                                        setDate={(value) => {
                                                            if (!value) {
                                                                setData('delivery_date', '');
                                                                return;
                                                            }

                                                            const d = value as Date;
                                                            const formatted = [
                                                                d.getFullYear(),
                                                                String(d.getMonth() + 1).padStart(2, '0'),
                                                                String(d.getDate()).padStart(2, '0'),
                                                            ].join('-');

                                                            setData('delivery_date', formatted);
                                                        }}
                                                    />
                                                ) : (
                                                    <Input value="Disponível somente para OS entregue" disabled />
                                                )}
                                                {errors.delivery_date && <div className="text-sm text-red-500">{errors.delivery_date}</div>}
                                            </div>
                                            <div className="grid gap-2">
                                                <Label htmlFor="delivery_forecast">Previsão de entrega *</Label>
                                                <DatePicker
                                                    mode="single"
                                                    date={data.delivery_forecast}
                                                    setDate={(value) => {
                                                        if (!value) {
                                                            setData('delivery_forecast', '');
                                                            return;
                                                        }
                                                        const d = value as Date;
                                                        const formatted = [
                                                            d.getFullYear(),
                                                            String(d.getMonth() + 1).padStart(2, '0'),
                                                            String(d.getDate()).padStart(2, '0'),
                                                        ].join('-');

                                                        setData('delivery_forecast', formatted);
                                                    }}
                                                />
                                                <InputError className="mt-2" message={errors.delivery_forecast} />
                                            </div>

                                            <div className="grid gap-2">
                                                <FormFieldHelp
                                                    label="Garantia em dias"
                                                    content="Se houver data de entrega, o sistema calcula automaticamente o vencimento da garantia."
                                                />
                                                <Input
                                                    id="warranty_days"
                                                    type="number"
                                                    min="0"
                                                    value={data.warranty_days}
                                                    onChange={(e) => setData('warranty_days', e.target.value)}
                                                    placeholder="Ex.: 90"
                                                />
                                                {errors.warranty_days && <div className="text-sm text-red-500">{errors.warranty_days}</div>}
                                            </div>
                                            <div className="grid gap-2 md:col-span-2">
                                                {order?.is_warranty_return ? (
                                                    <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-950">
                                                        <p className="font-medium">Esta OS é um retorno em garantia</p>
                                                        <p className="mt-1">
                                                            Vinculada à OS #{equipmentHistory?.warranty_source_order?.order_number ?? 'não informada'}.
                                                            Esse vínculo é permanente para preservar o histórico da garantia.
                                                        </p>
                                                    </div>
                                                ) : (
                                                    <label className="flex items-center gap-2 font-medium">
                                                        <input
                                                            type="checkbox"
                                                            checked={data.is_warranty_return}
                                                            onChange={(event) => {
                                                                setData('is_warranty_return', event.target.checked);
                                                                if (!event.target.checked) setData('warranty_source_order_id', '');
                                                            }}
                                                        />
                                                        Esta OS é um retorno em garantia de outra OS
                                                    </label>
                                                )}
                                                {data.is_warranty_return && !order?.is_warranty_return && (
                                                    <select
                                                        value={data.warranty_source_order_id}
                                                        onChange={(event) => setData('warranty_source_order_id', event.target.value)}
                                                        className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                                    >
                                                        <option value="">Selecione a OS de origem</option>
                                                        {eligibleWarrantyOrders.map((item: any) => (
                                                            <option key={item.id} value={item.id}>
                                                                OS #{item.order_number}{item.model ? ` • ${item.model}` : ''} • garantia até{' '}
                                                                {moment(item.warranty_expires_at).format('DD/MM/YYYY')}
                                                            </option>
                                                        ))}
                                                    </select>
                                                )}
                                                {data.is_warranty_return && !order?.is_warranty_return && eligibleWarrantyOrders.length === 0 && (
                                                    <p className="text-sm text-amber-700">
                                                        Não há outra OS entregue com garantia ativa para este cliente e equipamento.
                                                    </p>
                                                )}
                                                {errors.warranty_source_order_id && (
                                                    <div className="text-sm text-red-500">{errors.warranty_source_order_id}</div>
                                                )}
                                            </div>
                                        </div>

                                        <div className="mt-4 grid gap-4 md:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="services_performed">Serviços executados</Label>
                                                <Textarea
                                                    id="services_performed"
                                                    value={data.services_performed}
                                                    onChange={(e) => setData('services_performed', e.target.value)}
                                                />
                                                {errors.services_performed && <div className="text-sm text-red-500">{errors.services_performed}</div>}
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="observations">Observações</Label>
                                                <Textarea
                                                    id="observations"
                                                    value={data.observations}
                                                    onChange={(e) => setData('observations', e.target.value)}
                                                />
                                                {errors.observations && <div className="text-sm text-red-500">{errors.observations}</div>}
                                            </div>
                                        </div>
                                    </CardContent>
                                </Card>

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={processing}>
                                        <Save />
                                        Salvar
                                    </Button>
                                </div>
                            </TabsContent>

                            <TabsContent value="history">
                                <div className="space-y-4">
                                    <Card>
                                        <CardTitle className="border-b px-4 py-3">Histórico do equipamento</CardTitle>
                                        <CardContent className="space-y-3 pt-4">
                                            <div className="flex flex-wrap gap-2">
                                                <Badge variant={equipmentHistory?.has_recurrence ? 'default' : 'outline'}>
                                                    {equipmentHistory?.has_recurrence ? 'Com reincidência' : 'Sem reincidência'}
                                                </Badge>
                                                {equipmentHistory?.is_warranty_return && equipmentHistory?.warranty_source_order && (
                                                    <Badge className="bg-amber-100 text-amber-800 hover:bg-amber-100">
                                                        Retorno em garantia da OS #{equipmentHistory.warranty_source_order.order_number}
                                                    </Badge>
                                                )}
                                                {equipmentHistory?.active_warranty && (
                                                    <Badge variant="outline">
                                                        Garantia ativa até{' '}
                                                        {moment(equipmentHistory.active_warranty.warranty_expires_at).format('DD/MM/YYYY')}
                                                    </Badge>
                                                )}
                                            </div>

                                            <div className="grid gap-2 text-sm md:grid-cols-3">
                                                <div>
                                                    <span className="text-muted-foreground">Atendimentos anteriores</span>
                                                    <p className="font-medium">{equipmentHistory?.total_previous_orders ?? 0}</p>
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground">Mesmo defeito</span>
                                                    <p className="font-medium">{equipmentHistory?.same_defect_count ?? 0}</p>
                                                </div>
                                                <div>
                                                    <span className="text-muted-foreground">Garantia</span>
                                                    <p className="font-medium">
                                                        {equipmentHistory?.active_warranty ? 'Em vigor' : 'Sem cobertura ativa'}
                                                    </p>
                                                </div>
                                            </div>

                                            {equipmentHistory?.history?.length ? (
                                                <div className="space-y-2">
                                                    {equipmentHistory.history.map((item: any) => (
                                                        <div key={item.id} className="rounded-lg border p-3 text-sm">
                                                            <div className="flex flex-wrap items-center justify-between gap-2">
                                                                <span className="font-medium">OS #{item.order_number}</span>
                                                                <Badge variant="outline">{moment(item.delivery_date).format('DD/MM/YYYY')}</Badge>
                                                            </div>
                                                            <p className="text-muted-foreground mt-2 line-clamp-2">{item.defect}</p>
                                                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                                                <Badge variant="secondary">{maskMoney(String(item.service_cost ?? 0))}</Badge>
                                                                {item.warranty_expires_at && (
                                                                    <Badge variant="outline">
                                                                        Garantia até {moment(item.warranty_expires_at).format('DD/MM/YYYY')}
                                                                    </Badge>
                                                                )}
                                                            </div>
                                                        </div>
                                                    ))}
                                                </div>
                                            ) : (
                                                <p className="text-muted-foreground text-sm">
                                                    Nenhum atendimento anterior encontrado para este equipamento/modelo.
                                                </p>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>
                            </TabsContent>
                        </Tabs>
                    </form>
                </div>
            </div>
            <Dialog open={publicKeyModalOpen} onOpenChange={setPublicKeyModalOpen}>
                <DialogContent className="max-w-md">
                    <DialogTitle>Chave da página pública</DialogTitle>
                    <p className="text-muted-foreground text-sm">Envie esta chave ao cliente junto com o link público da OS.</p>
                    <div className="rounded-lg border bg-slate-50 px-4 py-5 text-center font-mono text-2xl font-semibold tracking-[0.3em] text-slate-900">
                        {publicAccessKey}
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => {
                            void navigator.clipboard?.writeText(String(publicAccessKey));
                            toastSuccess('Chave copiada', 'A chave da página pública foi copiada.');
                        }}
                    >
                        <Copy className="h-4 w-4" />
                        Copiar chave
                    </Button>
                </DialogContent>
            </Dialog>
            <Dialog open={customerUpdateOpen} onOpenChange={setCustomerUpdateOpen}>
                <DialogContent className="max-w-md">
                    <DialogTitle>Enviar atualização ao cliente</DialogTitle>
                    <p className="text-muted-foreground text-sm">
                        Essa mensagem fica visível na página pública de acompanhamento da ordem e, se possível, também é enviada por e-mail ao
                        cliente.
                    </p>
                    <form onSubmit={handleSendCustomerUpdate} className="space-y-3">
                        <Textarea
                            value={customerUpdateForm.data.note}
                            onChange={(e) => customerUpdateForm.setData('note', e.target.value)}
                            placeholder="Ex.: Peça já chegou e o reparo deve ficar pronto até amanhã."
                            rows={4}
                            maxLength={500}
                        />
                        {customerUpdateForm.errors.note && <p className="text-sm text-red-500">{customerUpdateForm.errors.note}</p>}
                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={() => setCustomerUpdateOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={customerUpdateForm.processing || !customerUpdateForm.data.note.trim()}>
                                {customerUpdateForm.processing ? 'Enviando...' : 'Enviar atualização'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
