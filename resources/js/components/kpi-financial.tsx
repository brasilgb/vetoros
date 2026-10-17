import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { formatBRL } from '@/lib/money';
import { ReactNode } from 'react';

interface KpiFinancialProps {
    title: string;
    total?: number | string;
    services?: number | string;
    parts?: number | string;
    orders?: number;
    ordersLabel?: string;
    comparison?: {
        change?: number;
        percent?: number | null;
        previous?: number;
    };
    icon?: ReactNode;
}

export function KpiFinancial({ title, total = 0, services = 0, parts = 0, orders = 0, ordersLabel, comparison, icon }: KpiFinancialProps) {
    const toNumber = (value?: number | string) => Number(value || 0);

    const format = (v?: number | string) => formatBRL(v);

    const formatPercent = (value?: number | null) =>
        value === null || value === undefined ? 'Sem base anterior' : `${value > 0 ? '+' : ''}${value.toFixed(1)}%`;

    const totalValue = toNumber(total);
    const servicesValue = toNumber(services);
    const partsValue = toNumber(parts);

    const percentServices = totalValue > 0 ? Math.round((servicesValue / totalValue) * 100) : 0;

    const percentParts = totalValue > 0 ? Math.round((partsValue / totalValue) * 100) : 0;
    const comparisonClass = Number(comparison?.change || 0) >= 0 ? 'text-emerald-600' : 'text-rose-600';

    return (
        <Card className="h-full min-w-0">
            <CardHeader className="flex flex-row items-center justify-between gap-3 pb-2">
                <CardDescription>{title}</CardDescription>

                <div className="text-muted-foreground shrink-0">{icon}</div>
            </CardHeader>

            <CardContent className="space-y-4">
                {/* VALOR PRINCIPAL */}
                <CardTitle className="text-xl leading-tight font-bold break-words tabular-nums sm:text-2xl 2xl:text-3xl">{format(total)}</CardTitle>

                {comparison && (
                    <div className={`text-xs font-medium ${comparisonClass}`}>{formatPercent(comparison.percent)} vs período anterior</div>
                )}

                {/* ORIGEM DO FATURAMENTO */}
                <div className="bg-muted flex h-2 w-full overflow-hidden rounded-full">
                    <div className="bg-primary" style={{ width: `${percentServices}%` }} />
                    <div className="bg-emerald-500" style={{ width: `${percentParts}%` }} />
                </div>

                {/* BREAKDOWN */}
                <div className="grid gap-3 text-sm sm:grid-cols-2">
                    <div className="flex flex-col rounded-lg border p-3">
                        <span className="text-muted-foreground flex justify-between text-xs">
                            Serviços
                            <span>{percentServices}%</span>
                        </span>

                        <span className="font-medium tabular-nums">{format(services)}</span>
                    </div>

                    <div className="flex flex-col rounded-lg border p-3">
                        <span className="text-muted-foreground flex justify-between text-xs">
                            Peças
                            <span>{percentParts}%</span>
                        </span>

                        <span className="font-medium tabular-nums">{format(parts)}</span>
                    </div>
                </div>

                {/* ORDENS ANALISADAS */}
                <div className="text-muted-foreground border-t pt-2 text-xs">
                    {ordersLabel ?? `${orders} ${orders > 1 ? 'ordens' : 'ordem'} ${orders > 1 ? 'analisadas' : 'analisada'}`}
                </div>
            </CardContent>
        </Card>
    );
}
