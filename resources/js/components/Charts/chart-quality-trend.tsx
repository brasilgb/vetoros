import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { CartesianGrid, Line, LineChart, ReferenceLine, XAxis, YAxis } from 'recharts';

const chartConfig = {
    rate: {
        label: 'Taxa de retorno (%)',
        color: '#0f766e',
    },
    threshold: {
        label: 'Limite (%)',
        color: '#dc2626',
    },
} satisfies ChartConfig;

export function ChartQualityTrend({
    trend,
    threshold,
}: {
    trend?: { granularity?: string; data?: Array<{ label: string; rate: number; returns: number; total_orders: number }> };
    threshold?: number;
}) {
    const data = trend?.data ?? [];
    const granularityLabel = trend?.granularity === 'monthly' ? 'mensal' : trend?.granularity === 'weekly' ? 'semanal' : 'diaria';

    return (
        <Card>
            <CardHeader>
                <CardTitle>Tendência de retorno em garantia</CardTitle>
                <CardDescription>Evolução {granularityLabel} da taxa de retorno em garantia no período selecionado.</CardDescription>
            </CardHeader>
            <CardContent className="px-2 pt-4 sm:px-6 sm:pt-6">
                <ChartContainer config={chartConfig} className="aspect-auto h-[280px] w-full">
                    <LineChart data={data} margin={{ top: 8, right: 12, left: 12, bottom: 0 }}>
                        <CartesianGrid vertical={false} />
                        <XAxis dataKey="label" tickLine={false} axisLine={false} minTickGap={18} />
                        <YAxis tickLine={false} axisLine={false} width={44} domain={[0, 'auto']} />
                        <ChartTooltip
                            content={
                                <ChartTooltipContent
                                    formatter={(value, name, item: any) => {
                                        if (name === 'rate') {
                                            return (
                                                <>
                                                    <span>Taxa</span>
                                                    <span>{Number(value).toFixed(1)}%</span>
                                                    <span className="text-muted-foreground block w-full text-[11px]">
                                                        {item?.payload?.returns ?? 0} retornos / {item?.payload?.total_orders ?? 0} ordens
                                                    </span>
                                                </>
                                            );
                                        }

                                        return (
                                            <>
                                                <span>Limite</span>
                                                <span>{Number(value).toFixed(1)}%</span>
                                            </>
                                        );
                                    }}
                                />
                            }
                        />
                        <ReferenceLine y={threshold ?? 0} stroke="var(--color-threshold)" strokeDasharray="4 4" />
                        <Line type="monotone" dataKey="rate" stroke="var(--color-rate)" strokeWidth={2.5} dot={{ r: 3 }} activeDot={{ r: 5 }} />
                    </LineChart>
                </ChartContainer>
            </CardContent>
        </Card>
    );
}
