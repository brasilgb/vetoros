import { ChartLoadState } from '@/components/Charts/chart-load-state';
import { ChartPurchasesTrend } from '@/components/Charts/chart-purchases-trend';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useDashboardResource } from '@/hooks/use-dashboard-resource';
import { parsePurchasesPayload } from '@/lib/dashboard-payload';
import { formatBRL } from '@/lib/money';
import { AlertTriangle, FileChartColumn, HandCoins, PackageSearch, ShoppingBag } from 'lucide-react';
import moment from 'moment';

type DateRangeValue = {
    from?: Date | string;
    to?: Date | string;
};

type PurchasesKpis = {
    total_spent: number;
    received_count: number;
    average_ticket: number;
    pending_count: number;
    pending_value: number;
    low_stock_count: number;
};

type Supplier = { id: number; name: string; total: number; orders_count: number };
type PartRow = { id: number; name: string; quantity: number; total: number };

function formatIsoDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('YYYY-MM-DD');
}

const parsePurchases = (payload: unknown) => parsePurchasesPayload<PurchasesKpis, Supplier, PartRow, { date: string; total: number }>(payload);

export default function PurchasesDashboard({
    timerange,
    dateRange,
    customRange,
}: {
    timerange: number;
    dateRange?: DateRangeValue;
    customRange?: boolean;
}) {
    // Uma chamada alimenta a aba inteira; falha mostra erro com nova tentativa, nunca R$ 0,00 (VETOR-STABILIZE-03).
    const rangeQuery =
        customRange && dateRange?.from && dateRange?.to ? `?from=${formatIsoDate(dateRange.from)}&to=${formatIsoDate(dateRange.to)}` : '';
    const resource = useDashboardResource(`kpisPurchases/${timerange}${rangeQuery}`, parsePurchases);

    if (resource.load.status !== 'ready' || !resource.data) {
        return (
            <div className="min-w-0">
                <ChartLoadState
                    load={resource.load.status === 'ready' ? { status: 'loading' } : resource.load}
                    onRetry={resource.retry}
                    className="h-[320px]"
                    label="dashboard de compras"
                />
            </div>
        );
    }

    const { kpis, topSuppliers, topParts, trend } = resource.data;

    return (
        <div className="min-w-0">
            <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Total comprado no período</CardTitle>
                        <HandCoins className="text-muted-foreground h-4 w-4" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{formatBRL(kpis?.total_spent)}</div>
                        <p className="text-muted-foreground text-xs">{kpis?.received_count ?? 0} ordem(ns) recebida(s)</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Ticket médio por ordem</CardTitle>
                        <ShoppingBag className="text-muted-foreground h-4 w-4" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{formatBRL(kpis?.average_ticket)}</div>
                        <p className="text-muted-foreground text-xs">Média das ordens recebidas no período</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Ordens em aberto</CardTitle>
                        <FileChartColumn className="text-muted-foreground h-4 w-4" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{kpis?.pending_count ?? 0}</div>
                        <p className="text-muted-foreground text-xs">{formatBRL(kpis?.pending_value)} em rascunho/enviadas</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between space-y-0 pb-2">
                        <CardTitle className="text-sm font-medium">Peças com estoque baixo</CardTitle>
                        <AlertTriangle className="text-muted-foreground h-4 w-4" />
                    </CardHeader>
                    <CardContent>
                        <div className="text-2xl font-bold">{kpis?.low_stock_count ?? 0}</div>
                        {(kpis?.low_stock_count ?? 0) > 0 ? (
                            <p className="text-muted-foreground text-xs">
                                <a href={route('app.parts.index', { filter: 'low_stock' })} className="underline">
                                    Ver peças e sugerir compra
                                </a>
                            </p>
                        ) : (
                            <p className="text-muted-foreground text-xs">Nenhuma peça abaixo do mínimo agora</p>
                        )}
                    </CardContent>
                </Card>
            </div>

            <div className="mt-3 grid gap-4 xl:grid-cols-3">
                <Card className="min-w-0 xl:col-span-2">
                    <CardHeader>
                        <CardTitle className="text-base">Compras por mês (últimos 6 meses)</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <ChartPurchasesTrend data={trend} />
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle className="text-base">Top fornecedores</CardTitle>
                    </CardHeader>
                    <CardContent className="p-0">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Fornecedor</TableHead>
                                    <TableHead className="text-right">Total</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {topSuppliers.length ? (
                                    topSuppliers.map((supplier) => (
                                        <TableRow key={supplier.id}>
                                            <TableCell>
                                                <div className="font-medium">{supplier.name}</div>
                                                <div className="text-muted-foreground text-xs">{supplier.orders_count} ordem(ns)</div>
                                            </TableCell>
                                            <TableCell className="text-right">{formatBRL(supplier.total)}</TableCell>
                                        </TableRow>
                                    ))
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={2} className="text-muted-foreground h-16 text-center text-sm">
                                            Nenhuma compra recebida no período.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <Card className="mt-3">
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <PackageSearch className="h-4 w-4" />
                        Peças mais compradas no período
                    </CardTitle>
                </CardHeader>
                <CardContent className="p-0">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Peça</TableHead>
                                <TableHead className="text-right">Quantidade</TableHead>
                                <TableHead className="text-right">Total gasto</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {topParts.length ? (
                                topParts.map((part) => (
                                    <TableRow key={part.id}>
                                        <TableCell className="font-medium">{part.name}</TableCell>
                                        <TableCell className="text-right">
                                            <Badge variant="secondary">{part.quantity}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">{formatBRL(part.total)}</TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={3} className="text-muted-foreground h-16 text-center text-sm">
                                        Nenhuma peça comprada no período.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
        </div>
    );
}
