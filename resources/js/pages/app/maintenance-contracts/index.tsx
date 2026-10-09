import ActionDelete from '@/components/action-delete';
import AlertSuccess from '@/components/app-alert-success';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import AsyncResourceSelect from '@/components/async-resource-select';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import type { OptionType } from '@/types';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Ban, CalendarClock, Edit, PauseCircle, PlayCircle, Plus, Printer, ReceiptText, RefreshCcw, Wallet } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Contratos de manutenção', href: '#' },
];

type Contract = {
    id: number;
    contract_number?: number | null;
    description: string;
    monthly_amount: number;
    billing_day: number;
    start_date: string;
    duration_months?: number | null;
    end_date?: string | null;
    visit_frequency_days?: number | null;
    next_billing_date?: string | null;
    next_schedule_date?: string | null;
    status: 'active' | 'suspended' | 'cancelled' | 'expired';
    notes?: string | null;
    customer_id?: number;
    customer?: { id: number; name: string } | null;
    preferred_technician_id?: number | null;
    preferred_technician?: { id: number; name: string } | null;
    auto_issue_invoice?: boolean;
    auto_send_invoice?: boolean;
};

type Technician = { id: number; name: string };

type ContractForm = {
    customer_id: string;
    description: string;
    monthly_amount: string;
    billing_day: string;
    start_date: string;
    duration_months: string;
    visit_frequency_days: string;
    preferred_technician_id: string;
    notes: string;
    auto_issue_invoice: boolean;
    auto_send_invoice: boolean;
};

