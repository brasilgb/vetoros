import { Bar, BarChart, CartesianGrid, Pie, PieChart, XAxis, YAxis } from 'recharts';

import { ChartConfig, ChartContainer, ChartLegend, ChartLegendContent, ChartTooltip, ChartTooltipContent } from '@/components/ui/chart';
import { formatBRL } from '@/lib/money';
import moment from 'moment';

const chartConfig = {
    total: {
        label: 'Total',
        color: 'var(--chart-1)',
    },
    services: {
        label: 'Serviços',
        color: 'var(--chart-2)',
    },
    parts: {
        label: 'Peças',
        color: 'var(--chart-3)',
    },
} satisfies ChartConfig;

const trendChartConfig = {
    services: {
        label: 'Serviços',
        color: 'var(--chart-2)',
    },
    parts: {
        label: 'Peças',
        color: 'var(--chart-3)',
    },
} satisfies ChartConfig;

type FinancialRevenuePoint = {
    date: string;
    total: number;
    services: number;
    parts: number;
};

export function FinancialRevenueChart({ data }: { data: FinancialRevenuePoint[] }) {
    const totals = data.reduce(
        (acc, item) => {
            acc.services += Number(item.services || 0);
            acc.parts += Number(item.parts || 0);
            return acc;
        },
        { services: 0, parts: 0 },
    );

    const pieData = [
        { key: 'services', label: 'Serviços', value: totals.services, fill: 'var(--color-services)' },
        { key: 'parts', label: 'Peças', value: totals.parts, fill: 'var(--color-parts)' },
    ].filter((item) => item.value > 0);

    return (
        <ChartContainer config={chartConfig} className="h-[260px] w-full sm:h-[300px]">
            <PieChart>
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            nameKey="key"
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
                <Pie data={pieData} dataKey="value" nameKey="label" innerRadius={42} outerRadius={78} strokeWidth={2} />
                <ChartLegend
                    align="left"
                    verticalAlign="bottom"
                    layout="horizontal"
                    content={<ChartLegendContent nameKey="key" className="justify-start gap-3 pt-4 sm:justify-center" />}
                />
            </PieChart>
        </ChartContainer>
    );
}

export function FinancialRevenueTrendChart({ data }: { data: FinancialRevenuePoint[] }) {
    const chartData = data.map((item) => ({
        ...item,
        dateLabel: moment(item.date).format('DD/MM'),
    }));

    return (
        <ChartContainer config={trendChartConfig} className="h-[240px] w-full sm:h-[280px] lg:h-[300px]">
            <BarChart data={chartData} margin={{ top: 8, right: 6, left: 0, bottom: 0 }}>
                <CartesianGrid vertical={false} />
                <XAxis dataKey="dateLabel" tickLine={false} axisLine={false} minTickGap={24} />
                <YAxis tickLine={false} axisLine={false} width={56} tickFormatter={(value) => formatBRL(value, { wholeReais: true })} />
                <ChartTooltip
                    cursor={false}
                    content={
                        <ChartTooltipContent
                            labelFormatter={(_, payload) => (payload?.[0]?.payload?.date ? moment(payload[0].payload.date).format('DD/MM/YYYY') : '')}
                            formatter={(value, name) => {
                                const label = name === 'services' ? 'Serviços' : 'Peças';
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
                <Bar dataKey="services" name="services" fill="var(--color-services)" radius={[4, 4, 0, 0]} maxBarSize={16} />
                <Bar dataKey="parts" name="parts" fill="var(--color-parts)" radius={[4, 4, 0, 0]} maxBarSize={16} />
                <ChartLegend content={<ChartLegendContent nameKey="name" className="justify-start gap-3 pt-4 sm:justify-center" />} />
            </BarChart>
        </ChartContainer>
    );
}
