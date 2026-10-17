import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { CalendarCheck, CalendarClock, Clock, MapPin, Receipt, TrendingUp } from 'lucide-react';
import moment from 'moment';
import { useMemo } from 'react';
import { CartesianGrid, Line, LineChart, XAxis, YAxis } from 'recharts';

import { ChartLoadState } from '@/components/Charts/chart-load-state';
import { ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { useDashboardResource } from '@/hooks/use-dashboard-resource';
import { parseKpisPayload, parseSeriesPayload } from '@/lib/dashboard-payload';
import { formatBRL } from '@/lib/money';

type DateRangeValue = {
    from?: Date | string;
    to?: Date | string;
};

type KpiComparison = {
    current: number;
    previous: number;
    change: number;
    percent: number | null;
};

type ScheduleKpis = {
    total: number;
    open: number;
    in_progress: number;
    completed: number;
    overdue: number;
    today: number;
    tomorrow: number;
    completion_rate: number;
    average_service_minutes: number;
    local_payment_total: number;
    comparison?: {
        total?: KpiComparison;
        completed?: KpiComparison;
        completion_rate?: KpiComparison;
        average_service_minutes?: KpiComparison;
        local_payment_total?: KpiComparison;
    };
};

type ScheduleChartPoint = {
    date: string;
    total: number;
    open: number;
    in_progress: number;
    completed: number;
};

function formatIsoDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('YYYY-MM-DD');
}

function formatBrDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

function formatDuration(minutes?: number | null) {
    const total = Math.round(Number(minutes || 0));

    if (total <= 0) return 'Sem dados';
    if (total < 60) return `${total} min`;

    const hours = Math.floor(total / 60);
    const rest = total % 60;

    return rest > 0 ? `${hours}h ${rest}min` : `${hours}h`;
}

function formatPercent(value?: number | null) {
    return `${Number(value || 0).toLocaleString('pt-BR', { maximumFractionDigits: 1 })}%`;
}

function comparisonLabel(comparison?: KpiComparison) {
    if (!comparison || comparison.percent === null) return 'Sem comparativo anterior';

    const sign = comparison.percent > 0 ? '+' : '';

    return `${sign}${comparison.percent.toLocaleString('pt-BR', { maximumFractionDigits: 1 })}% vs período anterior`;
}

function buildQuery(customRange?: boolean, dateRange?: DateRangeValue) {
    const query = new URLSearchParams();

    if (customRange && dateRange?.from && dateRange?.to) {
        query.set('from', formatIsoDate(dateRange.from));
        query.set('to', formatIsoDate(dateRange.to));
    }

    return query.toString() ? `?${query.toString()}` : '';
}

function ScheduleMetricCard({
    title,
    value,
    description,
    icon,
}: {
    title: string;
    value: string | number;
    description: string;
    icon: React.ReactNode;
}) {
    return (
        <Card className="min-w-0">
            <CardHeader className="flex flex-row items-start justify-between gap-3 pb-2">
                <div className="min-w-0 space-y-1">
                    <CardDescription>{title}</CardDescription>
                    <CardTitle className="truncate text-3xl font-bold tabular-nums">{value}</CardTitle>
                </div>
                <div className="text-muted-foreground shrink-0">{icon}</div>
            </CardHeader>
            <CardContent className="text-muted-foreground text-xs">{description}</CardContent>
        </Card>
    );
}

const chartConfig = {
    open: {
        label: 'Abertos',
        color: 'var(--chart-3)',
    },
    in_progress: {
        label: 'Em atendimento',
        color: 'var(--chart-2)',
    },
    completed: {
        label: 'Concluídos',
        color: 'var(--chart-1)',
    },
} satisfies ChartConfig;

