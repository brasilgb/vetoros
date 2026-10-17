import { ChartConfig, ChartContainer, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { formatBRL } from '@/lib/money';
import moment from 'moment';
import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';

const chartConfig = {
    total: {
        label: 'Comprado',
        color: 'var(--chart-1)',
    },
} satisfies ChartConfig;

export function ChartPurchasesTrend({ data }: { data: Array<{ date: string; total: number }> }) {
    const chartData = data.map((item) => ({
        ...item,
        monthLabel: moment(item.date).format('MMM/YY'),
    }));

    return (
        <ChartContainer config={chartConfig} className="h-[260px] w-full sm:h-[300px]">
            <BarChart data={chartData} margin={{ top: 8, right: 12, left: 0, bottom: 0 }}>
                <CartesianGrid vertical={false} />
                <XAxis dataKey="monthLabel" tickLine={false} axisLine={false} />
                <YAxis tickLine={false} axisLine={false} width={64} tickFormatter={(value) => formatBRL(value, { wholeReais: true })} />
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            labelFormatter={(_, payload) => (payload?.[0]?.payload?.date ? moment(payload[0].payload.date).format('MMMM/YYYY') : '')}
                            formatter={(value) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                    <span>Comprado</span>
                                    <span className="font-mono font-medium tabular-nums">{formatBRL(Number(value || 0))}</span>
                                </div>
                            )}
                        />
                    }
                />
                <Bar dataKey="total" name="total" fill="var(--color-total)" radius={[4, 4, 0, 0]} maxBarSize={48} />
            </BarChart>
        </ChartContainer>
    );
}
