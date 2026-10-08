import { ChartAreaDashboard } from '@/components/Charts/chart-area-dashboard';
import ChartBudgetsStatus from '@/components/Charts/chart-budgets-status';
import ChartFluxoOrders from '@/components/Charts/chart-fluxo-orders';
import { KpiDashboard } from '@/components/kpi-dashboard';
import { SalesProducts } from '@/components/sales-products';
import ScheduleCalendarModal from '@/components/Schedules/ScheduleCalendarModal';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { connectBackend } from '@/Utils/connectApi';
import { Link } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Calendar,
    Check,
    Clock,
    MemoryStickIcon,
    MessageSquareMore,
    ShieldAlert,
    Star,
    UserRoundX,
    Users,
    WalletCards,
    Wrench,
} from 'lucide-react';
import moment from 'moment';
import { useEffect, useState } from 'react';

type OperationStatusKey = 'va' | 'ea' | 'aa' | 'og' | 'oa' | 'ca' | 'cn' | 'fb' | 'wg';
type OperationItem = {
    schedules_number?: string | number;
    order_number?: string | number;
    feedback?: boolean;
};

function formatIsoDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('YYYY-MM-DD');
}

function formatBrDate(date: Date | string) {
    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

export default function OrderDashboard({
    timerange,
    dateRange,
    customRange,
    parts,
    others,
    cashier,
    orders,
    feedbackDelay,
    acount,
    listSchedules,
    auth,
}: any) {
    const [metrics, setMetrics] = useState<any>([]);
    const [selectedOperation, setSelectedOperation] = useState<OperationStatusKey | null>(null);
    const canUsePdv = auth?.permissions?.includes('sales') && auth?.role !== 'technician';
    const canUseFinance = auth?.permissions?.includes('finance') && auth?.role !== 'technician';
    const isCashierOpen = Boolean(cashier?.isOpen);
    const showFinanceShortcut = Boolean(others?.enable_finance && canUseFinance);
    const showPdvShortcut = Boolean(others?.enablesales && canUsePdv);
    const quickShortcutClass =
        'border-input bg-background hover:bg-muted/60 flex h-14 flex-1 cursor-pointer flex-col items-center justify-center gap-1 rounded-xl border p-2 text-xs font-medium whitespace-nowrap shadow-xs transition-colors lg:size-[110px] lg:flex-none';

    useEffect(() => {
        const getOrders = async () => {
            try {
                const query = new URLSearchParams();

                if (customRange && dateRange?.from && dateRange?.to) {
                    query.set('from', formatIsoDate(dateRange.from));
                    query.set('to', formatIsoDate(dateRange.to));
                }

                const url = `metricsSystem/${timerange}${query.toString() ? `?${query.toString()}` : ''}`;
                const response = await connectBackend.get(url);
                setMetrics(response.data);
            } catch (error) {
                console.error('Erro ao carregar dados do gráfico Fluxo de ordens', error);
            }
        };
        getOrders();
    }, [timerange, customRange, dateRange]);

    // Lógica consolidada para o Label do período
    const isTodayRange = Number(timerange) === 1;
    const rangeLabel =
        customRange && dateRange?.from && dateRange?.to
            ? `${formatBrDate(dateRange.from)} a ${formatBrDate(dateRange.to)}`
            : isTodayRange
              ? 'Hoje'
              : `${timerange} dias`;

    const defaultKpiDescription = 'Acessar dados';
    const periodKpiDescription = (value: number) => (
        <span className="flex min-w-0 items-center gap-1.5">
            <span className="truncate">Total período</span>
            <span className="bg-primary/10 text-primary rounded-md px-1.5 py-0.5 font-semibold tabular-nums">{value}</span>
        </span>
    );
    const feedbackDelayLabel = `${Number(feedbackDelay || 7)} ${Number(feedbackDelay || 7) === 1 ? 'dia' : 'dias'}`;
    const operationLinkClass =
        'inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-slate-200 px-4 py-2 text-sm font-medium whitespace-nowrap text-slate-900 shadow-xs transition-all hover:bg-slate-300 dark:bg-slate-700 dark:text-slate-50 dark:hover:bg-slate-600';
    const inProgressLinkClass =
        'inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-sky-100 px-4 py-2 text-sm font-medium whitespace-nowrap text-sky-900 shadow-xs transition-all hover:bg-sky-200 dark:bg-sky-500/25 dark:text-sky-100 dark:hover:bg-sky-500/35';
    const overdueLinkClass =
        'inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-red-100 px-4 py-2 text-sm font-medium whitespace-nowrap text-red-900 shadow-xs transition-all hover:bg-red-200 dark:bg-red-500/25 dark:text-red-100 dark:hover:bg-red-500/35';
    const warrantyLinkClass =
        'inline-flex h-9 shrink-0 items-center justify-center gap-2 rounded-md bg-amber-100 px-4 py-2 text-sm font-medium whitespace-nowrap text-amber-900 shadow-xs transition-all hover:bg-amber-200 dark:bg-amber-500/25 dark:text-amber-100 dark:hover:bg-amber-500/35';
    const operationStatusTriggerClass =
        'group flex min-h-16 min-w-0 items-center justify-between gap-3 rounded-lg border bg-background px-3 py-2.5 text-left transition-all enabled:cursor-pointer enabled:hover:border-primary/40 enabled:hover:bg-primary/5 enabled:hover:text-primary enabled:hover:shadow-sm disabled:cursor-not-allowed disabled:bg-muted/30 disabled:opacity-45';
    const operationStatuses: Array<{
        key: OperationStatusKey;
        title: string;
        description: string;
        items: OperationItem[];
    }> = [
        {
            key: 'va',
            title: 'Agenda aberta',
            description: 'Visitas abertas pelo número do agendamento.',
            items: orders?.agendados ?? [],
        },
        {
            key: 'ea',
            title: 'Em atendimento',
            description: 'Atendimentos iniciados pelo app técnico.',
            items: orders?.em_atendimento ?? [],
        },
        {
            key: 'aa',
            title: 'Atrasados',
            description: 'Visitas abertas ou em atendimento com data anterior a hoje.',
            items: orders?.atrasados ?? [],
        },
        {
            key: 'og',
            title: 'Orçamentos gerados',
            description: 'Orçamentos gerados por número de ordem.',
            items: orders?.gerados ?? [],
        },
        {
            key: 'oa',
            title: 'Orçamentos aprovados',
            description: 'Orçamentos aprovados por número de ordem.',
            items: orders?.aprovados ?? [],
        },
        {
            key: 'ca',
            title: 'Aguardando retirada',
            description: 'Serviços concluídos por número de ordem e clientes avisados.',
            items: orders?.concluidosca ?? [],
        },
        {
            key: 'cn',
            title: 'Serviço concluído',
            description: 'Serviços concluídos por número de ordem e clientes não avisados.',
            items: orders?.concluidoscn ?? [],
        },
        {
            key: 'fb',
            title: `Entregues há ${feedbackDelayLabel}`,
            description: `Serviços no prazo de ${feedbackDelayLabel} para provável feedback.`,
            items: orders?.feedback ?? [],
        },
        {
            key: 'wg',
            title: 'Retorno em garantia',
            description: 'Ordens identificadas como retorno em garantia.',
            items: orders?.garantia ?? [],
        },
    ];
    const operationCount = operationStatuses.reduce((total, status) => total + status.items.length, 0);
    const activeOperation = operationStatuses.find((status) => status.key === selectedOperation);
    const todayPriorities = [
        {
            label: 'Prazo vencido',
            value: Number(acount?.numorde_overdue ?? 0),
            href: route('app.orders.index', { filter: 'overdue' }),
            icon: AlertTriangle,
            urgent: true,
        },
        {
            label: 'Vencendo hoje',
            value: Number(acount?.numorde_due_today ?? 0),
            href: route('app.orders.index', { filter: 'due_48h' }),
            icon: Clock,
        },
        {
            label: 'Vencendo amanhã',
            value: Number(acount?.numorde_due_tomorrow ?? 0),
            href: route('app.orders.index', { filter: 'due_48h' }),
            icon: Clock,
        },
        {
            label: 'Aguardando aprovação',
            value: Number(acount?.numorde_awaiting_approval ?? 0),
            href: route('app.orders.index', { status: 3 }),
            icon: Clock,
        },
        {
            label: 'Orçamento vencido',
            value: Number(acount?.numorde_budget_expired ?? 0),
            href: route('app.orders.index', { status: 3 }),
            icon: AlertTriangle,
            urgent: true,
        },
        {
            label: 'Aguardando retirada',
            value: Number(acount?.numorde_awaiting_pickup ?? 0),
            href: route('app.orders.index', { filter: 'awaiting_pickup' }),
            icon: Check,
        },
        {
            label: 'Sem técnico',
            value: Number(acount?.numorde_unassigned ?? 0),
            href: route('app.orders.index', { filter: 'unassigned' }),
            icon: UserRoundX,
        },
        {
            label: 'Estoque baixo',
            value: Number(acount?.numparts_low_stock ?? 0),
            href: route('app.parts.index', { filter: 'low_stock' }),
            icon: MemoryStickIcon,
        },
    ];
    const todayPriorityCount = todayPriorities.reduce((total, priority) => total + priority.value, 0);

    const renderOperationLink = (status: OperationStatusKey, item: OperationItem, index: number) => {
        if (status === 'va' || status === 'ea' || status === 'aa') {
            const className = status === 'ea' ? inProgressLinkClass : status === 'aa' ? overdueLinkClass : operationLinkClass;

            return (
                <Link
                    key={`${status}-${item.schedules_number ?? index}`}
                    href={route('app.schedules.index', { search: item.schedules_number, init: true })}
                    className={className}
                >
                    {status === 'ea' && <Clock className="h-4 w-4" />}
                    {status === 'aa' && <AlertTriangle className="h-4 w-4" />}
                    {item.schedules_number}
                </Link>
            );
        }

        if (status === 'wg') {
            return (
                <Link
                    key={`${status}-${item.order_number ?? index}`}
                    href={route('app.orders.index', {
                        filter: 'warranty_return',
                        search: item.order_number,
                    })}
                    className={warrantyLinkClass}
                >
                    {item.order_number}
                </Link>
            );
        }

        return (
            <Link
                key={`${status}-${item.order_number ?? index}`}
                href={route('app.orders.index', {
                    search: item.order_number,
                    init: true,
                    ...(status === 'fb' ? { fd: 1 } : {}),
                })}
                className={`${operationLinkClass} ${status === 'fb' ? 'relative' : ''}`}
            >
                {status === 'fb' && item.feedback && <Check className="absolute -top-1 -right-1 h-4 w-4" />}
                {item.order_number}
            </Link>
        );
    };

    return (
        <div className="min-w-0">
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-2 lg:flex-row lg:items-stretch">
                    <Card className="min-w-0 flex-1 gap-0 overflow-hidden py-0">
                        <div className="flex flex-col gap-3 px-4 py-3 lg:flex-row lg:items-center">
                            <div className="flex min-w-[180px] items-center gap-3">
                                <div className="bg-primary/10 text-primary flex h-9 w-9 shrink-0 items-center justify-center rounded-lg">
                                    <Activity className="h-4 w-4" />
                                </div>
                                <div>
                                    <div className="text-sm font-semibold">Prioridades de hoje</div>
                                    <div className="text-muted-foreground text-xs">
                                        {todayPriorityCount > 0 ? `${todayPriorityCount} itens pedem atenção` : 'Operação em dia'}
                                    </div>
                                </div>
                            </div>
                            <div className="grid flex-1 grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                                {todayPriorities.map((priority) => {
                                    const PriorityIcon = priority.icon;

                                    return (
                                        <Link
                                            key={priority.label}
                                            href={priority.href}
                                            className={`hover:bg-muted/60 flex min-w-0 items-center justify-between gap-2 rounded-md border px-3 py-2 text-sm transition-colors ${
                                                priority.urgent && priority.value > 0
                                                    ? 'border-red-200 bg-red-50/70 dark:border-red-900/70 dark:bg-red-950/30'
                                                    : ''
                                            }`}
                                        >
                                            <span className="flex min-w-0 items-center gap-2">
                                                <PriorityIcon
                                                    className={`h-4 w-4 shrink-0 ${
                                                        priority.urgent && priority.value > 0
                                                            ? 'text-red-600 dark:text-red-400'
                                                            : 'text-muted-foreground'
                                                    }`}
                                                />
                                                <span className="truncate">{priority.label}</span>
                                            </span>
                                            <span className="font-semibold tabular-nums">{priority.value}</span>
                                        </Link>
                                    );
                                })}
                            </div>
                        </div>
                    </Card>
                    <nav aria-label="Atalhos rápidos" className="flex gap-2 lg:shrink-0">
                        {showFinanceShortcut && (
                            <Tooltip>
                                <TooltipTrigger asChild>
                                    <Link
                                        href={route('app.cashier.index')}
                                        aria-label={isCashierOpen ? 'Caixa aberto' : 'Caixa fechado'}
                                        className={`${quickShortcutClass} ${
                                            isCashierOpen
                                                ? ''
                                                : 'border-red-200 bg-red-50 text-red-700 hover:bg-red-100 dark:border-red-900/70 dark:bg-red-950/30 dark:text-red-400'
                                        }`}
                                    >
                                        <WalletCards
                                            className={`h-5 w-5 shrink-0 ${isCashierOpen ? 'text-emerald-600 dark:text-emerald-400' : ''}`}
                                        />
                                        <span>Caixa</span>
                                    </Link>
                                </TooltipTrigger>
                                <TooltipContent>{isCashierOpen ? 'Caixa aberto' : 'Caixa fechado — clique para abrir'}</TooltipContent>
                            </Tooltip>
                        )}
                        {showPdvShortcut && <SalesProducts parts={parts} iconSize={20} triggerLabel="PDV" triggerClassName={quickShortcutClass} />}
                        <ScheduleCalendarModal
                            schedules={listSchedules}
                            iconSize={20}
                            triggerLabel="Calendário"
                            triggerClassName={quickShortcutClass}
                        />
                    </nav>
                </div>
                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                    <KpiDashboard
                        link={route('app.schedules.index')}
                        title="Agenda"
                        value={acount?.numshed ?? 0}
                        icon={<Calendar className="h-10 w-10" />}
                        description={
                            <span className="flex min-w-0 flex-wrap items-center gap-1">
                                <span className="bg-primary/10 text-primary rounded-md px-1.5 py-0.5 font-semibold tabular-nums">
                                    Abertos {acount?.numshed_open ?? 0}
                                </span>
                                <span className="rounded-md bg-sky-100 px-1.5 py-0.5 font-semibold text-sky-700 tabular-nums dark:bg-sky-500/20 dark:text-sky-200">
                                    Em atendimento {acount?.numshed_in_progress ?? 0}
                                </span>
                                <span className="rounded-md bg-red-100 px-1.5 py-0.5 font-semibold text-red-700 tabular-nums dark:bg-red-500/20 dark:text-red-200">
                                    Atrasados {acount?.numshed_overdue ?? 0}
                                </span>
                            </span>
                        }
                    />
                    <KpiDashboard
                        link={route('app.orders.index', { filter: 'budget_follow_up' })}
                        title="Orç. parados"
                        value={metrics?.budget_follow_ups ?? 0}
                        icon={<MessageSquareMore className="h-10 w-10" />}
                        description="Cobrar retorno"
                    />
                    <KpiDashboard
                        link={route('app.orders.index', { filter: 'pending_payment_follow_up' })}
                        title="Cobranças"
                        value={metrics?.pending_payment_follow_ups ?? 0}
                        icon={<MessageSquareMore className="h-10 w-10" />}
                        description="Cobrar saldo"
                    />
                    <KpiDashboard
                        link={route('app.orders.index', { filter: 'warranty_return' })}
                        title="Retorno garantia"
                        value={metrics?.warranty_returns ?? 0}
                        icon={<ShieldAlert className="h-10 w-10" />}
                        description={defaultKpiDescription}
                    />
                    <KpiDashboard
                        link={route('app.quality.index')}
                        title="Avaliações"
                        value={metrics?.feedback_responses ?? 0}
                        icon={<Star className="h-10 w-10" />}
                        description={`Nota média ${metrics?.feedback_average_rating ?? 0} • ${metrics?.feedback_response_rate ?? 0}% retorno`}
                    />
                </div>
                <div>
                    <div className="text-muted-foreground mb-2 text-xs font-medium">Totais gerais</div>
                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                        <KpiDashboard
                            link={route('app.customers.index')}
                            title="Clientes"
                            value={acount?.numcust ?? 0}
                            icon={<Users className="h-10 w-10" />}
                            description={periodKpiDescription(metrics?.customers ?? 0)}
                        />
                        <KpiDashboard
                            link={route('app.orders.index')}
                            title="Ordens"
                            value={acount?.numorde ?? 0}
                            icon={<Wrench className="h-10 w-10" />}
                            description={periodKpiDescription(metrics?.orders ?? 0)}
                        />
                        <KpiDashboard
                            link={route('app.messages.index')}
                            title="Mensagens"
                            value={acount?.nummess ?? 0}
                            icon={<MessageSquareMore className="h-10 w-10" />}
                            description={periodKpiDescription(metrics?.messages ?? 0)}
                        />
                        <KpiDashboard
                            link={route('app.parts.index')}
                            title="Peças"
                            value={acount?.numparts ?? 0}
                            icon={<MemoryStickIcon className="h-10 w-10" />}
                            description={periodKpiDescription(metrics?.parts ?? 0)}
                        />
                        <KpiDashboard
                            link={route('app.parts.index')}
                            title="Produtos"
                            value={acount?.numproducts ?? 0}
                            icon={<MemoryStickIcon className="h-10 w-10" />}
                            description={periodKpiDescription(metrics?.products ?? 0)}
                        />
                    </div>
                </div>
            </div>
            <div className="mt-3 grid min-h-[210px] gap-3">
                <div className="h-full min-w-0">
                    <Card className="@container/card h-full flex-1 gap-4 overflow-hidden">
                        <CardHeader className="border-b pb-4">
                            <div className="flex items-start justify-between gap-4">
                                <div className="flex min-w-0 items-start gap-3">
                                    <div className="bg-primary/10 text-primary flex h-10 w-10 shrink-0 items-center justify-center rounded-lg">
                                        <Activity className="h-5 w-5" />
                                    </div>
                                    <div className="min-w-0 space-y-1">
                                        <CardTitle>Status da operação</CardTitle>
                                        <CardDescription>Selecione uma etapa para consultar os atendimentos e ordens relacionados.</CardDescription>
                                    </div>
                                </div>
                                <div className="bg-muted shrink-0 rounded-lg px-3 py-2 text-right">
                                    <div className="text-lg leading-none font-semibold tabular-nums">{operationCount}</div>
                                    <div className="text-muted-foreground mt-1 text-[11px]">registros</div>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <div className="grid w-full grid-cols-2 gap-2 md:grid-cols-3 xl:grid-cols-5">
                                {operationStatuses.map((status) => {
                                    const hasItems = status.items.length > 0;

                                    return (
                                        <button
                                            key={status.key}
                                            type="button"
                                            disabled={!hasItems}
                                            onClick={() => setSelectedOperation(status.key)}
                                            className={operationStatusTriggerClass}
                                            title={hasItems ? `Abrir ${status.title}` : 'Nenhum registro disponível'}
                                        >
                                            <span className="min-w-0 leading-tight">{status.title}</span>
                                            <Badge
                                                variant={hasItems ? 'default' : 'secondary'}
                                                className="group-enabled:group-hover:bg-primary shrink-0 tabular-nums"
                                            >
                                                {status.items.length}
                                            </Badge>
                                        </button>
                                    );
                                })}
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </div>

            <Dialog open={selectedOperation !== null} onOpenChange={(open) => !open && setSelectedOperation(null)}>
                <DialogContent className="max-h-[85vh] sm:max-w-2xl">
                    <DialogHeader className="pr-8">
                        <div className="flex items-center gap-2">
                            <DialogTitle>{activeOperation?.title}</DialogTitle>
                            <Badge variant="secondary" className="tabular-nums">
                                {activeOperation?.items.length ?? 0}
                            </Badge>
                        </div>
                        <DialogDescription>{activeOperation?.description}</DialogDescription>
                    </DialogHeader>

                    <div className="bg-muted/20 max-h-[60vh] overflow-y-auto rounded-lg border p-4">
                        <div className="flex flex-wrap gap-2">
                            {activeOperation?.items.map((item, index) => renderOperationLink(activeOperation.key, item, index))}
                        </div>
                    </div>
                </DialogContent>
            </Dialog>

            <div className="mt-3 grid gap-3 xl:grid-cols-3">
                <div>
                    <ChartAreaDashboard timerange={timerange} dateRange={dateRange} customRange={customRange} />
                </div>
                <div>
                    <ChartBudgetsStatus timerange={timerange} dateRange={dateRange} customRange={customRange} />
                </div>
                <div>
                    <ChartFluxoOrders timerange={timerange} dateRange={dateRange} customRange={customRange} />
                </div>
            </div>
        </div>
    );
}
