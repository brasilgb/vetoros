import AlertSuccess from '@/components/app-alert-success';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { BadgePercent, Banknote, CheckCircle2, Clock3 } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Comissão de técnicos', href: '#' },
];

type Commission = {
    id: number;
    base_amount: number;
    commission_percentage: number;
    commission_amount: number;
    created_at: string;
    order?: { id: number; order_number: number; delivery_date?: string | null; customer?: { name: string } | null } | null;
    technician?: { id: number; name: string } | null;
    account_payable?: { id: number; status: 'pending' | 'partial' | 'paid' | 'cancelled'; balance_amount: number } | null;
};

const statusMeta: Record<string, { label: string; className: string }> = {
    pending: { label: 'Pendente', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
    partial: { label: 'Parcial', className: 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300' },
    paid: { label: 'Pago', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' },
    cancelled: { label: 'Cancelado', className: 'bg-muted text-muted-foreground' },
};

function PayCommissionButton({ accountPayableId, balance }: { accountPayableId: number; balance: number }) {
    const form = useForm({
        amount: balance.toFixed(2),
        payment_method: 'Comissão',
    });

    const handlePay = () => {
        form.post(route('app.accounts-payable.register-payment', accountPayableId));
    };

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button size="sm" className="bg-emerald-600 text-white hover:bg-emerald-700">
                    Marcar como pago
                </Button>
            </AlertDialogTrigger>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Confirmar pagamento de comissão?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Isso registra o pagamento de {formatBRL(balance)} para esta comissão em Contas a pagar.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                    <AlertDialogAction onClick={handlePay} className="bg-emerald-600 hover:bg-emerald-700">
                        Confirmar
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}

export default function TechnicianCommissions({ commissions, totals, technicians, filters }: any) {
    const { flash } = usePage().props as any;

    const updateFilters = (patch: Record<string, string | undefined>) => {
        router.get(route('app.technician-commissions.index'), { ...filters, ...patch, page: undefined }, { preserveState: true, replace: true });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            {flash?.success && <AlertSuccess message={flash.success} />}
            <Head title="Comissão de técnicos" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={BadgePercent} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Comissão de técnicos</h2>
                </div>
                <p className="text-muted-foreground text-sm">
                    Calculada sobre o valor do serviço das OS entregues, conforme o percentual configurado no cadastro de cada técnico.
                </p>
            </div>

            <div className="grid gap-3 px-4 sm:grid-cols-3">
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Comissão gerada</p>
                            <p className="text-xl font-semibold">{formatBRL(totals?.commission_amount ?? 0)}</p>
                        </div>
                        <Banknote className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Já paga</p>
                            <p className="text-xl font-semibold">{formatBRL(totals?.paid_amount ?? 0)}</p>
                        </div>
                        <CheckCircle2 className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
                <Card>
                    <CardContent className="flex items-center justify-between py-4">
                        <div>
                            <p className="text-muted-foreground text-sm">Pendente</p>
                            <p className="text-xl font-semibold">{formatBRL(totals?.pending_amount ?? 0)}</p>
                        </div>
                        <Clock3 className="text-muted-foreground h-8 w-8" />
                    </CardContent>
                </Card>
            </div>

            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:flex-wrap sm:items-center">
                <select
                    value={filters?.technician_id ?? ''}
                    onChange={(e) => updateFilters({ technician_id: e.target.value || undefined })}
                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                >
                    <option value="">Todos os técnicos</option>
                    {technicians?.map((technician: { id: number; name: string; commission_percentage?: number | null }) => (
                        <option key={technician.id} value={technician.id}>
                            {technician.name}
                            {technician.commission_percentage ? ` (${technician.commission_percentage}%)` : ' (sem comissão configurada)'}
                        </option>
                    ))}
                </select>

                <select
                    value={filters?.status ?? ''}
                    onChange={(e) => updateFilters({ status: e.target.value || undefined })}
                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                >
                    <option value="">Todos os status</option>
                    <option value="pending">Pendente</option>
                    <option value="partial">Parcial</option>
                    <option value="paid">Pago</option>
                </select>

                <input
                    type="date"
                    value={filters?.start_date ?? ''}
                    onChange={(e) => updateFilters({ start_date: e.target.value || undefined })}
                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                />
                <input
                    type="date"
                    value={filters?.end_date ?? ''}
                    onChange={(e) => updateFilters({ end_date: e.target.value || undefined })}
                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                />
            </div>

            <div className="p-4">
                <PaginationSummary data={commissions} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Técnico</TableHead>
                                <TableHead>OS</TableHead>
                                <TableHead>Base (serviço)</TableHead>
                                <TableHead>%</TableHead>
                                <TableHead>Comissão</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="min-w-[160px] text-right"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {commissions?.data?.length ? (
                                commissions.data.map((commission: Commission) => {
                                    const meta = commission.account_payable ? statusMeta[commission.account_payable.status] : null;

                                    return (
                                        <TableRow key={commission.id}>
                                            <TableCell>{commission.technician?.name ?? '-'}</TableCell>
                                            <TableCell>
                                                {commission.order ? (
                                                    <Link
                                                        href={route('app.orders.show', commission.order.id)}
                                                        className="text-primary hover:underline"
                                                    >
                                                        OS #{commission.order.order_number}
                                                    </Link>
                                                ) : (
                                                    '-'
                                                )}
                                                <div className="text-muted-foreground text-xs">{commission.order?.customer?.name}</div>
                                            </TableCell>
                                            <TableCell>{formatBRL(commission.base_amount)}</TableCell>
                                            <TableCell>{Number(commission.commission_percentage).toFixed(2)}%</TableCell>
                                            <TableCell className="font-medium">{formatBRL(commission.commission_amount)}</TableCell>
                                            <TableCell>
                                                {meta && (
                                                    <Badge className={meta.className} variant="outline">
                                                        {meta.label}
                                                    </Badge>
                                                )}
                                            </TableCell>
                                            <TableCell className="min-w-[160px] text-right">
                                                {commission.account_payable && commission.account_payable.status !== 'paid' && (
                                                    <PayCommissionButton
                                                        accountPayableId={commission.account_payable.id}
                                                        balance={Number(commission.account_payable.balance_amount)}
                                                    />
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={7} className="h-16 text-center">
                                        Nenhuma comissão gerada até o momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={7}>
                                    <AppPagination data={commissions} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
