import { connectBackend } from '@/Utils/connectApi';
import moment from 'moment';
import { useEffect, useState } from 'react';
import { Pie, PieChart } from 'recharts';
import { type ChartLoad, ChartLoadState } from './chart-load-state';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

import { ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';

type OrdersPoint = {
    period: string;
    entradas: number;
    concluidos: number;
    entregues: number;
};

export default function ChartFluxoOrders({
    timerange,
    dateRange,
    customRange,
}: {
    timerange: string | number;
    dateRange?: any;
    customRange?: boolean;
}) {
    const [orders, setOrders] = useState<OrdersPoint[]>([]);
    const [load, setLoad] = useState<ChartLoad>({ status: 'loading' });
    const [attempt, setAttempt] = useState(0);

    function formatIsoDate(date: Date | string) {
        const d = date instanceof Date ? date : new Date(date);
        return moment(d).format('YYYY-MM-DD');
    }

    function formatBrDate(date: Date | string) {
        const d = date instanceof Date ? date : new Date(date);
        return moment(d).format('DD/MM/YYYY');
    }

    useEffect(() => {
        let current = true;

        const getOrders = async () => {
            setLoad({ status: 'loading' });
            try {
                const query = new URLSearchParams();

                if (customRange && dateRange?.from && dateRange?.to) {
                    query.set('from', formatIsoDate(dateRange.from));
                    query.set('to', formatIsoDate(dateRange.to));
                }

                const response = await connectBackend.get(`fluxsOrders/${timerange}${query.toString() ? `?${query.toString()}` : ''}`);
                if (!current) return;
                // A API devolve uma lista por dia; outro formato (ex.: página de login) é erro, não "sem dados".
                if (!Array.isArray(response.data)) {
                    setLoad({ status: 'error', message: 'Resposta inesperada do servidor.' });
                    return;
                }
                setOrders(response.data);
                setLoad({ status: 'ready' });
            } catch (error) {
                console.error('Erro ao carregar dados do gráfico Fluxo de ordens', error);
                if (current) setLoad({ status: 'error', message: 'Não foi possível carregar o gráfico.' });
            }
        };
        getOrders();

        return () => {
            current = false;
        };
    }, [timerange, customRange, dateRange, attempt]);

    const chartConfig = {
        entradas: {
            label: 'Entradas',
            color: 'var(--chart-1)',
        },
        concluidos: {
            label: 'Concluídos',
            color: 'var(--chart-2)',
        },
        entregues: {
            label: 'Entregues',
            color: 'var(--chart-3)',
        },
    };

    const pieData = [
        {
            key: 'entradas',
            label: 'Entradas',
            value: orders.reduce((acc, item) => acc + Number(item.entradas || 0), 0),
            fill: 'var(--chart-1)',
        },
        {
            key: 'concluidos',
            label: 'Concluídos',
            value: orders.reduce((acc, item) => acc + Number(item.concluidos || 0), 0),
            fill: 'var(--chart-2)',
        },
        {
            key: 'entregues',
            label: 'Entregues',
            value: orders.reduce((acc, item) => acc + Number(item.entregues || 0), 0),
            fill: 'var(--chart-3)',
        },
    ].filter((item) => item.value > 0);

    return (
        <Card className="@container/card">
            <CardHeader>
                <CardTitle>
                    {customRange && dateRange?.from && dateRange?.to
                        ? `Fluxo de Ordens • ${formatBrDate(dateRange.from)} até ${formatBrDate(dateRange.to)}`
                        : `Fluxo de Ordens Últimos • ${timerange} dias`}
                </CardTitle>

                <CardDescription>
                    <span className="hidden @[540px]/card:block">Entradas x Concluídos x Entregues</span>
                </CardDescription>
            </CardHeader>

            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                {load.status !== 'ready' ? (
                    <ChartLoadState load={load} onRetry={() => setAttempt((value) => value + 1)} />
                ) : pieData.length === 0 ? (
                    <div className="flex h-[200px] w-full flex-col items-center justify-center rounded-md border border-dashed px-4 text-center">
                        <p className="text-sm font-medium">Sem movimentação de ordens no período</p>
                        <p className="text-muted-foreground mt-1 text-xs">Altere o período ou registre uma ordem para acompanhar o fluxo.</p>
                    </div>
                ) : (
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
                )}
            </CardContent>
        </Card>
    );
}
