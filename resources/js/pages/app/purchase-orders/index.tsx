import ActionDelete from '@/components/action-delete';
import AlertSuccess from '@/components/app-alert-success';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Edit, Package, Plus, Send, Truck, X } from 'lucide-react';
import moment from 'moment';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Ordens de compra', href: '#' },
];

type PurchaseOrder = {
    id: number;
    purchase_order_number?: number;
    supplier?: { id: number; name: string } | null;
    status: 'draft' | 'sent' | 'received' | 'cancelled';
    total_amount: number;
    expected_date?: string | null;
    received_at?: string | null;
};

const statusMeta: Record<PurchaseOrder['status'], { label: string; className: string }> = {
    draft: { label: 'Rascunho', className: 'bg-muted text-muted-foreground' },
    sent: { label: 'Enviada', className: 'bg-blue-100 text-blue-800 dark:bg-blue-950/40 dark:text-blue-300' },
    received: { label: 'Recebida', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' },
    cancelled: { label: 'Cancelada', className: 'bg-red-100 text-red-800 dark:bg-red-950/40 dark:text-red-300' },
};

export default function PurchaseOrders({ purchaseOrders, search, status }: any) {
    const { flash, auth } = usePage().props as any;
    const canManage = auth?.role !== 'technician' && auth?.permissions?.includes('purchase_orders');

    const actionForm = useForm({});

    const changeStatusFilter = (value: string) => {
        router.get(
            route('app.purchase-orders.index'),
            { search: search || undefined, status: value || undefined, page: undefined },
            { preserveState: true, replace: true },
        );
    };

    const sendOrder = (id: number) => actionForm.post(route('app.purchase-orders.send', id));
    const receiveOrder = (id: number) => {
        if (confirm('Confirmar o recebimento? O estoque será atualizado e uma conta a pagar será gerada.')) {
            actionForm.post(route('app.purchase-orders.receive', id));
        }
    };
    const cancelOrder = (id: number) => {
        if (confirm('Tem certeza que deseja cancelar esta ordem de compra?')) {
            actionForm.post(route('app.purchase-orders.cancel', id));
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            {flash?.success && <AlertSuccess message={flash.success} />}
            <Head title="Ordens de compra" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Truck} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Ordens de compra</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex w-full flex-col gap-2 sm:flex-row lg:max-w-[560px]">
                    <div className="min-w-0 flex-1">
                        <InputSearch placeholder="Buscar por número ou fornecedor" url="app.purchase-orders.index" />
                    </div>
                    <select
                        value={status || ''}
                        onChange={(e) => changeStatusFilter(e.target.value)}
                        className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                    >
                        <option value="">Todos os status</option>
                        <option value="draft">Rascunho</option>
                        <option value="sent">Enviada</option>
                        <option value="received">Recebida</option>
                        <option value="cancelled">Cancelada</option>
                    </select>
                </div>
                {canManage && (
                    <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                        <Button asChild variant="default" className="w-full whitespace-nowrap sm:w-auto">
                            <Link href={route('app.purchase-orders.create')}>
                                <Plus className="h-4 w-4" />
                                <span>Nova ordem de compra</span>
                            </Link>
                        </Button>
                    </div>
                )}
            </div>

            <div className="p-4">
                <PaginationSummary data={purchaseOrders} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Fornecedor</TableHead>
                                <TableHead>Previsão</TableHead>
                                <TableHead>Total</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead className="min-w-[220px] text-right"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {purchaseOrders?.data?.length ? (
                                purchaseOrders.data.map((po: PurchaseOrder) => (
                                    <TableRow key={po.id}>
                                        <TableCell>{po.purchase_order_number ?? '-'}</TableCell>
                                        <TableCell>{po.supplier?.name ?? 'Sem fornecedor'}</TableCell>
                                        <TableCell>{po.expected_date ? moment(po.expected_date).format('DD/MM/YYYY') : '-'}</TableCell>
                                        <TableCell>{formatBRL(po.total_amount)}</TableCell>
                                        <TableCell>
                                            <Badge className={statusMeta[po.status].className} variant="outline">
                                                {statusMeta[po.status].label}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="min-w-[220px] text-right">
                                            {canManage && (
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {po.status === 'draft' && (
                                                        <>
                                                            <Button
                                                                className="bg-blue-600 text-white hover:bg-blue-700"
                                                                size="sm"
                                                                onClick={() => sendOrder(po.id)}
                                                            >
                                                                <Send className="h-4 w-4" />
                                                                Enviar
                                                            </Button>
                                                            <Button
                                                                className="bg-orange-500 text-white hover:bg-orange-600"
                                                                size="icon"
                                                                title="Editar ordem de compra"
                                                                aria-label={`Editar ordem de compra ${po.purchase_order_number}`}
                                                                asChild
                                                            >
                                                                <Link href={route('app.purchase-orders.edit', po.id)}>
                                                                    <Edit className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                            <ActionDelete
                                                                title={'esta ordem de compra'}
                                                                url={'app.purchase-orders.destroy'}
                                                                param={po.id}
                                                            />
                                                        </>
                                                    )}
                                                    {po.status === 'sent' && (
                                                        <Button
                                                            className="bg-emerald-600 text-white hover:bg-emerald-700"
                                                            size="sm"
                                                            onClick={() => receiveOrder(po.id)}
                                                        >
                                                            <Package className="h-4 w-4" />
                                                            Receber
                                                        </Button>
                                                    )}
                                                    {(po.status === 'draft' || po.status === 'sent') && (
                                                        <Button variant="outline" size="icon" title="Cancelar" onClick={() => cancelOrder(po.id)}>
                                                            <X className="h-4 w-4" />
                                                        </Button>
                                                    )}
                                                </div>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={6} className="h-16 text-center">
                                        Nenhuma ordem de compra cadastrada.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={6}>
                                    <AppPagination data={purchaseOrders} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
