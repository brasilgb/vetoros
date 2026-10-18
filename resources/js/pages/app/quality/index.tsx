import { toastError } from '@/components/app-toast-messages';
import { ChartQualityTrend } from '@/components/Charts/chart-quality-trend';
import { DatePicker } from '@/components/date-picker';
import { Icon } from '@/components/icon';
import { SlaTooltip } from '@/components/sla-tooltip';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { usePersistedPeriodFilter } from '@/hooks/use-persisted-period-filter';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { connectBackend } from '@/Utils/connectApi';
import { Head, Link, router } from '@inertiajs/react';
import { isAxiosError } from 'axios';
import { AlertTriangle, ChevronLeft, ChevronRight, ExternalLink, ShieldAlert, ShieldCheck, ShieldEllipsis, Star } from 'lucide-react';
import moment from 'moment';
import { useEffect, useState } from 'react';
import type { DateRange } from 'react-day-picker';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Garantias e avaliações',
        href: route('app.quality.index'),
    },
];

type RankingItem = {
    label: string;
    total: number;
};

type FeedbackRecoveryItem = {
    id: number;
    order_number: number;
    customer: string;
    rating: number;
    comment?: string;
    submitted_at?: string;
    recovery_status?: string;
    recovery_notes?: string;
    recovery_updated_at?: string;
    recovery_assigned_to?: string;
    recovery_assigned_to_id?: number | null;
    recovery_overdue?: boolean;
};