export default function ScheduleDashboard({
    timerange,
    dateRange,
    customRange,
}: {
    timerange: number;
    dateRange?: DateRangeValue;
    customRange?: boolean;
}) {
    // Indicadores e gráfico validados (VETOR-STABILIZE-03): falha da API mostra erro, nunca zeros fictícios.
    const kpisResource = useDashboardResource(`kpisSchedules/${timerange}${buildQuery(customRange, dateRange)}`, parseKpisPayload<ScheduleKpis>);
    const chartResource = useDashboardResource(
        `schedulesStatusChart/${timerange}${buildQuery(customRange, dateRange)}`,
        parseSeriesPayload<ScheduleChartPoint>,
    );
    const kpis = kpisResource.data;
    const kpisReady = kpisResource.load.status === 'ready';
    const chartData = chartResource.data;

    const rangeLabel =
        customRange && dateRange?.from && dateRange?.to ? `${formatBrDate(dateRange.from)} a ${formatBrDate(dateRange.to)}` : `${timerange} dias`;
    const completionRate = Number(kpis?.completion_rate || 0);
    const pendingCount = Number(kpis?.open || 0) + Number(kpis?.in_progress || 0);
    const chart = useMemo(
        () =>
            (chartData ?? []).map((item) => ({
                ...item,
                label: moment(item.date).format('DD/MM'),
            })),
        [chartData],
    );

    return (
        <div className="min-w-0">
            {kpisResource.load.status !== 'ready' ? (
                <ChartLoadState load={kpisResource.load} onRetry={kpisResource.retry} className="h-[232px]" label="indicadores" />
            ) : (
                <>
                    <div className="grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                        <ScheduleMetricCard
                            title={`Agendamentos - ${rangeLabel}`}
                            value={kpis?.total ?? 0}
                            description={comparisonLabel(kpis?.comparison?.total)}
                            icon={<CalendarClock size={18} />}
                        />
                        <ScheduleMetricCard
                            title="Concluídos"
                            value={kpis?.completed ?? 0}
                            description={comparisonLabel(kpis?.comparison?.completed)}
                            icon={<CalendarCheck size={18} />}
                        />
                        <ScheduleMetricCard
                            title="Taxa de conclusão"
                            value={formatPercent(completionRate)}
                            description={comparisonLabel(kpis?.comparison?.completion_rate)}
                            icon={<TrendingUp size={18} />}
                        />
                        <ScheduleMetricCard
                            title="Pagamento local"
                            value={formatBRL(kpis?.local_payment_total)}
                            description={comparisonLabel(kpis?.comparison?.local_payment_total)}
                            icon={<Receipt size={18} />}
                        />
                    </div>

                    <div className="mt-3 grid gap-3 sm:grid-cols-2 2xl:grid-cols-4">
                        <ScheduleMetricCard
                            title="Hoje"
                            value={kpis?.today ?? 0}
                            description="Atendimentos programados para hoje"
                            icon={<CalendarClock size={18} />}
                        />
                        <ScheduleMetricCard
                            title="Amanhã"
                            value={kpis?.tomorrow ?? 0}
                            description="Atendimentos programados para amanhã"
                            icon={<CalendarClock size={18} />}
                        />
                        <ScheduleMetricCard
                            title="Em aberto"
                            value={pendingCount}
                            description={`${kpis?.in_progress ?? 0} em atendimento`}
                            icon={<MapPin size={18} />}
                        />
                        <ScheduleMetricCard
                            title="Tempo médio"
                            value={formatDuration(kpis?.average_service_minutes)}
                            description={comparisonLabel(kpis?.comparison?.average_service_minutes)}
                            icon={<Clock size={18} />}
                        />
                    </div>
                </>
            )}

            <div className="mt-3 grid gap-4 xl:grid-cols-3">
                <Card className="min-w-0 xl:col-span-2">
                    <CardHeader>
                        <CardTitle>Status dos agendamentos</CardTitle>
                        <CardDescription>Volume diário por status no período de {rangeLabel}</CardDescription>
                    </CardHeader>
                    <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                        {chartResource.load.status !== 'ready' ? (
                            <ChartLoadState load={chartResource.load} onRetry={chartResource.retry} className="h-[260px]" />
                        ) : (
                            <ChartContainer config={chartConfig} className="aspect-auto h-[260px] w-full">
                                <LineChart data={chart} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                                    <CartesianGrid vertical={false} />
                                    <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} minTickGap={18} />
                                    <YAxis tickLine={false} axisLine={false} width={28} allowDecimals={false} />
                                    <ChartTooltip cursor={false} content={<ChartTooltipContent />} />
                                    <Line type="monotone" dataKey="open" stroke="var(--color-open)" strokeWidth={2} dot={false} />
                                    <Line type="monotone" dataKey="in_progress" stroke="var(--color-in_progress)" strokeWidth={2} dot={false} />
                                    <Line type="monotone" dataKey="completed" stroke="var(--color-completed)" strokeWidth={2} dot={false} />
                                </LineChart>
                            </ChartContainer>
                        )}
                    </CardContent>
                </Card>

                <Card className="min-w-0">
                    <CardHeader>
                        <CardTitle>Alertas</CardTitle>
                        <CardDescription>Visão rápida dos pontos que precisam de atenção</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {!kpisReady ? (
                            <ChartLoadState
                                load={kpisResource.load.status === 'ready' ? { status: 'loading' } : kpisResource.load}
                                onRetry={kpisResource.retry}
                                className="h-[150px]"
                                label="alertas"
                            />
                        ) : (
                            <>
                                <div className="rounded-lg border p-3">
                                    <div className="text-2xl font-bold tabular-nums">{kpis?.overdue ?? 0}</div>
                                    <div className="text-muted-foreground text-sm">Agendamentos atrasados</div>
                                </div>
                                <div className="rounded-lg border p-3">
                                    <div className="text-2xl font-bold tabular-nums">{kpis?.in_progress ?? 0}</div>
                                    <div className="text-muted-foreground text-sm">Atendimentos em execução</div>
                                </div>
                            </>
                        )}
                    </CardContent>
                </Card>
            </div>
        </div>
    );
}
