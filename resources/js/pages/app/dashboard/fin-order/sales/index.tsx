import { ChartLoadState } from '@/components/Charts/chart-load-state';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartConfig, ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { useDashboardResource } from '@/hooks/use-dashboard-resource';
import { parseKpisPayload, parseSeriesPayload } from '@/lib/dashboard-payload';
import { formatBRL } from '@/lib/money';
import { BadgeDollarSign, PackageSearch, ShoppingCart, TrendingUp, WalletCards, XCircle } from 'lucide-react';
import moment from 'moment';
import type { ReactNode } from 'react';
import { useMemo } from 'react';
import { CartesianGrid, Cell, ComposedChart, Line, Pie, PieChart, XAxis, YAxis } from 'recharts';

function formatIsoDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('YYYY-MM-DD');
}

function formatBrDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

type SalesRevenuePoint = {
    date: string;
    total: number;
    paid: number;
    pending: number;
    partial: number;
    expenses: number;
    profit: number;
};

type KpiComparison = {
    current: number;
    previous: number;
    change: number;
    percent: number | null;
};

type DateRangeValue = {
    from?: Date | string;
    to?: Date | string;
};

type SalesKpis = {
    today_revenue: number;
    today_expenses: number;
    today_profit: number;
    month_projection_revenue: number;
    month_revenue: number;
    range_revenue: number;
    range_expenses: number;
    range_profit: number;
    daily_average: number;
    daily_expense_average: number;
    daily_profit_average: number;
    average_ticket: number;
    sales_count: number;
    sales_today_count: number;
    sales_month_count: number;
    pending_sales_amount?: number;
    cancelled_sales_amount?: number;
    cancelled_sales_count?: number;
    top_products?: {
        name: string;
        quantity: number;
        total: number;
    }[];
    comparison?: {
        range_revenue?: KpiComparison;
        range_profit?: KpiComparison;
        range_expenses?: KpiComparison;
        daily_profit_average?: KpiComparison;
        average_ticket?: KpiComparison;
    };
    payment_methods?: {
        pix?: number;
        cartao?: number;
        dinheiro?: number;
        transferencia?: number;
        boleto?: number;
        outros?: number;
    };
};

const chartConfig = {
    total: {
        label: 'Vendas',
        color: 'var(--chart-1)',
    },
    expenses: {
        label: 'Despesas',
        color: 'var(--chart-2)',
    },
} satisfies ChartConfig;

const paymentChartConfig = {
    pix: { label: 'Pix', color: 'var(--chart-1)' },
    cartao: { label: 'Cartão', color: 'var(--chart-2)' },
    dinheiro: { label: 'Dinheiro', color: 'var(--chart-3)' },
    transferencia: { label: 'Transferência', color: 'var(--chart-4)' },
    boleto: { label: 'Boleto', color: 'var(--chart-5)' },
    outros: { label: 'Outros', color: 'var(--muted-foreground)' },
} satisfies ChartConfig;

function SalesRevenueChart({ data }: { data: SalesRevenuePoint[] }) {
    const chartData = useMemo(
        () =>
            data.map((item) => ({
                ...item,
                dateLabel: moment(item.date).format('DD/MM'),
            })),
        [data],
    );

    return (
        <ChartContainer config={chartConfig} className="h-[240px] w-full sm:h-[280px] lg:h-[300px]">
            <ComposedChart data={chartData} margin={{ top: 8, right: 6, left: 0, bottom: 0 }}>
                <CartesianGrid vertical={false} />
                <XAxis dataKey="dateLabel" tickLine={false} axisLine={false} minTickGap={24} />
                <YAxis tickLine={false} axisLine={false} width={56} tickFormatter={(value) => formatBRL(value, { wholeReais: true })} />
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            labelFormatter={(_, payload) => (payload?.[0]?.payload?.date ? moment(payload[0].payload.date).format('DD/MM/YYYY') : '')}
                            formatter={(value, name) => {
                                const label = name === 'total' ? 'Vendas' : 'Despesas';
                                return (
                                    <div className="flex w-full items-center justify-between gap-3">
                                        <span>{label}</span>
                                        <span className="font-mono font-medium tabular-nums">{formatBRL(Number(value || 0))}</span>
                                    </div>
                                );
                            }}
                        />
                    }
                />
                <Line type="monotone" dataKey="total" name="total" stroke="var(--color-total)" strokeWidth={2} dot={{ r: 2 }} activeDot={{ r: 4 }} />
                <Line
                    type="monotone"
                    dataKey="expenses"
                    name="expenses"
                    stroke="var(--color-expenses)"
                    strokeWidth={2}
                    dot={{ r: 2 }}
                    activeDot={{ r: 4 }}
                />
                <ChartLegend content={<ChartLegendContent nameKey="name" className="justify-start gap-3 pt-4 sm:justify-center" />} />
            </ComposedChart>
        </ChartContainer>
    );
}

