import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { orderStatusLabel } from '@/Utils/order-status';
import { Head, Link } from '@inertiajs/react';
import axios, { isAxiosError } from 'axios';
import { AlertTriangle, ChartColumnIncreasing, Info, Loader2 } from 'lucide-react';
import moment from 'moment';
import { FormEvent, ReactNode, useCallback, useEffect, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Indicadores', href: route('app.intel.indicators') },
];

type Filters = { from: string; to: string; stalled_days: number; expiring_days: number };

type Indicators = {
    period: { from: string; to: string };
    stalled_orders: {
        threshold_days: number;
        total: number;
        reference_unknown: number;
        orders: {
            order_id: number;
            order_number: number;
            status: number;
            days: number;
            reference: 'order_events' | 'order_status_history';
            customer: string | null;
            equipment: string | null;
            technician: string | null;
        }[];
    };
    overdue_orders: { total: number; renegotiated: number; past_original: number; past_original_renegotiated: number; original_unknown: number };
    budgets_awaiting: { count: number; amount: number; average_age_days: number | null; age_unknown: number };
    budgets_expiring: {
        within_days: number;
        count: number;
        amount: number;
        budgets: { order_id: number; version: number; valid_until: string; quoted_amount: number }[];
    };
    budget_conversion: {
        cycles: number;
        approved: number;
        rejected: number;
        expired: number;
        pending: number;
        conversion_rate: number | null;
        approval_time_hours: { average: number | null; median: number | null };
        average_versions_per_cycle: number | null;
        legacy_excluded: number;
    };
    technician_productivity: {
        technicians: { technician_id: number; name: string | null; completed: number; delivered: number; warranty_returns: number }[];
        unattributed: { completed: number; delivered: number };
    };
    deadline_compliance: {
        delivered: number;
        on_time: number;
        late: number;
        on_time_rate: number | null;
        average_delay_days: number | null;
        renegotiated: number;
        original_unknown: number;
    };
    profitability: {
        delivered: number;
        complete: number;
        incomplete: number;
        revenue_complete: number;
        known_cost_complete: number;
        margin_complete: number;
        margin_rate_complete: number | null;
        incomplete_by_component: Record<string, Record<string, number>>;
    };
    data_quality: Record<string, number>;
};

const COMPONENT_LABELS: Record<string, string> = {
    stock_parts: 'custo de peças de estoque',
    manual_parts: 'custo de peças avulsas',
    commission: 'comissão',
    payment_fees: 'taxas de pagamento',
};

const STATUS_LABELS: Record<string, string> = { unknown: 'desconhecido', pending: 'pendente' };

const QUALITY_LABELS: Record<string, string> = {
    stalled_reference_unknown: 'OS ativas sem histórico de status',
    active_original_unknown: 'OS ativas sem prazo original',
    budgets_age_unknown: 'orçamentos aguardando sem data de envio',
    productivity_unattributed_events: 'conclusões/entregas sem técnico atribuído',
    deadline_original_unknown: 'entregas sem prazo original',
    profitability_incomplete: 'OS entregues com custos incompletos',
};

const currency = (value: number) => value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const plural = (count: number, one: string, many: string) => `${count} ${count === 1 ? one : many}`;

function quickRange(key: string, maxDays: number): Pick<Filters, 'from' | 'to'> {
    const today = moment();
    const ranges: Record<string, [moment.Moment, moment.Moment]> = {
        today: [today.clone(), today.clone()],
        '7d': [today.clone().subtract(6, 'days'), today.clone()],
        '30d': [today.clone().subtract(29, 'days'), today.clone()],
        '90d': [today.clone().subtract(89, 'days'), today.clone()],
        month: [today.clone().startOf('month'), today.clone()],
        previous: [today.clone().subtract(1, 'month').startOf('month'), today.clone().subtract(1, 'month').endOf('month')],
    };
    const [from, to] = ranges[key] ?? ranges['30d'];

    return { from: from.format('YYYY-MM-DD'), to: (to.diff(from, 'days') > maxDays ? from.clone().add(maxDays, 'days') : to).format('YYYY-MM-DD') };
}