const statusMeta: Record<Contract['status'], { label: string; className: string }> = {
    active: { label: 'Ativo', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' },
    suspended: { label: 'Suspenso', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
    cancelled: { label: 'Cancelado', className: 'bg-muted text-muted-foreground' },
    expired: { label: 'Vencido', className: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300' },
};

function formatCurrency(value: number | string) {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function formatCurrencyMask(value: string) {
    const digits = value.replace(/\D/g, '');
    if (!digits) return '';
    const number = Number(digits) / 100;
    return number.toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

function parseCurrencyMask(value: string) {
    const normalized = value
        .replace(/\./g, '')
        .replace(',', '.')
        .replace(/[^\d.]/g, '');
    const amount = Number(normalized);
    return Number.isFinite(amount) ? amount.toFixed(2) : '';
}

export default function MaintenanceContracts({ contracts, search, status, totals, technicians, invoiceBlocker }: any) {
    const { flash, auth } = usePage().props as any;
    const canManage = auth?.role !== 'technician' && auth?.permissions?.includes('finance');

    const [openModal, setOpenModal] = useState(false);
    const [editingContract, setEditingContract] = useState<Contract | null>(null);
    const [amountDisplay, setAmountDisplay] = useState('');
    const [selectedCustomer, setSelectedCustomer] = useState<OptionType | null>(null);
    const [hasDuration, setHasDuration] = useState(false);
    const [hasAutoVisit, setHasAutoVisit] = useState(false);

    const [renewingContract, setRenewingContract] = useState<Contract | null>(null);

    const form = useForm<ContractForm>({
        customer_id: '',
        description: '',
        monthly_amount: '',
        billing_day: '5',
        start_date: moment().format('YYYY-MM-DD'),
        duration_months: '',
        visit_frequency_days: '',
        preferred_technician_id: '',
        notes: '',
        auto_issue_invoice: false,
        auto_send_invoice: false,
    });

    const renewForm = useForm({ duration_months: '' });

    const resetForm = () => {
        form.reset();
        form.clearErrors();
        form.setData('start_date', moment().format('YYYY-MM-DD'));
        form.setData('billing_day', '5');
        setAmountDisplay('');
        setSelectedCustomer(null);
        setHasDuration(false);
        setHasAutoVisit(false);
    };

    const openCreateModal = () => {
        setEditingContract(null);
        resetForm();
        setOpenModal(true);
    };

    const openEditModal = (contract: Contract) => {
        setEditingContract(contract);
        form.clearErrors();
        form.setData({
            customer_id: String(contract.customer_id ?? contract.customer?.id ?? ''),
            description: contract.description ?? '',
            monthly_amount: Number(contract.monthly_amount ?? 0).toFixed(2),
            billing_day: String(contract.billing_day ?? 5),
            start_date: contract.start_date ? moment(contract.start_date).format('YYYY-MM-DD') : '',
            duration_months: contract.duration_months ? String(contract.duration_months) : '',
            visit_frequency_days: contract.visit_frequency_days ? String(contract.visit_frequency_days) : '',
            preferred_technician_id: contract.preferred_technician_id ? String(contract.preferred_technician_id) : '',
            notes: contract.notes ?? '',
            auto_issue_invoice: !!contract.auto_issue_invoice,
            auto_send_invoice: !!contract.auto_send_invoice,
        });
        setAmountDisplay(Number(contract.monthly_amount || 0).toLocaleString('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }));
        setSelectedCustomer(contract.customer ? { value: contract.customer.id, label: contract.customer.name } : null);
        setHasDuration(!!contract.duration_months);
        setHasAutoVisit(!!contract.visit_frequency_days);
        setOpenModal(true);
    };

    const closeModal = () => {
        setOpenModal(false);
        setEditingContract(null);
        resetForm();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (editingContract) {
            form.put(route('app.maintenance-contracts.update', editingContract.id), { onSuccess: () => closeModal() });
            return;
        }

        form.post(route('app.maintenance-contracts.store'), { onSuccess: () => closeModal() });
    };

    const onAmountChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const masked = formatCurrencyMask(event.target.value);
        setAmountDisplay(masked);
        form.setData('monthly_amount', parseCurrencyMask(masked));
    };

    const changeCustomer = (option: OptionType | null) => {
        setSelectedCustomer(option);
        form.setData('customer_id', option ? String(option.value) : '');
    };

    const toggleDuration = (checked: boolean) => {
        setHasDuration(checked);
        if (!checked) form.setData('duration_months', '');
    };

    const toggleAutoVisit = (checked: boolean) => {
        setHasAutoVisit(checked);
        if (!checked) form.setData('visit_frequency_days', '');
    };

    const changeStatusFilter = (value: string) => {
        router.get(
            route('app.maintenance-contracts.index'),
            { search: search || undefined, status: value || undefined, page: undefined },
            { preserveState: true, replace: true },
        );
    };

    const openRenewModal = (contract: Contract) => {
        setRenewingContract(contract);
        renewForm.reset();
        renewForm.clearErrors();
        renewForm.setData('duration_months', contract.duration_months ? String(contract.duration_months) : '');
    };

    const closeRenewModal = () => {
        setRenewingContract(null);
        renewForm.reset();
    };

    const handleRenew = (e: React.FormEvent) => {
        e.preventDefault();
        if (!renewingContract) return;

        renewForm.post(route('app.maintenance-contracts.renew', renewingContract.id), {
            onSuccess: () => closeRenewModal(),
        });
    };

    const suspend = (contract: Contract) => {
        router.post(route('app.maintenance-contracts.suspend', contract.id));
    };

    const reactivate = (contract: Contract) => {
        router.post(route('app.maintenance-contracts.reactivate', contract.id));
    };

    const cancelContract = (contract: Contract) => {
        if (!confirm('Cancelar este contrato de manutenção? Nenhuma nova cobrança ou visita será gerada.')) return;
        router.post(route('app.maintenance-contracts.cancel', contract.id));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            {flash?.success && <AlertSuccess message={flash.success} />}
            <Head title="Contratos de manutenção" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={CalendarClock} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Contratos de manutenção</h2>
                </div>
            </div>

            {flash?.contract_print && (
                <div className="mx-4 mb-4 rounded-lg border border-emerald-200 bg-emerald-50 p-4 dark:border-emerald-900/70 dark:bg-emerald-950/30">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="font-medium text-emerald-900 dark:text-emerald-100">
                                Contrato #{flash.contract_print.contract_number} cadastrado
                            </p>
                            <p className="text-sm text-emerald-700 dark:text-emerald-300">Contrato pronto para impressão.</p>
                        </div>
                        <Button type="button" asChild className="bg-emerald-600 text-white hover:bg-emerald-700">
                            <a href={flash.contract_print.print_url} target="_blank" rel="noopener noreferrer">
                                <Printer className="h-4 w-4" />
                                Imprimir contrato
                            </a>
                        </Button>
                    </div>
                </div>
            )}

            <div className="grid gap-3 px-4 sm:grid-cols-2">
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Contratos ativos</p>
                            <p className="text-xl font-semibold">{totals?.active_count ?? 0}</p>
                        </div>
                        <CalendarClock className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Receita mensal recorrente</p>
                            <p className="text-xl font-semibold">{formatCurrency(totals?.monthly_revenue ?? 0)}</p>
                        </div>
                        <Wallet className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex w-full flex-col gap-2 sm:flex-row lg:max-w-[560px]">
                    <div className="min-w-0 flex-1">
                        <InputSearch placeholder="Buscar por número, cliente ou descrição" url="app.maintenance-contracts.index" />
                    </div>
                    <select
                        value={status || ''}
                        onChange={(e) => changeStatusFilter(e.target.value)}
                        className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                    >
                        <option value="">Todos os status</option>
                        <option value="active">Ativo</option>
                        <option value="suspended">Suspenso</option>
                        <option value="expired">Vencido</option>
                        <option value="cancelled">Cancelado</option>
                    </select>
                </div>
                {canManage && (
                    <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                        <Button variant="default" onClick={openCreateModal} className="w-full whitespace-nowrap sm:w-auto">
                            <Plus className="h-4 w-4" />
                            <span>Novo contrato</span>
                        </Button>
                    </div>
                )}
            </div>

            <div className="p-4">
                <PaginationSummary data={contracts} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Cliente / descrição</TableHead>
                                <TableHead>Mensalidade</TableHead>
                                <TableHead>Próx. cobrança</TableHead>
                                <TableHead>Próx. visita</TableHead>
                                <TableHead>Vigência</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="min-w-[260px] text-right"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {contracts?.data?.length ? (
                                contracts.data.map((contract: Contract) => (
                                    <TableRow key={contract.id}>
                                        <TableCell>{contract.contract_number ?? '-'}</TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="font-medium">{contract.customer?.name ?? 'Cliente removido'}</div>
                                                <div className="text-muted-foreground text-xs">{contract.description}</div>
                                            </div>
                                        </TableCell>
                                        <TableCell>{formatCurrency(contract.monthly_amount)}</TableCell>
                                        <TableCell>
                                            {contract.status === 'active' && contract.next_billing_date
                                                ? moment(contract.next_billing_date).format('DD/MM/YYYY')
                                                : '-'}
                                        </TableCell>
                                        <TableCell>
                                            {contract.status === 'active' && contract.next_schedule_date
                                                ? moment(contract.next_schedule_date).format('DD/MM/YYYY')
                                                : '-'}
                                        </TableCell>
                                        <TableCell>{contract.end_date ? moment(contract.end_date).format('DD/MM/YYYY') : 'Indeterminada'}</TableCell>
                                        <TableCell>
                                            <Badge className={statusMeta[contract.status].className} variant="outline">
                                                {statusMeta[contract.status].label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="min-w-[260px] text-right">
                                            {canManage && (
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {contract.end_date && (contract.status === 'active' || contract.status === 'expired') && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => openRenewModal(contract)}
                                                            title="Renovar contrato"
                                                        >
                                                            <RefreshCcw className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    {contract.status === 'active' && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => suspend(contract)}
                                                            title="Suspender contrato"
                                                        >
                                                            <PauseCircle className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    {contract.status === 'suspended' && (
                                                        <Button
                                                            size="sm"
                                                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                                                            onClick={() => reactivate(contract)}
                                                            title="Reativar contrato"
                                                        >
                                                            <PlayCircle className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    {(contract.status === 'active' || contract.status === 'suspended') && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            className="text-red-600 hover:text-red-700"
                                                            onClick={() => cancelContract(contract)}
                                                            title="Cancelar contrato"
                                                        >
                                                            <Ban className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                    <Button size="sm" variant="outline" asChild title="Cobranças, recebimentos e notas fiscais">
                                                        <Link href={route('app.maintenance-contracts.charges', contract.id)}>
                                                            <ReceiptText className="h-4 w-4" />
                                                            Cobranças
                                                        </Link>
                                                    </Button>
                                                    <Button size="icon" variant="outline" asChild title="Imprimir contrato">
                                                        <a
                                                            href={route('app.maintenance-contracts.printing', contract.id)}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label={`Imprimir contrato ${contract.contract_number ?? contract.description}`}
                                                        >
                                                            <Printer className="h-4 w-4" />
                                                        </a>
                                                    </Button>
                                                    <Button
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        size="icon"
                                                        onClick={() => openEditModal(contract)}
                                                        title="Editar contrato"
                                                        aria-label={`Editar contrato ${contract.contract_number ?? contract.description}`}
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                    <ActionDelete
                                                        title={'este contrato de manutenção'}
                                                        url={'app.maintenance-contracts.destroy'}
                                                        param={contract.id}
                                                    />
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={8} className="h-16 text-center">
                                        Nenhum contrato de manutenção cadastrado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={8}>
                                    <AppPagination data={contracts} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>

            <Dialog open={openModal} onOpenChange={(open) => (!open ? closeModal() : setOpenModal(true))}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>{editingContract ? 'Editar contrato de manutenção' : 'Novo contrato de manutenção'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <Card>
                            <CardTitle className="border-b px-6 pb-4">Dados do contrato</CardTitle>
                            <CardContent className="space-y-4 pt-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="customer_id">Cliente</Label>
                                    <AsyncResourceSelect
                                        inputId="customer_id"
                                        searchUrl={route('app.customers.search')}
                                        value={selectedCustomer}
                                        onChange={changeCustomer}
                                        placeholder="Digite o nome do cliente..."
                                    />
                                    <InputError message={form.errors.customer_id} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">Descrição</Label>
                                    <Input
                                        id="description"
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder="Ex: Manutenção preventiva mensal - Ar-condicionado"
                                    />
                                    <InputError message={form.errors.description} />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="monthly_amount">Valor da mensalidade</Label>
                                        <Input
                                            id="monthly_amount"
                                            type="text"
                                            inputMode="decimal"
                                            placeholder="0,00"
                                            value={amountDisplay}
                                            onChange={onAmountChange}
                                        />
                                        <InputError message={form.errors.monthly_amount} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="billing_day">Dia da cobrança</Label>
                                        <Input
                                            id="billing_day"
                                            type="number"
                                            min={1}
                                            max={28}
                                            value={form.data.billing_day}
                                            onChange={(e) => form.setData('billing_day', e.target.value)}
                                        />
                                        <InputError message={form.errors.billing_day} />
                                    </div>
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="start_date">Início do contrato</Label>
                                        <Input
                                            id="start_date"
                                            type="date"
                                            value={form.data.start_date}
                                            onChange={(e) => form.setData('start_date', e.target.value)}
                                        />
                                        <InputError message={form.errors.start_date} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="preferred_technician_id">Técnico responsável</Label>
                                        <select
                                            id="preferred_technician_id"
                                            value={form.data.preferred_technician_id}
                                            onChange={(e) => form.setData('preferred_technician_id', e.target.value)}
                                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                        >
                                            <option value="">A definir</option>
                                            {technicians?.map((technician: Technician) => (
                                                <option key={technician.id} value={technician.id}>
                                                    {technician.name}
                                                </option>
                                            ))}
                                        </select>
                                        <InputError message={form.errors.preferred_technician_id} />
                                    </div>
                                </div>

                                <div className="flex items-center gap-2">
                                    <Checkbox id="has_duration" checked={hasDuration} onCheckedChange={(v) => toggleDuration(!!v)} />
                                    <Label htmlFor="has_duration" className="cursor-pointer font-normal">
                                        Contrato com prazo determinado (sem renovação automática)
                                    </Label>
                                </div>
                                {hasDuration && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="duration_months">Duração (meses)</Label>
                                        <Input
                                            id="duration_months"
                                            type="number"
                                            min={1}
                                            max={120}
                                            value={form.data.duration_months}
                                            onChange={(e) => form.setData('duration_months', e.target.value)}
                                        />
                                        <InputError message={form.errors.duration_months} />
                                    </div>
                                )}

                                <div className="flex items-center gap-2">
                                    <Checkbox id="has_auto_visit" checked={hasAutoVisit} onCheckedChange={(v) => toggleAutoVisit(!!v)} />
                                    <Label htmlFor="has_auto_visit" className="cursor-pointer font-normal">
                                        Gerar ordem de serviço e agendamento de visita automaticamente
                                    </Label>
                                </div>
                                {hasAutoVisit && (
                                    <div className="grid gap-2">
                                        <Label htmlFor="visit_frequency_days">Intervalo entre visitas (dias)</Label>
                                        <Input
                                            id="visit_frequency_days"
                                            type="number"
                                            min={1}
                                            max={365}
                                            value={form.data.visit_frequency_days}
                                            onChange={(e) => form.setData('visit_frequency_days', e.target.value)}
                                        />
                                        <InputError message={form.errors.visit_frequency_days} />
                                        <p className="text-muted-foreground text-xs">
                                            O sistema gera automaticamente a ordem de serviço e o agendamento com 1 dia de antecedência da visita.
                                        </p>
                                    </div>
                                )}
                            </CardContent>
                        </Card>

                        <Card>
                            <CardTitle className="border-b px-6 pb-4">Nota fiscal de serviço</CardTitle>
                            <CardContent className="space-y-3 pt-6">
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="auto_issue_invoice"
                                        checked={form.data.auto_issue_invoice}
                                        disabled={!!invoiceBlocker && !form.data.auto_issue_invoice}
                                        onCheckedChange={(checked) =>
                                            form.setData((current) => ({
                                                ...current,
                                                auto_issue_invoice: checked === true,
                                                auto_send_invoice: checked === true ? current.auto_send_invoice : false,
                                            }))
                                        }
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="auto_issue_invoice">Emitir nota fiscal automaticamente após recebimento</Label>
                                        <p className="text-muted-foreground text-xs">
                                            A NFS-e é emitida quando a cobrança do contrato fica integralmente quitada. O vencimento sozinho não emite
                                            nota.
                                        </p>
                                        {invoiceBlocker && (
                                            <p className="text-xs text-amber-700 dark:text-amber-400">Indisponível: {invoiceBlocker}</p>
                                        )}
                                        <InputError message={form.errors.auto_issue_invoice} />
                                    </div>
                                </div>
                                <div className="flex items-start gap-2">
                                    <Checkbox
                                        id="auto_send_invoice"
                                        checked={form.data.auto_send_invoice}
                                        disabled={!form.data.auto_issue_invoice}
                                        onCheckedChange={(checked) => form.setData('auto_send_invoice', checked === true)}
                                    />
                                    <div className="grid gap-1">
                                        <Label htmlFor="auto_send_invoice">Enviar nota fiscal automaticamente ao cliente</Label>
                                        <p className="text-muted-foreground text-xs">
                                            Depois da autorização, o PDF e o XML vão para o e-mail do cliente pelo SMTP da empresa.
                                        </p>
                                    </div>
                                </div>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardTitle className="border-b px-6 pb-4">Observações</CardTitle>
                            <CardContent className="pt-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="notes">Observações</Label>
                                    <Textarea id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={3} />
                                    <InputError message={form.errors.notes} />
                                </div>
                            </CardContent>
                        </Card>

                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={closeModal}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={form.processing}>
                                {editingContract ? 'Salvar alterações' : 'Salvar contrato'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!renewingContract} onOpenChange={(open) => (!open ? closeRenewModal() : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Renovar contrato</DialogTitle>
                    </DialogHeader>
                    {renewingContract && (
                        <form onSubmit={handleRenew} className="space-y-4">
                            <p className="text-muted-foreground text-sm">
                                {renewingContract.customer?.name} — {renewingContract.description}
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="renew_duration_months">Nova duração (meses)</Label>
                                <Input
                                    id="renew_duration_months"
                                    type="number"
                                    min={1}
                                    max={120}
                                    placeholder="Deixe em branco para vigência indeterminada"
                                    value={renewForm.data.duration_months}
                                    onChange={(e) => renewForm.setData('duration_months', e.target.value)}
                                />
                                <InputError message={renewForm.errors.duration_months} />
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={closeRenewModal}>
                                    Cancelar
                                </Button>
                                <Button type="submit" disabled={renewForm.processing}>
                                    Confirmar renovação
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
