import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { Progress } from '@/components/ui/progress';
import { connectBackend } from '@/Utils/connectApi';
import moment from 'moment';
import { useEffect, useMemo, useState } from 'react';
import { Pie, PieChart } from 'recharts';
import { type ChartLoad, ChartLoadState } from './chart-load-state';

type BudgetsStatusResponse = {
    generated: number;
    approved: number;
    total: number;
    approval_rate: number;
};

type DateRangeValue = {
    from?: Date | string;
    to?: Date | string;
};

function formatIsoDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('YYYY-MM-DD');
}

function formatBrDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

export default function ChartBudgetsStatus({
    timerange,
    dateRange,
    customRange,
}: {
    timerange: string | number;
    dateRange?: DateRangeValue;
    customRange?: boolean;
}) {
    const [load, setLoad] = useState<ChartLoad>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);
    const [statusData, setStatusData] = useState<BudgetsStatusResponse>({
        generated: 0,
        approved: 0,
        total: 0,
        approval_rate: 0,
    });

    useEffect(() => {
        let current = true;

        const getBudgetsStatus = async () => {
            setLoad({ status: 'loading' });
            try {
                const query = new URLSearchParams();

                if (customRange && dateRange?.from && dateRange?.to) {
                    query.set('from', formatIsoDate(dateRange.from));
                    query.set('to', formatIsoDate(dateRange.to));
                }

                const response = await connectBackend.get(`budgetsStatusChart/${timerange}${query.toString() ? `?${query.toString()}` : ''}`);
                if (!current) return;
                // Falha não pode aparecer como "0 gerados / 0 aprovados".
                if (typeof response.data !== 'object' || response.data === null || Array.isArray(response.data)) {
                    setLoad({ status: 'error', message: 'Resposta inesperada do servidor.' });
                    return;
                }
                setStatusData(response.data);
                setLoad({ status: 'ready' });
            } catch (error) {
                console.error('Erro ao carregar dados de orçamentos gerados/aprovados', error);
                if (current) setLoad({ status: 'error', message: 'Não foi possível carregar o gráfico.' });
            }
        };

        getBudgetsStatus();

        return () => {
            current = false;
        };
    }, [timerange, customRange, dateRange, attempt]);

    const chartConfig = {
        generated: {
            label: 'Gerados',
            color: 'var(--chart-1)',
        },
        approved: {
            label: 'Aprovados',
            color: 'var(--chart-2)',
        },
    };

    const pieData = useMemo(
        () =>
            [
                { key: 'generated', label: 'Gerados', value: Number(statusData.generated || 0), fill: 'var(--color-generated)' },
                { key: 'approved', label: 'Aprovados', value: Number(statusData.approved || 0), fill: 'var(--color-approved)' },
            ].filter((item) => item.value > 0),
        [statusData],
    );
    const approvalRate = Math.max(0, Math.min(100, Number(statusData.approval_rate || 0)));

    return (
        <Card className="@container/card">
            <CardHeader>
                <CardTitle>
                    {customRange && dateRange?.from && dateRange?.to
                        ? `Orçamentos • ${formatBrDate(dateRange.from)} até ${formatBrDate(dateRange.to)}`
                        : `Orçamentos • Últimos ${timerange} dias`}
                </CardTitle>
            </CardHeader>

            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                {load.status !== 'ready' ? (
                    <ChartLoadState load={load} onRetry={() => setAttempt((value) => value + 1)} />
                ) : pieData.length === 0 ? (
                    <div className="flex h-[200px] w-full flex-col items-center justify-center rounded-md border border-dashed px-4 text-center">
                        <p className="text-sm font-medium">Sem orçamentos no período</p>
                        <p className="text-muted-foreground mt-1 text-xs">Altere o período ou gere um orçamento para acompanhar o aproveitamento.</p>
                    </div>
                ) : (
                    <div className="grid items-center gap-4 md:grid-cols-[1fr_170px]">
                        <ChartContainer config={chartConfig} className="aspect-auto h-[200px] w-full">
                            <PieChart>
                                <ChartTooltip cursor={false} content={<ChartTooltipContent nameKey="key" hideLabel />} />
                                <Pie data={pieData} dataKey="value" nameKey="label" innerRadius={45} outerRadius={80} strokeWidth={2} />
                                <ChartLegend
                                    align="left"
                                    verticalAlign="middle"
                                    layout="vertical"
                                    content={<ChartLegendContent nameKey="key" className="flex-col items-start justify-start pt-0 pl-2" />}
                                />
                            </PieChart>
                        </ChartContainer>

                        <div className="space-y-2 rounded-md border p-3">
                            <div className="text-sm font-medium">Aproveitamento</div>
                            <Progress value={approvalRate} />
                            <div className="text-muted-foreground text-xs">{approvalRate.toFixed(1)}%</div>
                            <div className="text-muted-foreground text-xs">Gerados: {statusData.generated}</div>
                            <div className="text-muted-foreground text-xs">Aprovados: {statusData.approved}</div>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}