function SalesPaymentMethodsPie({
    paymentMethods,
}: {
    paymentMethods?: {
        pix?: number;
        cartao?: number;
        dinheiro?: number;
        transferencia?: number;
        boleto?: number;
        outros?: number;
    };
}) {
    const pieData = useMemo(
        () =>
            [
                { key: 'pix', label: 'Pix', value: Number(paymentMethods?.pix || 0), fill: 'var(--color-pix)' },
                { key: 'cartao', label: 'Cartão', value: Number(paymentMethods?.cartao || 0), fill: 'var(--color-cartao)' },
                { key: 'dinheiro', label: 'Dinheiro', value: Number(paymentMethods?.dinheiro || 0), fill: 'var(--color-dinheiro)' },
                {
                    key: 'transferencia',
                    label: 'Transferência',
                    value: Number(paymentMethods?.transferencia || 0),
                    fill: 'var(--color-transferencia)',
                },
                { key: 'boleto', label: 'Boleto', value: Number(paymentMethods?.boleto || 0), fill: 'var(--color-boleto)' },
                { key: 'outros', label: 'Outros', value: Number(paymentMethods?.outros || 0), fill: 'var(--color-outros)' },
            ].filter((item) => item.value > 0),
        [paymentMethods],
    );

    if (pieData.length === 0) {
        return (
            <div className="flex h-[260px] w-full flex-col items-center justify-center rounded-md border border-dashed px-4 text-center sm:h-[300px]">
                <p className="text-sm font-medium">Sem vendas concluídas no período</p>
                <p className="text-muted-foreground mt-1 text-xs">Altere o período ou registre uma venda concluída para gerar a distribuição.</p>
            </div>
        );
    }

    return (
        <ChartContainer config={paymentChartConfig} className="h-[260px] w-full sm:h-[300px]">
            <PieChart>
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            hideLabel
                            formatter={(value, name) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                    <span>{String(name)}</span>
                                    <span className="font-mono font-medium tabular-nums">{formatBRL(Number(value || 0))}</span>
                                </div>
                            )}
                        />
                    }
                />
                <Pie data={pieData} dataKey="value" nameKey="label" innerRadius={42} outerRadius={78} strokeWidth={2}>
                    {pieData.map((entry) => (
                        <Cell key={entry.key} fill={entry.fill} />
                    ))}
                </Pie>
                <ChartLegend
                    align="left"
                    verticalAlign="bottom"
                    layout="horizontal"
                    content={<ChartLegendContent nameKey="key" className="flex flex-wrap justify-start gap-3 pt-4 sm:justify-center" />}
                />
            </PieChart>
        </ChartContainer>
    );
}

function KpiCard({
    title,
    value,
    description,
    count,
    countLabel,
    comparison,
    icon,
}: {
    title: string;
    value?: number;
    description: string;
    count?: number;
    countLabel?: string;
    comparison?: KpiComparison;
    icon: ReactNode;
}) {
    const formatPercent = (value?: number | null) =>
        value === null || value === undefined ? 'Sem base anterior' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;
    const comparisonClass = Number(comparison?.change || 0) >= 0 ? 'text-emerald-600' : 'text-rose-600';

    return (
        <Card className="h-full min-w-0">
            <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                <CardDescription>{title}</CardDescription>
                <div className="text-muted-foreground shrink-0">{icon}</div>
            </CardHeader>

            <CardContent className="space-y-3">
                <CardTitle className="text-xl leading-tight font-bold break-words tabular-nums sm:text-2xl 2xl:text-3xl">
                    {formatBRL(value)}
                </CardTitle>
                {comparison && (
                    <div className={`text-xs font-medium ${comparisonClass}`}>{formatPercent(comparison.percent)} vs período anterior</div>
                )}
                <div className="text-muted-foreground text-xs">{description}</div>
                <div className="text-muted-foreground border-t pt-2 text-xs">
                    {countLabel ?? `${Number(count || 0)} ${Number(count || 0) === 1 ? 'venda analisada' : 'vendas analisadas'}`}
                </div>
            </CardContent>
        </Card>
    );
}

