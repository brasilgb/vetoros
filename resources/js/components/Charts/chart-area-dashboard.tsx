import * as React from 'react';
import { Bar, BarChart, CartesianGrid, Cell, XAxis, YAxis } from 'recharts';

import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import moment from 'moment';

import { buildEquipmentBars, type ChartLine, type ChartPoint, parseEquipmentChart } from '@/lib/equipment-chart';
import { connectBackend } from '@/Utils/connectApi';
import { useEffect, useState } from 'react';
import { type ChartLoad, ChartLoadState } from './chart-load-state';

export function ChartAreaDashboard({ timerange, dateRange, customRange }: { timerange: string | number; dateRange?: any; customRange?: boolean }) {
    const [chartData, setChartData] = useState<ChartPoint[]>([]);
    const [lines, setLines] = useState<ChartLine[]>([]);
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
        // Ignora a resposta de um período anterior que chegue depois da troca de período.
        let current = true;

        const getChartData = async () => {
            const query = new URLSearchParams();

            if (customRange && dateRange?.from && dateRange?.to) {
                query.set('from', formatIsoDate(dateRange.from));
                query.set('to', formatIsoDate(dateRange.to));
            }

            setLoad({ status: 'loading' });

            try {
                const response = await connectBackend.get(`chartEquipments/${timerange}${query.toString() ? `?${query.toString()}` : ''}`);
                if (!current) return;

                // Sem `lines`/`data` = sem dados (estado vazio); resposta que não é JSON = erro, nunca gráfico fictício.
                const parsed = parseEquipmentChart(response.data);
                if (!parsed.ok) {
                    setLoad({ status: 'error', message: parsed.reason });
                    return;
                }

                setChartData(parsed.data);
                setLines(parsed.lines);
                setLoad({ status: 'ready' });
            } catch {
                if (current) setLoad({ status: 'error', message: 'Não foi possível carregar o gráfico.' });
            }
        };

        getChartData();

        return () => {
            current = false;
        };
    }, [timerange, customRange, dateRange, attempt]);

    const barData = React.useMemo(() => buildEquipmentBars(lines, chartData), [chartData, lines]);

    const chartConfig = React.useMemo(() => {
        return {
            value: {
                label: 'Equipamentos',
                color: 'var(--chart-1)',
            },
        } satisfies ChartConfig;
    }, []);

    function formatAxisLabel(label: string) {
        return label.length > 10 ? `${label.slice(0, 9)}...` : label;
    }

    return (
        <Card className="@container/card">
            <CardHeader>
                <CardTitle>
                    {customRange && dateRange?.from && dateRange?.to
                        ? `Equipamentos recebidos • ${formatBrDate(dateRange.from)} até ${formatBrDate(dateRange.to)}`
                        : `Equipamentos recebidos • Últimos ${timerange} dias`}
                </CardTitle>

                <CardDescription>
                    <span className="hidden @[540px]/card:block">Total de equipamentos recebidos</span>
                </CardDescription>
            </CardHeader>

            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                {load.status !== 'ready' ? (
                    <ChartLoadState load={load} onRetry={() => setAttempt((value) => value + 1)} />
                ) : barData.length === 0 ? (
                    <div className="flex h-[200px] w-full flex-col items-center justify-center rounded-md border border-dashed px-4 text-center">
                        <p className="text-sm font-medium">Sem equipamentos recebidos no período</p>
                        <p className="text-muted-foreground mt-1 text-xs">Altere o período ou registre uma ordem para gerar o gráfico.</p>
                    </div>
                ) : (
                    <ChartContainer config={chartConfig} className="aspect-auto h-[200px] w-full">
                        <BarChart data={barData} margin={{ top: 4, right: 8, left: 0, bottom: 0 }}>
                            <CartesianGrid vertical={false} />
                            <XAxis dataKey="label" tickLine={false} axisLine={false} tickMargin={8} minTickGap={12} tickFormatter={formatAxisLabel} />
                            <YAxis tickLine={false} axisLine={false} width={28} allowDecimals={false} />
                            <ChartTooltip
                                cursor={false}
                                content={
                                    <ChartTooltipContent
                                        hideLabel
                                        formatter={(value, _, item) => (
                                            <div className="flex w-full items-center justify-between gap-3">
                                                <span>{String(item.payload.label)}</span>
                                                <span className="font-mono font-medium tabular-nums">{Number(value || 0)}</span>
                                            </div>
                                        )}
                                    />
                                }
                            />
                            <Bar dataKey="value" name="value" radius={[4, 4, 0, 0]} maxBarSize={32}>
                                {barData.map((item) => (
                                    <Cell key={item.key} fill={item.fill} />
                                ))}
                            </Bar>
                        </BarChart>
                    </ChartContainer>
                )}
            </CardContent>
        </Card>
    );
}
