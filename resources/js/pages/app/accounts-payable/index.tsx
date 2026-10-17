import ActionDelete from '@/components/action-delete';
import AlertSuccess from '@/components/app-alert-success';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatBRL, normalizeMoney } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Banknote, Edit, HandCoins, Plus } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Contas a pagar', href: '#' },
];

type Bill = {
    id: number;
    bill_number?: number;
    supplier_name?: string | null;
    description: string;
    category?: string | null;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    due_date?: string | null;
    status: 'pending' | 'partial' | 'paid' | 'cancelled';
    source_type?: string | null;
    notes?: string | null;
    created_by?: { id: number; name: string } | null;
};

type BillForm = {
    supplier_name: string;
    description: string;
    category: string;
    total_amount: string;
    due_date: string;
    notes: string;
};

const statusMeta: Record<Bill['status'], { label: string; className: string }> = {
    pending: { label: 'Pendente', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
    partial: { label: 'Parcial', className: 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300' },
    paid: { label: 'Pago', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' },
    cancelled: { label: 'Cancelado', className: 'bg-muted text-muted-foreground' },
};

export default function AccountsPayable({ bills, search, status, totals }: any) {
    const { flash, auth } = usePage().props as any;
    const canManage = auth?.role !== 'technician' && auth?.permissions?.includes('finance');

    const [openModal, setOpenModal] = useState(false);
    const [editingBill, setEditingBill] = useState<Bill | null>(null);

    const [payingBill, setPayingBill] = useState<Bill | null>(null);

    const form = useForm<BillForm>({
        supplier_name: '',
        description: '',
        category: '',
        total_amount: '',
        due_date: '',
        notes: '',
    });

    const paymentForm = useForm({
        amount: '',
        payment_method: '',
        paid_at: moment().format('YYYY-MM-DD'),
    });

    const resetForm = () => {
        form.reset();
        form.clearErrors();
    };

    const openCreateModal = () => {
        setEditingBill(null);
        resetForm();
        setOpenModal(true);
    };

    const openEditModal = (bill: Bill) => {
        setEditingBill(bill);
        form.clearErrors();
        form.setData({
            supplier_name: bill.supplier_name ?? '',
            description: bill.description ?? '',
            category: bill.category ?? '',
            total_amount: normalizeMoney(bill.total_amount ?? 0),
            due_date: bill.due_date ? moment(bill.due_date).format('YYYY-MM-DD') : '',
            notes: bill.notes ?? '',
        });
        setOpenModal(true);
    };

    const closeModal = () => {
        setOpenModal(false);
        setEditingBill(null);
        resetForm();
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (editingBill) {
            form.put(route('app.accounts-payable.update', editingBill.id), { onSuccess: () => closeModal() });
            return;
        }

        form.post(route('app.accounts-payable.store'), { onSuccess: () => closeModal() });
    };

    const openPaymentModal = (bill: Bill) => {
        setPayingBill(bill);
        paymentForm.reset();
        paymentForm.clearErrors();
        paymentForm.setData('paid_at', moment().format('YYYY-MM-DD'));
        paymentForm.setData('amount', normalizeMoney(bill.balance_amount || 0));
    };

    const closePaymentModal = () => {
        setPayingBill(null);
        paymentForm.reset();
    };

    const handleRegisterPayment = (e: React.FormEvent) => {
        e.preventDefault();
        if (!payingBill) return;

        paymentForm.post(route('app.accounts-payable.register-payment', payingBill.id), {
            onSuccess: () => closePaymentModal(),
        });
    };

    const changeStatusFilter = (value: string) => {
        router.get(
            route('app.accounts-payable.index'),
            { search: search || undefined, status: value || undefined, page: undefined },
            { preserveState: true, replace: true },
        );
    };

    const isOverdue = (bill: Bill) =>
        (bill.status === 'pending' || bill.status === 'partial') && bill.due_date && moment(bill.due_date).isBefore(moment(), 'day');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            {flash?.success && <AlertSuccess message={flash.success} />}
            <Head title="Contas a pagar" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={HandCoins} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Contas a pagar</h2>
                </div>
            </div>

            <div className="grid gap-3 px-4 sm:grid-cols-2">
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Saldo em aberto</p>
                            <p className="text-xl font-semibold">{formatBRL(totals?.open_balance ?? 0)}</p>
                        </div>
                        <Banknote className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Contas vencidas</p>
                            <p className="text-xl font-semibold">{totals?.overdue_count ?? 0}</p>
                        </div>
                        <AlertTriangle className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex w-full flex-col gap-2 sm:flex-row lg:max-w-[560px]">
                    <div className="min-w-0 flex-1">
                        <InputSearch placeholder="Buscar por número, fornecedor ou descrição" url="app.accounts-payable.index" />
                    </div>
                    <select
                        value={status || ''}
                        onChange={(e) => changeStatusFilter(e.target.value)}
                        className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                    >
                        <option value="">Todos os status</option>
                        <option value="pending">Pendente</option>
                        <option value="partial">Parcial</option>
                        <option value="paid">Pago</option>
                        <option value="cancelled">Cancelado</option>
                    </select>
                </div>
                {canManage && (
                    <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                        <Button variant="default" onClick={openCreateModal} className="w-full whitespace-nowrap sm:w-auto">
                            <Plus className="h-4 w-4" />
                            <span>Nova conta</span>
                        </Button>
                    </div>
                )}
            </div>

            <div className="p-4">
                <PaginationSummary data={bills} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Vencimento</TableHead>
                                <TableHead>Conta</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Saldo</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="min-w-[160px] text-right"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {bills?.data?.length ? (
                                bills.data.map((bill: Bill) => (
                                    <TableRow key={bill.id}>
                                        <TableCell>{bill.bill_number ?? '-'}</TableCell>
                                        <TableCell className={isOverdue(bill) ? 'font-medium text-red-600 dark:text-red-400' : undefined}>
                                            {bill.due_date ? moment(bill.due_date).format('DD/MM/YYYY') : '-'}
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="font-medium">{bill.description}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {[bill.supplier_name, bill.category].filter(Boolean).join(' • ') || 'Sem fornecedor/categoria'}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>{formatBRL(bill.total_amount)}</TableCell>
                                        <TableCell>{formatBRL(bill.balance_amount)}</TableCell>
                                        <TableCell>
                                            <Badge className={statusMeta[bill.status].className} variant="outline">
                                                {statusMeta[bill.status].label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="min-w-[160px] text-right">
                                            {canManage && (
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {(bill.status === 'pending' || bill.status === 'partial') && (
                                                        <Button
                                                            size="sm"
                                                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                                                            onClick={() => openPaymentModal(bill)}
                                                        >
                                                            Pagar
                                                        </Button>
                                                    )}
                                                    <Button
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        size="icon"
                                                        onClick={() => openEditModal(bill)}
                                                        title="Editar conta"
                                                        aria-label={`Editar conta ${bill.bill_number ?? bill.description}`}
                                                    >
                                                        <Edit className="h-4 w-4" />
                                                    </Button>
                                                    <ActionDelete title={'esta conta a pagar'} url={'app.accounts-payable.destroy'} param={bill.id} />
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={7} className="h-16 text-center">
                                        Nenhuma conta a pagar cadastrada.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={7}>
                                    <AppPagination data={bills} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>

            <Dialog open={openModal} onOpenChange={(open) => (!open ? closeModal() : setOpenModal(true))}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>{editingBill ? 'Editar conta a pagar' : 'Nova conta a pagar'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} className="space-y-4">
                        <Card>
                            <CardTitle className="border-b px-6 pb-4">Dados da conta</CardTitle>
                            <CardContent className="space-y-4 pt-6">
                                <div className="grid gap-2">
                                    <Label htmlFor="supplier_name">Fornecedor</Label>
                                    <Input
                                        id="supplier_name"
                                        value={form.data.supplier_name}
                                        onChange={(e) => form.setData('supplier_name', e.target.value)}
                                        placeholder="Ex: Distribuidora de peças"
                                    />
                                    <InputError message={form.errors.supplier_name} />
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">Descrição</Label>
                                    <Input
                                        id="description"
                                        value={form.data.description}
                                        onChange={(e) => form.setData('description', e.target.value)}
                                        placeholder="Ex: Compra de peças de reposição"
                                    />
                                    <InputError message={form.errors.description} />
                                </div>

                                <div className="grid grid-cols-2 gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="category">Categoria</Label>
                                        <Input
                                            id="category"
                                            value={form.data.category}
                                            onChange={(e) => form.setData('category', e.target.value)}
                                            placeholder="Digite a categoria"
                                        />
                                        <InputError message={form.errors.category} />
                                    </div>
                                    <div className="grid gap-2">
                                        <Label htmlFor="due_date">Vencimento</Label>
                                        <Input
                                            id="due_date"
                                            type="date"
                                            value={form.data.due_date}
                                            onChange={(e) => form.setData('due_date', e.target.value)}
                                        />
                                        <InputError message={form.errors.due_date} />
                                    </div>
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="total_amount">Valor</Label>
                                    <MoneyInput
                                        id="total_amount"
                                        placeholder="0,00"
                                        value={form.data.total_amount}
                                        onValueChange={(value) => form.setData('total_amount', value)}
                                        error={form.errors.total_amount}
                                    />
                                    <InputError message={form.errors.total_amount} />
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
                                {editingBill ? 'Salvar alterações' : 'Salvar conta'}
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>

            <Dialog open={!!payingBill} onOpenChange={(open) => (!open ? closePaymentModal() : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Registrar pagamento</DialogTitle>
                    </DialogHeader>
                    {payingBill && (
                        <form onSubmit={handleRegisterPayment} className="space-y-4">
                            <p className="text-muted-foreground text-sm">
                                {payingBill.description} — saldo atual: <strong>{formatBRL(payingBill.balance_amount)}</strong>
                            </p>

                            <div className="grid gap-2">
                                <Label htmlFor="amount">Valor pago</Label>
                                <MoneyInput
                                    id="amount"
                                    placeholder="0,00"
                                    value={paymentForm.data.amount}
                                    onValueChange={(value) => paymentForm.setData('amount', value)}
                                    error={paymentForm.errors.amount}
                                />
                                <InputError message={paymentForm.errors.amount} />
                            </div>

                            <div className="grid grid-cols-2 gap-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="payment_method">Forma de pagamento</Label>
                                    <Input
                                        id="payment_method"
                                        value={paymentForm.data.payment_method}
                                        onChange={(e) => paymentForm.setData('payment_method', e.target.value)}
                                        placeholder="Ex: Pix, Boleto"
                                    />
                                    <InputError message={paymentForm.errors.payment_method} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="paid_at">Data do pagamento</Label>
                                    <Input
                                        id="paid_at"
                                        type="date"
                                        value={paymentForm.data.paid_at}
                                        onChange={(e) => paymentForm.setData('paid_at', e.target.value)}
                                    />
                                    <InputError message={paymentForm.errors.paid_at} />
                                </div>
                            </div>

                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={closePaymentModal}>
                                    Cancelar
                                </Button>
                                <Button type="submit" disabled={paymentForm.processing}>
                                    Confirmar pagamento
                                </Button>
                            </DialogFooter>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