function InsightCard({
    title,
    value,
    description,
    icon,
    tone = 'default',
}: {
    title: string;
    value: string;
    description: string;
    icon: ReactNode;
    tone?: 'default' | 'positive' | 'warning';
}) {
    const toneClass = {
        default: 'text-muted-foreground',
        positive: 'text-emerald-600',
        warning: 'text-amber-600',
    }[tone];

    return (
        <Card className="min-w-0">
            <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                <CardDescription>{title}</CardDescription>
                <div className={`shrink-0 ${toneClass}`}>{icon}</div>
            </CardHeader>
            <CardContent className="space-y-2">
                <CardTitle className="text-xl leading-tight font-bold break-words tabular-nums sm:text-2xl">{value}</CardTitle>
                <p className="text-muted-foreground text-xs">{description}</p>
            </CardContent>
        </Card>
    );
}

function TopProductsCard({ products }: { products?: SalesKpis['top_products'] }) {
    const items = products ?? [];

    return (
        <Card className="min-w-0">
            <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                <div>
                    <CardTitle className="text-base">Mais vendidos</CardTitle>
                    <CardDescription>Produtos e peças com maior receita no período</CardDescription>
                </div>
                <PackageSearch className="text-muted-foreground h-5 w-5 shrink-0" />
            </CardHeader>
            <CardContent>
                {items.length === 0 ? (
                    <p className="text-muted-foreground text-sm">Sem itens vendidos no período.</p>
                ) : (
                    <div className="space-y-3">
                        {items.map((item, index) => (
                            <div
                                key={`${item.name}-${index}`}
                                className="flex items-center justify-between gap-3 border-b pb-2 last:border-b-0 last:pb-0"
                            >
                                <div className="min-w-0">
                                    <p className="truncate text-sm font-medium">{item.name}</p>
                                    <p className="text-muted-foreground text-xs">{Number(item.quantity || 0)} un.</p>
                                </div>
                                <span className="shrink-0 text-sm font-medium tabular-nums">{formatBRL(item.total)}</span>
                            </div>
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function FinanceiroSales({
    timerange,
    dateRange,
    customRange,
}: {
    timerange: number;
    dateRange?: DateRangeValue;
    customRange?: boolean;
}) {
    // Indicadores e gráfico validados (VETOR-STABILIZE-03): falha da API mostra erro, nunca R$ 0,00.
    const rangeQuery =
        customRange && dateRange?.from && dateRange?.to ? `?from=${formatIsoDate(dateRange.from)}&to=${formatIsoDate(dateRange.to)}` : '';
    const monthQuery = `?from=${moment().startOf('month').format('YYYY-MM-DD')}&to=${moment().endOf('month').format('YYYY-MM-DD')}`;
    const kpisResource = useDashboardResource(`kpisFinancialSales/${timerange}${rangeQuery}`, parseKpisPayload<SalesKpis>);
    const chartResource = useDashboardResource(`financialSalesRevenueChart/30${monthQuery}`, parseSeriesPayload<SalesRevenuePoint>);
    const kpisSales = kpisResource.data;
    const kpisReady = kpisResource.load.status === 'ready';
    const kpisFallback = (className: string) =>
        kpisResource.load.status !== 'ready' && (
            <ChartLoadState load={kpisResource.load} onRetry={kpisResource.retry} className={className} label="indicadores" />
        );

    const rangeLabel =
        customRange && dateRange?.from && dateRange?.to ? `${formatBrDate(dateRange.from)} a ${formatBrDate(dateRange.to)}` : `${timerange} dias`;

    return (
        <div className="min-w-0">
            {!kpisReady ? (
                kpisFallback('h-[132px]')
            ) : (
                <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-5">
                    <KpiCard
                        title="Vendas - Projeção mensal"
                        value={kpisSales?.month_projection_revenue}
                        description="Projeção do faturamento de vendas concluídas no mês atual"
                        count={kpisSales?.sales_month_count}
                        countLabel={`${Number(kpisSales?.sales_month_count || 0)} ${Number(kpisSales?.sales_month_count || 0) === 1 ? 'venda do mês' : 'vendas do mês'}`}
                        icon={<BadgeDollarSign size={18} />}
                    />

                    <KpiCard
                        title={`Lucro Líquido - Período ${rangeLabel}`}
                        value={kpisSales?.range_profit}
                        description="Receita de vendas menos despesas lançadas"
                        count={kpisSales?.sales_count}
                        comparison={kpisSales?.comparison?.range_profit}
                        icon={<WalletCards size={18} />}
                    />

                    <KpiCard
                        title={`Despesas - Período ${rangeLabel}`}
                        value={kpisSales?.range_expenses}
                        description="Somatório de saídas de caixa e despesas lançadas"
                        count={kpisSales?.sales_count}
                        comparison={kpisSales?.comparison?.range_expenses}
                        icon={<ShoppingCart size={18} />}
                    />

                    <KpiCard
                        title={`Média de Lucro - Período ${rangeLabel}`}
                        value={kpisSales?.daily_profit_average}
                        description="Média diária do lucro líquido no período"
                        count={kpisSales?.sales_count}
                        comparison={kpisSales?.comparison?.daily_profit_average}
                        icon={<TrendingUp size={18} />}
                    />

                    <KpiCard
                        title={`Ticket Médio - Período ${rangeLabel}`}
                        value={kpisSales?.average_ticket}
                        description="Média por venda concluída no período"
                        count={kpisSales?.sales_count}
                        comparison={kpisSales?.comparison?.average_ticket}
                        icon={<BadgeDollarSign size={18} />}
                    />
                </div>
            )}

            <div className="mt-3 grid gap-4 xl:grid-cols-12">
                <Card className="min-w-0 xl:col-span-4">
                    <CardHeader>
                        <CardTitle>Meios de pagamento</CardTitle>
                        <CardDescription>Distribuição das vendas por forma de pagamento no período selecionado</CardDescription>
                    </CardHeader>
                    <CardContent className="px-4 pb-4 sm:px-6">
                        {kpisReady ? <SalesPaymentMethodsPie paymentMethods={kpisSales?.payment_methods} /> : kpisFallback('h-[200px]')}
                    </CardContent>
                </Card>

                <Card className="min-w-0 xl:col-span-8">
                    <CardHeader>
                        <CardTitle>Faturamento de vendas</CardTitle>
                        <CardDescription>Mês corrente: linhas de vendas concluídas e despesas lançadas</CardDescription>
                    </CardHeader>

                    <CardContent className="px-4 pb-4 sm:px-6">
                        {chartResource.load.status !== 'ready' ? (
                            <ChartLoadState load={chartResource.load} onRetry={chartResource.retry} />
                        ) : (
                            <SalesRevenueChart data={chartResource.data ?? []} />
                        )}
                    </CardContent>
                </Card>
            </div>

            {kpisReady && (
                <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <InsightCard
                        title="Pendente em vendas"
                        value={formatBRL(kpisSales?.pending_sales_amount)}
                        description="Saldo ainda aberto em vendas parciais ou pendentes"
                        icon={<BadgeDollarSign size={18} />}
                        tone={Number(kpisSales?.pending_sales_amount || 0) > 0 ? 'warning' : 'default'}
                    />
                    <InsightCard
                        title="Vendas canceladas"
                        value={formatBRL(kpisSales?.cancelled_sales_amount)}
                        description={`${Number(kpisSales?.cancelled_sales_count || 0)} ${Number(kpisSales?.cancelled_sales_count || 0) === 1 ? 'cancelamento' : 'cancelamentos'} no período`}
                        icon={<XCircle size={18} />}
                        tone={Number(kpisSales?.cancelled_sales_amount || 0) > 0 ? 'warning' : 'default'}
                    />
                    <TopProductsCard products={kpisSales?.top_products} />
                </div>
            )}
        </div>
    );
}