function formatDateRange(date?: Date | string) {
    if (!date) return '';

    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

function severityConfig(severity: string) {
    if (severity === 'Critico') {
        return {
            label: 'Crítico',
            cardClass: 'border-amber-300 bg-amber-50',
            textClass: 'text-amber-900',
            icon: AlertTriangle,
        };
    }

    if (severity === 'Atencao') {
        return {
            label: 'Atenção',
            cardClass: 'border-blue-200 bg-blue-50',
            textClass: 'text-blue-900',
            icon: ShieldEllipsis,
        };
    }

    return {
        label: 'Saudável',
        cardClass: 'border-emerald-200 bg-emerald-50',
        textClass: 'text-emerald-900',
        icon: ShieldCheck,
    };
}

function RankingCard({ title, items, emptyText }: { title: string; items?: RankingItem[]; emptyText: string }) {
    return (
        <Card className="h-full">
            <CardHeader>
                <CardTitle className="text-base">{title}</CardTitle>
            </CardHeader>
            <CardContent>
                {items && items.length > 0 ? (
                    <div className="space-y-3">
                        {items.map((item) => (
                            <div key={item.label} className="flex items-center justify-between gap-3 border-b pb-2 last:border-b-0 last:pb-0">
                                <span className="text-sm">{item.label}</span>
                                <Badge variant="secondary">{item.total}</Badge>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">{emptyText}</p>
                )}
            </CardContent>
        </Card>
    );
}

function FeedbackRecoveryCard({
    items,
    assignees,
    onSave,
    currentUserId,
}: {
    items?: FeedbackRecoveryItem[];
    assignees?: Array<{ id: number; name: string }>;
    onSave: (payload: { orderId: number; assigned_to: string; status: string; notes: string }) => void;
    currentUserId?: number | null;
}) {
    const [forms, setForms] = useState<Record<number, { assigned_to: string; status: string; notes: string }>>({});

    const getForm = (item: FeedbackRecoveryItem) =>
        forms[item.id] ?? {
            assigned_to: item.recovery_assigned_to_id ? String(item.recovery_assigned_to_id) : '',
            status: item.recovery_status ?? 'pending',
            notes: item.recovery_notes ?? '',
        };

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Clientes com nota baixa</CardTitle>
            </CardHeader>
            <CardContent>
                {items && items.length > 0 ? (
                    <div className="space-y-3">
                        {items.map((item) => {
                            const form = getForm(item);

                            return (
                                <div
                                    key={item.id}
                                    className={`rounded-lg border p-3 ${item.recovery_overdue ? 'border-rose-300 bg-rose-50/50' : ''}`}
                                >
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <div className="font-medium">
                                                OS #{item.order_number} • {item.customer}
                                            </div>
                                            <div className="text-muted-foreground text-xs">
                                                {item.submitted_at ? moment(item.submitted_at).format('DD/MM/YYYY HH:mm') : '-'}
                                            </div>
                                        </div>
                                        <div className="flex items-center gap-2">
                                            {item.recovery_overdue ? (
                                                <Badge variant="secondary" className="bg-white text-rose-900">
                                                    <SlaTooltip>SLA vencido</SlaTooltip>
                                                </Badge>
                                            ) : null}
                                            <Badge variant="destructive">Nota {item.rating}</Badge>
                                        </div>
                                    </div>
                                    {item.comment ? <p className="mt-2 text-sm text-slate-600">{item.comment}</p> : null}
                                    <div className="mt-3 grid gap-3 md:grid-cols-3">
                                        <div>
                                            <div className="mb-1 text-xs font-medium text-slate-500">Responsável</div>
                                            <select
                                                value={form.assigned_to}
                                                onChange={(e) =>
                                                    setForms((prev) => ({
                                                        ...prev,
                                                        [item.id]: { ...form, assigned_to: e.target.value },
                                                    }))
                                                }
                                                className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                                            >
                                                <option value="">Sem responsável</option>
                                                {assignees?.map((assignee) => (
                                                    <option key={assignee.id} value={assignee.id}>
                                                        {assignee.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                        <div>
                                            <div className="mb-1 text-xs font-medium text-slate-500">Status da tratativa</div>
                                            <select
                                                value={form.status}
                                                onChange={(e) =>
                                                    setForms((prev) => ({
                                                        ...prev,
                                                        [item.id]: { ...form, status: e.target.value },
                                                    }))
                                                }
                                                className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                                            >
                                                <option value="pending">Pendente</option>
                                                <option value="in_progress">Em tratativa</option>
                                                <option value="resolved">Resolvido</option>
                                            </select>
                                        </div>
                                        <div className="flex items-end">
                                            <div className="flex w-full gap-2">
                                                {!form.assigned_to && currentUserId ? (
                                                    <Button
                                                        type="button"
                                                        variant="outline"
                                                        className="flex-1"
                                                        onClick={() =>
                                                            setForms((prev) => ({
                                                                ...prev,
                                                                [item.id]: { ...form, assigned_to: String(currentUserId), status: 'in_progress' },
                                                            }))
                                                        }
                                                    >
                                                        Assumir
                                                    </Button>
                                                ) : null}
                                                <Button
                                                    type="button"
                                                    className="flex-1"
                                                    onClick={() =>
                                                        onSave({
                                                            orderId: item.id,
                                                            assigned_to: form.assigned_to,
                                                            status: form.status,
                                                            notes: form.notes,
                                                        })
                                                    }
                                                >
                                                    Salvar tratativa
                                                </Button>
                                            </div>
                                        </div>
                                    </div>
                                    <div className="mt-3">
                                        <div className="mb-1 text-xs font-medium text-slate-500">Observação da tratativa</div>
                                        <Textarea
                                            value={form.notes}
                                            onChange={(e) =>
                                                setForms((prev) => ({
                                                    ...prev,
                                                    [item.id]: { ...form, notes: e.target.value },
                                                }))
                                            }
                                            rows={3}
                                            className="w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm"
                                            placeholder="Registre como a equipe tratou essa insatisfação."
                                        />
                                    </div>
                                    {item.recovery_updated_at ? (
                                        <div className="mt-2 text-xs text-slate-500">
                                            Última atualização: {moment(item.recovery_updated_at).format('DD/MM/YYYY HH:mm')}
                                            {item.recovery_assigned_to ? ` • ${item.recovery_assigned_to}` : ''}
                                        </div>
                                    ) : null}
                                </div>
                            );
                        })}
                    </div>
                ) : (
                    <p className="text-muted-foreground text-sm">Nenhuma avaliação crítica no período.</p>
                )}
            </CardContent>
        </Card>
    );
}

type CustomerFeedbackItem = {
    id: number;
    order_number: number;
    customer: string;
    technician?: string | null;
    rating: number;
    comment?: string | null;
    submitted_at?: string | null;
    requires_recovery?: boolean;
    recovery_status?: string | null;
};

type CustomerFeedbackPage = {
    data: CustomerFeedbackItem[];
    current_page: number;
    last_page: number;
    per_page: number;
    from?: number | null;
    to?: number | null;
    total: number;
};

type WarrantyReturnItem = {
    id: number;
    order_number: number;
    source_order_number?: number | null;
    customer: string;
    equipment: string;
    technician: string;
    defect?: string | null;
    status: string;
    created_at?: string | null;
};

type WarrantyReturnPage = Omit<CustomerFeedbackPage, 'data'> & { data: WarrantyReturnItem[] };

type QualityMetrics = {
    summary?: {
        severity?: string;
        warranty_return_rate?: number;
        warranty_return_threshold?: number;
        total_orders?: number;
        warranty_returns?: number;
        open_warranty_returns?: number;
        affected_customers?: number;
        avg_days_to_return?: number;
        recovery_pending?: number;
        recovery_in_progress?: number;
        recovery_resolved?: number;
        recovery_overdue?: number;
        recovery_sla_days?: number;
        feedback_responses?: number;
        feedback_average_rating?: number;
        feedback_response_rate?: number;
        low_feedbacks?: number;
        unassigned_low_feedbacks?: number;
    };
    comparison?: {
        direction?: string;
        previous_warranty_return_rate?: number;
        delta_rate?: number;
    };
    top_equipments?: RankingItem[];
    top_defects?: RankingItem[];
    top_technicians?: RankingItem[];
    status_breakdown?: RankingItem[];
    trend?: {
        granularity?: string;
        data?: Array<{ label: string; rate: number; returns: number; total_orders: number }>;
    };
    feedback_orders?: CustomerFeedbackPage;
    low_feedback_orders?: FeedbackRecoveryItem[];
    warranty_orders?: WarrantyReturnPage;
};

function WarrantyReturnsTable({ page, onPageChange }: { page?: WarrantyReturnPage; onPageChange: (page: number) => void }) {
    const items = page?.data ?? [];

    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-base">Ordens que retornaram em garantia</CardTitle>
                <p className="text-muted-foreground mt-1 text-sm">Relação das OS identificadas no período e suas respectivas ordens de origem.</p>
            </CardHeader>
            <CardContent>
                {items.length ? (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Entrada</TableHead>
                                    <TableHead>OS retorno</TableHead>
                                    <TableHead>OS origem</TableHead>
                                    <TableHead>Cliente / equipamento</TableHead>
                                    <TableHead>Técnico</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead className="text-right">Ação</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>{item.created_at ? moment(item.created_at).format('DD/MM/YYYY') : '-'}</TableCell>
                                        <TableCell className="font-medium">#{item.order_number}</TableCell>
                                        <TableCell>{item.source_order_number ? `#${item.source_order_number}` : '-'}</TableCell>
                                        <TableCell>
                                            <div>{item.customer}</div>
                                            <div className="text-muted-foreground text-xs">{item.equipment}</div>
                                        </TableCell>
                                        <TableCell>{item.technician}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline">{item.status}</Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild size="sm" variant="outline">
                                                <Link href={route('app.orders.edit', item.id)}>Abrir OS</Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                        <div className="mt-4 flex items-center justify-between text-sm">
                            <span className="text-muted-foreground">{page?.total ?? 0} retorno(s)</span>
                            <div className="flex gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={(page?.current_page ?? 1) <= 1}
                                    onClick={() => onPageChange((page?.current_page ?? 1) - 1)}
                                >
                                    <ChevronLeft className="h-4 w-4" /> Anterior
                                </Button>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={(page?.current_page ?? 1) >= (page?.last_page ?? 1)}
                                    onClick={() => onPageChange((page?.current_page ?? 1) + 1)}
                                >
                                    Próxima <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </>
                ) : (
                    <p className="text-muted-foreground text-sm">Nenhum retorno em garantia no período.</p>
                )}
            </CardContent>
        </Card>
    );
}

function CustomerFeedbackTable({
    page,
    onPageChange,
    onPerPageChange,
}: {
    page?: CustomerFeedbackPage;
    onPageChange: (page: number) => void;
    onPerPageChange: (perPage: number) => void;
}) {
    const items = page?.data ?? [];

    return (
        <Card>
            <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                    <CardTitle className="text-base">Avaliações recebidas</CardTitle>
                    <p className="text-muted-foreground mt-1 text-sm">Notas e comentários enviados pelos clientes no período selecionado.</p>
                </div>
                <label className="text-muted-foreground flex items-center gap-2 text-xs">
                    Por página
                    <select
                        value={page?.per_page ?? 20}
                        onChange={(event) => onPerPageChange(Number(event.target.value))}
                        className="border-input bg-background rounded-md border px-2 py-1.5 text-sm"
                    >
                        <option value={20}>20</option>
                        <option value={50}>50</option>
                        <option value={100}>100</option>
                    </select>
                </label>
            </CardHeader>
            <CardContent>
                {items && items.length > 0 ? (
                    <>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Data</TableHead>
                                    <TableHead>OS</TableHead>
                                    <TableHead>Cliente</TableHead>
                                    <TableHead>Técnico</TableHead>
                                    <TableHead>Nota</TableHead>
                                    <TableHead className="min-w-[280px]">Comentário</TableHead>
                                    <TableHead className="text-right">Ação</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {items.map((item) => (
                                    <TableRow key={item.id}>
                                        <TableCell>{item.submitted_at ? moment(item.submitted_at).format('DD/MM/YYYY HH:mm') : '-'}</TableCell>
                                        <TableCell className="font-medium">#{item.order_number}</TableCell>
                                        <TableCell>{item.customer}</TableCell>
                                        <TableCell>{item.technician || '-'}</TableCell>
                                        <TableCell>
                                            <div className="flex items-center gap-2">
                                                <div className="flex items-center gap-0.5" aria-label={`Nota ${item.rating} de 5`}>
                                                    {[1, 2, 3, 4, 5].map((rating) => (
                                                        <Star
                                                            key={rating}
                                                            className={`h-3.5 w-3.5 ${
                                                                rating <= item.rating ? 'fill-amber-400 text-amber-400' : 'text-muted-foreground/30'
                                                            }`}
                                                        />
                                                    ))}
                                                </div>
                                                <Badge variant={item.requires_recovery ? 'destructive' : 'secondary'}>{item.rating}</Badge>
                                            </div>
                                        </TableCell>
                                        <TableCell className="max-w-[420px] whitespace-normal">
                                            {item.comment?.trim() || <span className="text-muted-foreground">Sem comentário</span>}
                                            {item.requires_recovery ? (
                                                <div className="mt-1">
                                                    <Badge variant="outline">
                                                        Tratativa:{' '}
                                                        {item.recovery_status === 'resolved'
                                                            ? 'resolvida'
                                                            : item.recovery_status === 'in_progress'
                                                              ? 'em andamento'
                                                              : 'pendente'}
                                                    </Badge>
                                                </div>
                                            ) : null}
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button asChild size="icon" variant="outline" title={`Abrir OS ${item.order_number}`}>
                                                <Link href={route('app.orders.index', { search: item.order_number, init: true })}>
                                                    <ExternalLink className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>

                        <div className="mt-4 flex flex-col gap-3 border-t pt-4 sm:flex-row sm:items-center sm:justify-between">
                            <div className="text-muted-foreground text-sm">
                                Exibindo {page?.from ?? 0}–{page?.to ?? 0} de {page?.total ?? 0} avaliações
                            </div>
                            <div className="flex items-center gap-2">
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!page || page.current_page <= 1}
                                    onClick={() => onPageChange(Math.max(1, (page?.current_page ?? 1) - 1))}
                                >
                                    <ChevronLeft className="h-4 w-4" />
                                    Anterior
                                </Button>
                                <span className="text-muted-foreground px-2 text-sm">
                                    Página {page?.current_page ?? 1} de {page?.last_page ?? 1}
                                </span>
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!page || page.current_page >= page.last_page}
                                    onClick={() => onPageChange(Math.min(page?.last_page ?? 1, (page?.current_page ?? 1) + 1))}
                                >
                                    Próxima
                                    <ChevronRight className="h-4 w-4" />
                                </Button>
                            </div>
                        </div>
                    </>
                ) : (
                    <p className="text-muted-foreground text-sm">Nenhuma avaliação recebida no período selecionado.</p>
                )}
            </CardContent>
        </Card>
    );
}

export default function QualityIndicators({
    recoveryAssignees,
    auth,
}: {
    recoveryAssignees?: Array<{ id: number; name: string }>;
    auth?: { id?: number };
}) {
    const { timeRange, dateRange, setTimeRange, setDateRange, clearDateRange } = usePersistedPeriodFilter('quality-period-filter');
    const [metrics, setMetrics] = useState<QualityMetrics | null>(null);
    const [recoveryStatusFilter, setRecoveryStatusFilter] = useState('all');
    const [assignedToFilter, setAssignedToFilter] = useState('all');
    const [feedbackPage, setFeedbackPage] = useState(1);
    const [feedbackPerPage, setFeedbackPerPage] = useState(20);
    const [warrantyPage, setWarrantyPage] = useState(1);

    const customRangeFrom = dateRange.from;
    const customRangeTo = dateRange.to;
    const hasCustomRange = Boolean(customRangeFrom && customRangeTo);
    const timerangeForRequests =
        customRangeFrom && customRangeTo
            ? Math.max(1, Math.ceil((new Date(customRangeTo).getTime() - new Date(customRangeFrom).getTime()) / (1000 * 60 * 60 * 24)) + 1)
            : Number(timeRange);

    const timerangeLabel = hasCustomRange
        ? `${formatDateRange(customRangeFrom)} até ${formatDateRange(customRangeTo)}`
        : timeRange === '1'
          ? 'Hoje'
          : `${timeRange} dias`;

    useEffect(() => {
        const loadMetrics = async () => {
            const query = new URLSearchParams();

            if (hasCustomRange && dateRange?.from && dateRange?.to) {
                query.set('from', moment(dateRange.from).format('YYYY-MM-DD'));
                query.set('to', moment(dateRange.to).format('YYYY-MM-DD'));
            }

            if (recoveryStatusFilter !== 'all') {
                query.set('recovery_status', recoveryStatusFilter);
            }

            if (assignedToFilter !== 'all') {
                query.set('assigned_to', assignedToFilter);
            }

            query.set('feedback_page', String(feedbackPage));
            query.set('feedback_per_page', String(feedbackPerPage));
            query.set('warranty_page', String(warrantyPage));

            const url = `quality-indicators/metrics/${timerangeForRequests}${query.toString() ? `?${query.toString()}` : ''}`;

            try {
                const response = await connectBackend.get(url);
                setMetrics(response.data);
            } catch (error: unknown) {
                const message = isAxiosError<{ message?: string }>(error) ? error.response?.data?.message : undefined;
                toastError('Não foi possível carregar os indicadores', message ?? 'Tente novamente em instantes.');
            }
        };

        void loadMetrics();
    }, [
        timeRange,
        dateRange,
        hasCustomRange,
        timerangeForRequests,
        recoveryStatusFilter,
        assignedToFilter,
        feedbackPage,
        feedbackPerPage,
        warrantyPage,
    ]);

    const onTimeRangeChange = (value: string) => {
        if (!value) return;

        setTimeRange(value);
        setFeedbackPage(1);
        setWarrantyPage(1);
        if (value !== 'custom') {
            clearDateRange();
        }
    };

    const onDateRangeChange = (range: Date | DateRange | undefined) => {
        const selectedRange: DateRange = range && !(range instanceof Date) ? range : { from: undefined, to: undefined };

        setDateRange(selectedRange);
        setFeedbackPage(1);
        setWarrantyPage(1);

        if (selectedRange.from && selectedRange.to) {
            setTimeRange('custom');
        } else if (timeRange === 'custom') {
            setTimeRange('7');
        }
    };

    const summary = metrics?.summary;
    const comparison = metrics?.comparison;
    const severity = severityConfig(summary?.severity ?? 'Saudavel');
    const SeverityIcon = severity.icon;
    const comparisonLabel = comparison?.direction === 'melhorou' ? 'Melhorou' : comparison?.direction === 'piorou' ? 'Piorou' : 'Estável';
    const comparisonTextClass =
        comparison?.direction === 'melhorou' ? 'text-emerald-800' : comparison?.direction === 'piorou' ? 'text-amber-900' : 'text-slate-700';

    const saveRecovery = ({ orderId, assigned_to, status, notes }: { orderId: number; assigned_to: string; status: string; notes: string }) => {
        router.post(
            route('app.quality.feedback-recovery', orderId),
            {
                assigned_to: assigned_to ? Number(assigned_to) : null,
                status,
                notes,
            },
            {
                preserveScroll: true,
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Garantias e avaliações" />

            <div className="flex min-h-20 items-center justify-between gap-4 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ShieldAlert} className="h-8 w-8" />
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">Garantias e avaliações</h2>
                        <p className="text-muted-foreground text-sm">
                            Acompanhe retornos em garantia e clientes que deram nota baixa para organizar a tratativa.
                        </p>
                    </div>
                </div>
            </div>

            <div className="p-4">
                <div className="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground text-xs font-medium">Período analisado: {timerangeLabel}</span>
                    </div>
                    <div className="w-full sm:w-auto">
                        <DatePicker mode={'range'} setDate={onDateRangeChange} date={dateRange} />
                    </div>
                    <div className="flex min-w-0 flex-col gap-2 xl:items-end">
                        <ToggleGroup
                            type="single"
                            value={timeRange}
                            onValueChange={onTimeRangeChange}
                            className="bg-muted flex w-full justify-start gap-2 overflow-x-auto rounded-lg p-1 sm:w-auto"
                        >
                            <ToggleGroupItem value="1" className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">
                                Hoje
                            </ToggleGroupItem>

                            <ToggleGroupItem value="7" className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">
                                7 dias
                            </ToggleGroupItem>

                            <ToggleGroupItem value="30" className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">
                                30 dias
                            </ToggleGroupItem>

                            <ToggleGroupItem value="60" className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">
                                60 dias
                            </ToggleGroupItem>

                            <ToggleGroupItem value="custom" className="data-[state=on]:bg-primary data-[state=on]:text-primary-foreground">
                                Intervalo
                            </ToggleGroupItem>
                        </ToggleGroup>
                    </div>
                </div>

                <Tabs defaultValue="warranty" className="mt-4">
                    <TabsList>
                        <TabsTrigger value="warranty">Retornos em garantia</TabsTrigger>
                        <TabsTrigger value="feedback">Avaliações de clientes</TabsTrigger>
                    </TabsList>

                    <TabsContent value="warranty" className="space-y-4">
                        <Card className={severity.cardClass}>
                            <CardHeader className="flex flex-row items-start justify-between gap-3">
                                <div>
                                    <CardTitle className={`text-base ${severity.textClass}`}>Panorama da garantia</CardTitle>
                                    <p className={`mt-2 text-sm ${severity.textClass}`}>
                                        Situação {severity.label}. Taxa atual de retorno em garantia: {summary?.warranty_return_rate ?? 0}%, com
                                        limite configurado de {summary?.warranty_return_threshold ?? 0}%.
                                    </p>
                                    <p className={`mt-2 text-sm font-medium ${comparisonTextClass}`}>
                                        {comparisonLabel} em relação ao período anterior: {comparison?.previous_warranty_return_rate ?? 0}% antes,{' '}
                                        variação de {comparison?.delta_rate ?? 0} p.p.
                                    </p>
                                </div>
                                <SeverityIcon className={`h-6 w-6 shrink-0 ${severity.textClass}`} />
                            </CardHeader>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Resumo de garantia</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Ordens analisadas</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.total_orders ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Retornos</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.warranty_returns ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Em aberto</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.open_warranty_returns ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Clientes afetados</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.affected_customers ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Média até retorno</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.avg_days_to_return ?? 0}d</CardContent>
                                </Card>
                            </CardContent>
                        </Card>

                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Fila de tratativas com clientes</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Pendentes</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.recovery_pending ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Em tratativa</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.recovery_in_progress ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Resolvidas</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.recovery_resolved ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            <SlaTooltip>Fora do SLA</SlaTooltip>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.recovery_overdue ?? 0}</CardContent>
                                </Card>
                            </CardContent>
                        </Card>

                        {Boolean(summary?.recovery_overdue) && (
                            <Card className="border-rose-300 bg-rose-50">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-sm text-rose-900">Tratativas críticas fora do prazo</CardTitle>
                                </CardHeader>
                                <CardContent className="pt-0 text-sm text-rose-900">
                                    Existem {summary?.recovery_overdue ?? 0} caso(s) de insatisfação sem resolução dentro do{' '}
                                    <SlaTooltip>SLA</SlaTooltip> de {summary?.recovery_sla_days ?? 3} dias.
                                </CardContent>
                            </Card>
                        )}

                        <div className="grid gap-4 xl:grid-cols-3">
                            <RankingCard
                                title="Equipamentos com mais reincidência"
                                items={metrics?.top_equipments}
                                emptyText="Nenhum retorno em garantia no período."
                            />
                            <RankingCard
                                title="Defeitos com mais reincidência"
                                items={metrics?.top_defects}
                                emptyText="Nenhum defeito recorrente em garantia no período."
                            />
                            <RankingCard
                                title="Técnicos com mais reincidência"
                                items={metrics?.top_technicians}
                                emptyText="Nenhum técnico associado a retorno no período."
                            />
                        </div>

                        <WarrantyReturnsTable page={metrics?.warranty_orders} onPageChange={setWarrantyPage} />

                        <ChartQualityTrend trend={metrics?.trend} threshold={summary?.warranty_return_threshold} />

                        <RankingCard
                            title="Situação atual dos retornos em garantia"
                            items={metrics?.status_breakdown?.map((item) => ({ label: item.label, total: item.total }))}
                            emptyText="Nenhum retorno em garantia no período."
                        />
                    </TabsContent>

                    <TabsContent value="feedback" className="space-y-4">
                        <Card>
                            <CardHeader className="pb-3">
                                <CardTitle className="text-base">Percepção do cliente</CardTitle>
                            </CardHeader>
                            <CardContent className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Feedbacks recebidos</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.feedback_responses ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Nota média</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.feedback_average_rating ?? 0}</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Taxa de resposta</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.feedback_response_rate ?? 0}%</CardContent>
                                </Card>
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Críticas totais</CardTitle>
                                    </CardHeader>
                                    <CardContent className="text-3xl font-bold">{summary?.low_feedbacks ?? 0}</CardContent>
                                </Card>
                            </CardContent>
                        </Card>

                        <CustomerFeedbackTable
                            page={metrics?.feedback_orders}
                            onPageChange={setFeedbackPage}
                            onPerPageChange={(perPage) => {
                                setFeedbackPerPage(perPage);
                                setFeedbackPage(1);
                            }}
                        />

                        {Boolean(summary?.unassigned_low_feedbacks) && (
                            <Card className="border-amber-300 bg-amber-50">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-sm text-amber-900">Existem casos de insatisfação sem responsável</CardTitle>
                                </CardHeader>
                                <CardContent className="pt-0 text-sm text-amber-900">
                                    Há {summary?.unassigned_low_feedbacks ?? 0} avaliação(ões) críticas aguardando definição de responsável.
                                </CardContent>
                            </Card>
                        )}

                        <section className="space-y-4">
                            <Card className="border-border bg-card">
                                <CardHeader className="pb-3">
                                    <CardTitle className="text-base">Tratativa das avaliações baixas</CardTitle>
                                    <p className="text-muted-foreground text-sm">
                                        Use os filtros abaixo para organizar a fila de clientes insatisfeitos e tratar os casos pendentes.
                                    </p>
                                </CardHeader>
                                <CardContent className="space-y-4">
                                    <div className="grid gap-4 md:max-w-3xl md:grid-cols-2">
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs font-medium">Status</div>
                                            <select
                                                value={recoveryStatusFilter}
                                                onChange={(e) => setRecoveryStatusFilter(e.target.value)}
                                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                <option value="all">Todos</option>
                                                <option value="pending">Pendente</option>
                                                <option value="in_progress">Em tratativa</option>
                                                <option value="resolved">Resolvido</option>
                                            </select>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground mb-1 text-xs font-medium">Responsável</div>
                                            <select
                                                value={assignedToFilter}
                                                onChange={(e) => setAssignedToFilter(e.target.value)}
                                                className="border-input bg-background ring-offset-background placeholder:text-muted-foreground focus-visible:ring-ring w-full rounded-md border px-3 py-2 text-sm focus-visible:ring-2 focus-visible:outline-none"
                                            >
                                                <option value="all">Todos</option>
                                                <option value="unassigned">Sem responsável</option>
                                                {recoveryAssignees?.map((assignee) => (
                                                    <option key={assignee.id} value={assignee.id}>
                                                        {assignee.name}
                                                    </option>
                                                ))}
                                            </select>
                                        </div>
                                    </div>

                                    <FeedbackRecoveryCard
                                        items={metrics?.low_feedback_orders}
                                        assignees={recoveryAssignees}
                                        onSave={saveRecovery}
                                        currentUserId={auth?.id ?? null}
                                    />
                                </CardContent>
                            </Card>
                        </section>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