/** Valor principal: null = não foi possível calcular (nunca exibido como zero). */
function MetricValue({ value }: { value: string | null }) {
    return value === null ? (
        <p className="text-muted-foreground text-2xl font-semibold" title="Sem dados suficientes para calcular">
            —
        </p>
    ) : (
        <p className="text-2xl font-semibold tracking-tight">{value}</p>
    );
}

function MetricCard({
    title,
    value,
    context,
    warning,
    href,
}: {
    title: string;
    value: string | null;
    context: ReactNode;
    warning?: ReactNode;
    href?: string;
}) {
    const body = (
        <Card className="h-full transition hover:shadow-sm">
            <CardContent className="space-y-1 p-4">
                <p className="text-muted-foreground text-sm font-medium">{title}</p>
                <MetricValue value={value} />
                <div className="text-muted-foreground text-xs">{context}</div>
                {warning ? (
                    <p className="flex items-start gap-1 text-xs text-amber-700 dark:text-amber-400">
                        <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" aria-hidden="true" />
                        <span>{warning}</span>
                    </p>
                ) : null}
            </CardContent>
        </Card>
    );

    return href ? (
        <a href={href} className="block focus-visible:ring-2 focus-visible:ring-offset-2 focus-visible:outline-none">
            {body}
        </a>
    ) : (
        body
    );
}

function Section({ id, title, description, children }: { id: string; title: string; description: string; children: ReactNode }) {
    return (
        <section aria-labelledby={id} className="space-y-3">
            <div>
                <h3 id={id} className="text-base font-semibold">
                    {title}
                </h3>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>
            {children}
        </section>
    );
}

