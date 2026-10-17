import { FinancialRevenueChart, FinancialRevenueTrendChart } from '@/components/Charts/chart-financial-revenue';
import { ChartLoadState } from '@/components/Charts/chart-load-state';
import { KpiFinancial } from '@/components/kpi-financial';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useDashboardResource } from '@/hooks/use-dashboard-resource';
import { parseKpisPayload, parseSeriesPayload } from '@/lib/dashboard-payload';
import { DollarSign, Receipt, TrendingUp } from 'lucide-react';
import moment from 'moment';

type DateRangeValue = {
    from?: Date | string;
    to?: Date | string;
};

type FinancialRevenuePoint = {
    date: string;
    total: number;
    services: number;
    parts: number;
};

type RevenueBreakdown = {
    total: number;
    services: number;
    parts: number;
};

type KpiComparison = {
    current: number;
    previous: number;
    change: number;
    percent: number | null;
};

type OrdersKpis = {
    today_revenue: RevenueBreakdown;
    month_projection: RevenueBreakdown;
    month_revenue: RevenueBreakdown;
    range_revenue: RevenueBreakdown;
    daily_average: RevenueBreakdown;
    average_ticket: RevenueBreakdown;
    orders_count: number;
    orders_today_count: number;
    orders_month_count: number;
    comparison?: {
        range_revenue?: KpiComparison;
        daily_average?: KpiComparison;
        average_ticket?: KpiComparison;
    };
};

function formatIsoDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('YYYY-MM-DD');
}

function formatBrDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

export default function FinanceiroOrders({
    timerange,
    dateRange,
    customRange,
}: {
    timerange: number;
    dateRange?: DateRangeValue;
    customRange?: boolean;
}) {
    // Indicadores e gráficos validados (VETOR-STABILIZE-03): falha da API mostra erro, nunca R$ 0,00.
    const rangeQuery =
        customRange && dateRange?.from && dateRange?.to ? `?from=${formatIsoDate(dateRange.from)}&to=${formatIsoDate(dateRange.to)}` : '';
    const monthQuery = `?from=${moment().startOf('month').format('YYYY-MM-DD')}&to=${moment().endOf('month').format('YYYY-MM-DD')}`;
    const kpisResource = useDashboardResource(`kpisFinancialOrder/${timerange}${rangeQuery}`, parseKpisPayload<OrdersKpis>);
    const revenueResource = useDashboardResource(`financialRevenueChart/${timerange}${rangeQuery}`, parseSeriesPayload<FinancialRevenuePoint>);
    const trendResource = useDashboardResource(`financialRevenueChart/30${monthQuery}`, parseSeriesPayload<FinancialRevenuePoint>);
    const kpiOrders = kpisResource.data;

    const rangeLabel =
        customRange && dateRange?.from && dateRange?.to ? `${formatBrDate(dateRange.from)} a ${formatBrDate(dateRange.to)}` : `${timerange} dias`;

    return (
        <div className="min-w-0">
            {kpisResource.load.status !== 'ready' ? (
                <ChartLoadState load={kpisResource.load} onRetry={kpisResource.retry} className="h-[132px]" label="indicadores" />
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                    <KpiFinancial
                        title="Faturamento - Projeção mensal"
                        total={kpiOrders?.month_projection?.total}
                        services={kpiOrders?.month_projection?.services}
                        parts={kpiOrders?.month_projection?.parts}
                        icon={<DollarSign size={18} />}
                        orders={kpiOrders?.orders_month_count}
                        ordersLabel={`${Number(kpiOrders?.orders_month_count || 0)} ${Number(kpiOrders?.orders_month_count || 0) === 1 ? 'ordem do mês' : 'ordens do mês'}`}
                    />

                    <KpiFinancial
                        title={`Faturamento - Período ${rangeLabel}`}
                        total={kpiOrders?.range_revenue?.total}
                        services={kpiOrders?.range_revenue?.services}
                        parts={kpiOrders?.range_revenue?.parts}
                        icon={<DollarSign size={18} />}
                        orders={kpiOrders?.orders_count}
                        comparison={kpiOrders?.comparison?.range_revenue}
                    />

                    <KpiFinancial
                        title={`Média - Período ${rangeLabel}`}
                        total={kpiOrders?.daily_average?.total}
                        services={kpiOrders?.daily_average?.services}
                        parts={kpiOrders?.daily_average?.parts}
                        icon={<TrendingUp size={18} />}
                        orders={kpiOrders?.orders_count}
                        comparison={kpiOrders?.comparison?.daily_average}
                    />

                    <KpiFinancial
                        title={`Ticket Médio - Período ${rangeLabel}`}
                        total={kpiOrders?.average_ticket?.total}
                        services={kpiOrders?.average_ticket?.services}
                        parts={kpiOrders?.average_ticket?.parts}
                        icon={<Receipt size={18} />}
                        orders={kpiOrders?.orders_count}
                        comparison={kpiOrders?.comparison?.average_ticket}
                    />
                </div>
            )}

            <div className="mt-3 grid gap-4 xl:grid-cols-3">
                <Card className="min-w-0 xl:col-span-1">
                    <CardHeader>
                        <CardTitle>Faturamento</CardTitle>
                        <CardDescription>Participação por categoria no período de {rangeLabel}</CardDescription>
                    </CardHeader>

                    <CardContent className="px-4 pb-4 sm:px-6">
                        <div className="mx-auto w-full max-w-full sm:max-w-[340px]">
                            {revenueResource.load.status !== 'ready' ? (
                                <ChartLoadState load={revenueResource.load} onRetry={revenueResource.retry} />
                            ) : (
                                <FinancialRevenueChart data={revenueResource.data ?? []} />
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card className="min-w-0 xl:col-span-2">
                    <CardHeader>
                        <CardTitle>Comparativo de ganhos</CardTitle>
                        <CardDescription>Mês corrente (do dia 01 ao último dia do mês): total de serviços x total de peças</CardDescription>
                    </CardHeader>

                    <CardContent className="px-4 pb-4 sm:px-6">
                        {trendResource.load.status !== 'ready' ? (
                            <ChartLoadState load={trendResource.load} onRetry={trendResource.retry} />
                        ) : (
                            <FinancialRevenueTrendChart data={trendResource.data ?? []} />
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