export default function IntelIndicators({ defaults }: { defaults: Filters & { max_period_days: number } }) {
    const maxDays = defaults.max_period_days;
    const [filters, setFilters] = useState<Filters>({
        from: defaults.from,
        to: defaults.to,
        stalled_days: defaults.stalled_days,
        expiring_days: defaults.expiring_days,
    });
    const [applied, setApplied] = useState<Filters>(filters);
    const [data, setData] = useState<Indicators | null>(null);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);

    const load = useCallback(async (params: Filters) => {
        setLoading(true);
        setError(null);

        try {
            // Endpoint consolidado: uma única chamada traz todos os indicadores.
            const response = await axios.get<Indicators>(route('app.intel.indicators'), { params, headers: { Accept: 'application/json' } });
            setData(response.data);
        } catch (exception) {
            const message = isAxiosError(exception)
                ? (exception.response?.data?.message ?? 'Não foi possível carregar os indicadores.')
                : 'Não foi possível carregar os indicadores.';
            setError(message);
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        load(applied);
    }, [applied, load]);

    const periodDays = moment(filters.to).diff(moment(filters.from), 'days');
    const periodInvalid = !filters.from || !filters.to || periodDays < 0 || periodDays > maxDays;

    const apply = (e: FormEvent) => {
        e.preventDefault();
        if (!periodInvalid) setApplied({ ...filters });
    };

    const applyQuick = (key: string) => {
        const next = { ...filters, ...quickRange(key, maxDays) };
        setFilters(next);
        setApplied(next);
    };

    const quality = data ? Object.entries(data.data_quality).filter(([, count]) => count > 0) : [];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Indicadores" />

            <div className="flex min-h-20 items-center gap-2 px-4 py-3">
                <ChartColumnIncreasing className="h-8 w-8" aria-hidden="true" />
                <div>
                    <h2 className="text-xl font-semibold tracking-tight">Indicadores</h2>
                    <p className="text-muted-foreground text-sm">O que está parado, atrasado, aguardando decisão e quanto as OS realmente rendem.</p>
                </div>
            </div>

            <div className="space-y-8 px-4 pb-8">
                <form onSubmit={apply} className="space-y-3 rounded-lg border p-4" aria-label="Filtros dos indicadores">
                    <div className="flex flex-wrap gap-2">
                        {[
                            ['today', 'Hoje'],
                            ['7d', '7 dias'],
                            ['30d', '30 dias'],
                            ['90d', '90 dias'],
                            ['month', 'Este mês'],
                            ['previous', 'Mês anterior'],
                        ].map(([key, label]) => (
                            <Button key={key} type="button" size="sm" variant="outline" onClick={() => applyQuick(key)} disabled={loading}>
                                {label}
                            </Button>
                        ))}
                    </div>
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5">
                        <div className="grid gap-1">
                            <Label htmlFor="intel-from">De</Label>
                            <Input
                                id="intel-from"
                                type="date"
                                value={filters.from}
                                onChange={(e) => setFilters({ ...filters, from: e.target.value })}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="intel-to">Até</Label>
                            <Input id="intel-to" type="date" value={filters.to} onChange={(e) => setFilters({ ...filters, to: e.target.value })} />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="intel-stalled">OS parada após (dias)</Label>
                            <Input
                                id="intel-stalled"
                                type="number"
                                min={1}
                                max={365}
                                value={filters.stalled_days}
                                onChange={(e) => setFilters({ ...filters, stalled_days: Number(e.target.value) || 1 })}
                            />
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="intel-expiring">Orçamento vencendo em (dias)</Label>
                            <Input
                                id="intel-expiring"
                                type="number"
                                min={0}
                                max={90}
                                value={filters.expiring_days}
                                onChange={(e) => setFilters({ ...filters, expiring_days: Number(e.target.value) || 0 })}
                            />
                        </div>
                        <div className="flex items-end">
                            <Button type="submit" className="w-full" disabled={loading || periodInvalid}>
                                {loading ? <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> : null}
                                Aplicar
                            </Button>
                        </div>
                    </div>
                    {periodInvalid && <p className="text-xs text-red-600">Informe um período válido de no máximo {maxDays} dias.</p>}
                    <p className="text-muted-foreground text-xs">
                        Os indicadores de período usam {moment(applied.from).format('DD/MM/YYYY')} a {moment(applied.to).format('DD/MM/YYYY')}; os de
                        situação atual (paradas, atrasadas, orçamentos aguardando) refletem hoje.
                    </p>
                </form>

                {error && (
                    <p role="alert" className="rounded-md border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                        {error}
                    </p>
                )}

                {loading && !data && (
                    <p className="text-muted-foreground flex items-center gap-2 text-sm" aria-live="polite">
                        <Loader2 className="h-4 w-4 animate-spin" aria-hidden="true" /> Carregando indicadores...
                    </p>
                )}

                {data && (
                    <div className={`space-y-8 ${loading ? 'opacity-60' : ''}`} aria-busy={loading}>
                        <Section
                            id="intel-summary"
                            title="Resumo"
                            description="Os números principais; abaixo de cada um, quanto do dado é confiável."
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard
                                    title="OS paradas"
                                    value={String(data.stalled_orders.total)}
                                    context={`sem evolução há mais de ${data.stalled_orders.threshold_days} dias`}
                                    warning={
                                        data.stalled_orders.reference_unknown > 0
                                            ? plural(
                                                  data.stalled_orders.reference_unknown,
                                                  'OS ativa sem histórico para avaliar',
                                                  'OS ativas sem histórico para avaliar',
                                              )
                                            : null
                                    }
                                    href="#intel-stalled"
                                />
                                <MetricCard
                                    title="OS atrasadas (prazo original)"
                                    value={String(data.overdue_orders.past_original)}
                                    context={`${plural(data.overdue_orders.past_original_renegotiated, 'com prazo renegociado', 'com prazo renegociado')} · ${data.overdue_orders.total} no prazo vigente`}
                                    warning={
                                        data.overdue_orders.original_unknown > 0
                                            ? plural(
                                                  data.overdue_orders.original_unknown,
                                                  'OS ativa sem prazo original',
                                                  'OS ativas sem prazo original',
                                              )
                                            : null
                                    }
                                />
                                <MetricCard
                                    title="Orçamentos aguardando"
                                    value={String(data.budgets_awaiting.count)}
                                    context={`${currency(data.budgets_awaiting.amount)} em decisão`}
                                    warning={
                                        data.budgets_awaiting.age_unknown > 0
                                            ? plural(data.budgets_awaiting.age_unknown, 'sem data de envio', 'sem data de envio')
                                            : null
                                    }
                                    href="#intel-budgets"
                                />
                                <MetricCard
                                    title="Orçamentos vencendo"
                                    value={String(data.budgets_expiring.count)}
                                    context={`em até ${data.budgets_expiring.within_days} dias · ${currency(data.budgets_expiring.amount)}`}
                                    href="#intel-budgets"
                                />
                                <MetricCard
                                    title="Conversão de orçamentos"
                                    value={
                                        data.budget_conversion.conversion_rate === null
                                            ? null
                                            : `${data.budget_conversion.conversion_rate.toLocaleString('pt-BR')}%`
                                    }
                                    context={
                                        data.budget_conversion.conversion_rate === null
                                            ? 'nenhum orçamento decidido no período'
                                            : `${data.budget_conversion.approved} aprovados de ${data.budget_conversion.approved + data.budget_conversion.rejected + data.budget_conversion.expired} decididos`
                                    }
                                    warning={
                                        data.budget_conversion.pending > 0
                                            ? plural(
                                                  data.budget_conversion.pending,
                                                  'ainda pendente (fora da taxa)',
                                                  'ainda pendentes (fora da taxa)',
                                              )
                                            : null
                                    }
                                />
                                <MetricCard
                                    title="Cumprimento de prazo"
                                    value={
                                        data.deadline_compliance.on_time_rate === null
                                            ? null
                                            : `${data.deadline_compliance.on_time_rate.toLocaleString('pt-BR')}%`
                                    }
                                    context={`${plural(data.deadline_compliance.on_time + data.deadline_compliance.late, 'OS avaliada', 'OS avaliadas')} contra o prazo original`}
                                    warning={
                                        data.deadline_compliance.original_unknown > 0
                                            ? plural(
                                                  data.deadline_compliance.original_unknown,
                                                  'entrega sem prazo original',
                                                  'entregas sem prazo original',
                                              )
                                            : null
                                    }
                                    href="#intel-deadline"
                                />
                                <MetricCard
                                    title="Margem conhecida"
                                    value={data.profitability.complete === 0 ? null : currency(data.profitability.margin_complete)}
                                    context={`${plural(data.profitability.complete, 'OS completa', 'OS completas')}${data.profitability.margin_rate_complete !== null ? ` · ${data.profitability.margin_rate_complete.toLocaleString('pt-BR')}% da receita` : ''}`}
                                    warning={
                                        data.profitability.incomplete > 0
                                            ? plural(
                                                  data.profitability.incomplete,
                                                  'OS com custos incompletos (fora da soma)',
                                                  'OS com custos incompletos (fora da soma)',
                                              )
                                            : null
                                    }
                                    href="#intel-profit"
                                />
                            </div>
                        </Section>

                        <Section
                            id="intel-quality"
                            title="Qualidade dos dados"
                            description="Registros que não puderam entrar nos cálculos. Eles não são contados como zero."
                        >
                            {quality.length === 0 ? (
                                <p className="text-sm text-emerald-700 dark:text-emerald-400">
                                    Todos os registros do período têm os dados necessários.
                                </p>
                            ) : (
                                <ul className="grid gap-1 text-sm sm:grid-cols-2">
                                    {quality.map(([key, count]) => (
                                        <li key={key} className="flex items-center gap-2">
                                            <Info className="h-4 w-4 shrink-0 text-amber-600" aria-hidden="true" />
                                            <span>
                                                <strong>{count}</strong> {QUALITY_LABELS[key] ?? key}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section
                            id="intel-stalled"
                            title="OS paradas"
                            description="Ordens ativas sem mudança de status acima do limite, das mais antigas para as mais recentes."
                        >
                            {data.stalled_orders.orders.length === 0 ? (
                                <p className="text-muted-foreground text-sm">Nenhuma OS parada acima de {data.stalled_orders.threshold_days} dias.</p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>OS</TableHead>
                                                <TableHead>Cliente</TableHead>
                                                <TableHead>Equipamento</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead className="text-right">Dias parada</TableHead>
                                                <TableHead>Técnico</TableHead>
                                                <TableHead>Referência</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.stalled_orders.orders.map((order) => (
                                                <TableRow key={order.order_id}>
                                                    <TableCell>
                                                        <Link
                                                            href={route('app.orders.show', order.order_id)}
                                                            className="font-medium underline-offset-2 hover:underline"
                                                        >
                                                            #{order.order_number}
                                                        </Link>
                                                    </TableCell>
                                                    <TableCell>{order.customer ?? '—'}</TableCell>
                                                    <TableCell>{order.equipment ?? '—'}</TableCell>
                                                    <TableCell>{orderStatusLabel(order.status)}</TableCell>
                                                    <TableCell className="text-right font-semibold">{order.days}</TableCell>
                                                    <TableCell>
                                                        {order.technician ?? <span className="text-muted-foreground">sem atribuição registrada</span>}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge variant="outline">
                                                            {order.reference === 'order_events' ? 'trilha de eventos' : 'histórico legado'}
                                                        </Badge>
                                                    </TableCell>
                                                </TableRow>
                                            ))}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </Section>

                        <Section
                            id="intel-budgets"
                            title="Orçamentos"
                            description="Calculado por ciclo de orçamento da OS: renegociações não contam como novos orçamentos."
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
                                <MetricCard
                                    title="Aguardando aprovação"
                                    value={currency(data.budgets_awaiting.amount)}
                                    context={`${plural(data.budgets_awaiting.count, 'orçamento', 'orçamentos')} · idade média ${data.budgets_awaiting.average_age_days === null ? '—' : `${data.budgets_awaiting.average_age_days.toLocaleString('pt-BR')} dias`}`}
                                />
                                <MetricCard
                                    title="Tempo médio de aprovação"
                                    value={
                                        data.budget_conversion.approval_time_hours.average === null
                                            ? null
                                            : `${data.budget_conversion.approval_time_hours.average.toLocaleString('pt-BR')} h`
                                    }
                                    context={`mediana ${data.budget_conversion.approval_time_hours.median === null ? '—' : `${data.budget_conversion.approval_time_hours.median.toLocaleString('pt-BR')} h`} · do primeiro envio à aprovação`}
                                />
                                <MetricCard
                                    title="Ciclos no período"
                                    value={String(data.budget_conversion.cycles)}
                                    context={`${data.budget_conversion.approved} aprovados · ${data.budget_conversion.rejected} recusados · ${data.budget_conversion.expired} vencidos · ${data.budget_conversion.pending} pendentes`}
                                    warning={
                                        data.budget_conversion.legacy_excluded > 0
                                            ? plural(
                                                  data.budget_conversion.legacy_excluded,
                                                  'orçamento legado fora da conversão',
                                                  'orçamentos legados fora da conversão',
                                              )
                                            : null
                                    }
                                />
                                <MetricCard
                                    title="Versões por ciclo"
                                    value={
                                        data.budget_conversion.average_versions_per_cycle === null
                                            ? null
                                            : data.budget_conversion.average_versions_per_cycle.toLocaleString('pt-BR')
                                    }
                                    context="média de versões até a decisão"
                                />
                            </div>
                            {data.budgets_expiring.budgets.length > 0 && (
                                <ul className="space-y-1 text-sm">
                                    {data.budgets_expiring.budgets.map((budget) => (
                                        <li key={`${budget.order_id}-${budget.version}`}>
                                            <Link href={route('app.orders.show', budget.order_id)} className="underline-offset-2 hover:underline">
                                                Orçamento v{budget.version}
                                            </Link>{' '}
                                            vence em {moment(budget.valid_until).format('DD/MM/YYYY')} · {currency(budget.quoted_amount)}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </Section>

                        <Section
                            id="intel-productivity"
                            title="Produtividade técnica"
                            description="Conclusões e entregas atribuídas ao técnico responsável no momento do evento. Não é um ranking: compare com o tipo de serviço de cada um."
                        >
                            {data.technician_productivity.technicians.length === 0 &&
                            data.technician_productivity.unattributed.completed + data.technician_productivity.unattributed.delivered === 0 ? (
                                <p className="text-muted-foreground text-sm">Nenhuma conclusão ou entrega registrada no período.</p>
                            ) : (
                                <div className="overflow-x-auto rounded-lg border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Técnico</TableHead>
                                                <TableHead className="text-right">Concluídas</TableHead>
                                                <TableHead className="text-right">Entregues</TableHead>
                                                <TableHead className="text-right">Retornos em garantia</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {data.technician_productivity.technicians.map((technician) => (
                                                <TableRow key={technician.technician_id}>
                                                    <TableCell>{technician.name ?? `Técnico #${technician.technician_id}`}</TableCell>
                                                    <TableCell className="text-right">{technician.completed}</TableCell>
                                                    <TableCell className="text-right">{technician.delivered}</TableCell>
                                                    <TableCell className="text-right">{technician.warranty_returns}</TableCell>
                                                </TableRow>
                                            ))}
                                            {data.technician_productivity.unattributed.completed +
                                                data.technician_productivity.unattributed.delivered >
                                                0 && (
                                                <TableRow>
                                                    <TableCell className="text-amber-700 dark:text-amber-400">
                                                        Sem técnico atribuído no momento
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {data.technician_productivity.unattributed.completed}
                                                    </TableCell>
                                                    <TableCell className="text-right">
                                                        {data.technician_productivity.unattributed.delivered}
                                                    </TableCell>
                                                    <TableCell className="text-muted-foreground text-right">—</TableCell>
                                                </TableRow>
                                            )}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </Section>

                        <Section
                            id="intel-deadline"
                            title="Prazo prometido"
                            description="Avaliado contra o prazo original; a renegociação aparece como contexto e não apaga o atraso."
                        >
                            <div className="grid grid-cols-2 gap-3 lg:grid-cols-5">
                                <MetricCard
                                    title="No prazo"
                                    value={String(data.deadline_compliance.on_time)}
                                    context="entregues até o prazo original"
                                />
                                <MetricCard
                                    title="Atrasadas"
                                    value={String(data.deadline_compliance.late)}
                                    context="entregues após o prazo original"
                                />
                                <MetricCard
                                    title="Atraso médio"
                                    value={
                                        data.deadline_compliance.average_delay_days === null
                                            ? null
                                            : `${data.deadline_compliance.average_delay_days.toLocaleString('pt-BR')} dias`
                                    }
                                    context="entre as atrasadas"
                                />
                                <MetricCard
                                    title="Renegociadas"
                                    value={String(data.deadline_compliance.renegotiated)}
                                    context="tiveram o prazo alterado"
                                />
                                <MetricCard
                                    title="Sem prazo original"
                                    value={String(data.deadline_compliance.original_unknown)}
                                    context="fora da avaliação (legado)"
                                />
                            </div>
                        </Section>

                        <Section
                            id="intel-profit"
                            title="Rentabilidade conhecida"
                            description="Somente OS entregues com todos os custos conhecidos. As incompletas não entram na soma e não têm custo preenchido com zero."
                        >
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <MetricCard
                                    title="Receita (OS completas)"
                                    value={data.profitability.complete === 0 ? null : currency(data.profitability.revenue_complete)}
                                    context={plural(data.profitability.complete, 'OS completa', 'OS completas')}
                                />
                                <MetricCard
                                    title="Custo conhecido"
                                    value={data.profitability.complete === 0 ? null : currency(data.profitability.known_cost_complete)}
                                    context="peças, avulsos, comissão e taxas"
                                />
                                <MetricCard
                                    title="Margem conhecida"
                                    value={data.profitability.complete === 0 ? null : currency(data.profitability.margin_complete)}
                                    context={
                                        data.profitability.margin_rate_complete === null
                                            ? 'sem receita no período'
                                            : `${data.profitability.margin_rate_complete.toLocaleString('pt-BR')}% da receita`
                                    }
                                />
                            </div>
                            {data.profitability.incomplete > 0 && (
                                <div className="rounded-md border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                                    <p className="font-medium">
                                        {plural(
                                            data.profitability.incomplete,
                                            'OS entregue com custos incompletos',
                                            'OS entregues com custos incompletos',
                                        )}
                                        :
                                    </p>
                                    <ul className="mt-1 list-inside list-disc">
                                        {Object.entries(data.profitability.incomplete_by_component).flatMap(([component, statuses]) =>
                                            Object.entries(statuses).map(([status, count]) => (
                                                <li key={`${component}-${status}`}>
                                                    {plural(count, 'OS', 'OS')} com {COMPONENT_LABELS[component] ?? component}{' '}
                                                    {STATUS_LABELS[status] ?? status}
                                                </li>
                                            )),
                                        )}
                                    </ul>
                                </div>
                            )}
                        </Section>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
