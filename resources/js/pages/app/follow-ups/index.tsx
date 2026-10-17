import AppPagination from '@/components/app-pagination';
import { ChartFollowUpTrend } from '@/components/Charts/chart-follow-up-trend';
import { DatePicker } from '@/components/date-picker';
import { Icon } from '@/components/icon';
import { StatusBadge } from '@/components/StatusBadge';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { maskPhone } from '@/Utils/mask';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CheckCircle2, Filter, LinkIcon, Mail, MessageCircle, PauseCircle, PlayCircle, Search, Wrench } from 'lucide-react';
import moment from 'moment';
import { useState, type ReactNode } from 'react';
import FollowUpTabs from './follow-up-tabs';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Retornos ao cliente', href: route('app.follow-ups.index') },
];

function formatDateRange(date?: Date | string) {
    if (!date) return '';

    const d = date instanceof Date ? date : new Date(date);
    return moment(d).format('DD/MM/YYYY');
}

function communicationLabel(communication: any) {
    if (!communication) return 'Sem contato';
    if (communication.action === 'budget_follow_up_sent') return 'Acompanhamento de orçamento';
    return 'Cobrança';
}

function priorityMeta(order: any, type: 'budget' | 'payment') {
    const isPaused = type === 'budget' ? Boolean(order.budget_follow_up_paused) : Boolean(order.payment_follow_up_paused);
    const hasContact = Boolean(order.last_communication?.created_at);
    const responseLabel = type === 'budget' ? order.budget_follow_up_response_label : order.payment_follow_up_response_label;

    if (isPaused) {
        if (responseLabel) {
            return {
                label: responseLabel,
                className: 'bg-emerald-100 text-emerald-900 hover:bg-emerald-100',
            };
        }

        return {
            label: 'Pausado',
            className: 'border-slate-300 text-slate-700',
        };
    }

    if (!hasContact) {
        return {
            label: 'Prioridade alta',
            className: 'bg-amber-100 text-amber-900 hover:bg-amber-100',
        };
    }

    return {
        label: 'Em acompanhamento',
        className: 'bg-blue-100 text-blue-900 hover:bg-blue-100',
    };
}

function agingMeta(days: number) {
    if (days >= 10) {
        return {
            label: 'Crítico',
            className: 'bg-rose-100 text-rose-900 hover:bg-rose-100',
            rowClassName: 'bg-rose-50/60 dark:bg-rose-950/10',
        };
    }

    if (days >= 5) {
        return {
            label: 'Atenção',
            className: 'bg-amber-100 text-amber-900 hover:bg-amber-100',
            rowClassName: 'bg-amber-50/60 dark:bg-amber-950/10',
        };
    }

    return {
        label: 'Dentro da janela',
        className: 'bg-blue-100 text-blue-900 hover:bg-blue-100',
        rowClassName: '',
    };
}

function FollowUpTable({
    title,
    description,
    orders,
    type,
    canManageOrders,
    automaticFollowUpsEnabled,
}: {
    title: string;
    description: ReactNode;
    orders: any;
    type: 'budget' | 'payment';
    canManageOrders: boolean;
    automaticFollowUpsEnabled: boolean;
}) {
    const pauseKey = type === 'budget' ? 'budget_follow_up_paused' : 'payment_follow_up_paused';
    const pauseReasonKey = type === 'budget' ? 'budget_follow_up_pause_reason' : 'payment_follow_up_pause_reason';
    const responseKey = type === 'budget' ? 'budget_follow_up_response_label' : 'payment_follow_up_response_label';
    const [orderToResume, setOrderToResume] = useState<number | null>(null);

    const handleResponse = (orderId: number) => {
        const choice = window.prompt('Informe o retorno do cliente: respondeu, sem_interesse, aguardando_peca ou prometeu_pagar');

        const map: Record<string, string> = {
            respondeu: 'responded',
            responded: 'responded',
            sem_interesse: 'no_interest',
            seminteresse: 'no_interest',
            aguardando_peca: 'waiting_piece',
            aguardandopeca: 'waiting_piece',
            prometeu_pagar: 'promised_payment',
            prometeupagar: 'promised_payment',
        };

        const normalized = choice ? map[choice.trim().toLowerCase()] : null;

        if (!normalized) {
            return;
        }

        router.post(
            route('app.follow-ups.respond', orderId),
            {
                scope: type,
                status: normalized,
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handleAction = (orderId: number) => {
        const routeName = type === 'budget' ? 'app.orders.budget-follow-up' : 'app.orders.payments.reminder';

        router.post(
            route(routeName, orderId),
            {},
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handlePause = (orderId: number) => {
        const reason = window.prompt('Informe o motivo da pausa da automação para esta ordem:');

        if (!reason || !reason.trim()) {
            return;
        }

        router.post(
            route('app.follow-ups.pause', orderId),
            {
                scope: type,
                reason: reason.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handleResume = (orderId: number) => {
        router.post(
            route('app.follow-ups.resume', orderId),
            {
                scope: type,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setOrderToResume(null),
            },
        );
    };

    return (
        <>
            <Card className="h-full">
                <CardHeader>
                    <CardTitle className="text-base">{title}</CardTitle>
                    <p className="text-muted-foreground text-sm">{description}</p>
                </CardHeader>
                <CardContent className="space-y-4">
                    {!orders?.data?.length ? (
                        <p className="text-muted-foreground text-sm">Nenhuma ordem elegível no momento.</p>
                    ) : (
                        <>
                            <div className="h-[420px] overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>#</TableHead>
                                            <TableHead>Cliente</TableHead>
                                            <TableHead>Técnico da OS</TableHead>
                                            <TableHead>Status</TableHead>
                                            {type === 'payment' && <TableHead>Saldo pendente</TableHead>}
                                            <TableHead>Dias sem retorno</TableHead>
                                            <TableHead>Último contato</TableHead>
                                            <TableHead></TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {orders.data.map((order: any) => {
                                            const aging = agingMeta(Number(order.communication_days_pending ?? 0));

                                            return (
                                                <TableRow
                                                    key={`${type}-${order.id}`}
                                                    className={!order[pauseKey] ? aging.rowClassName || undefined : undefined}
                                                >
                                                    <TableCell>{order.order_number}</TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            <span className="font-medium">{order.customer?.name}</span>
                                                            <span className="text-muted-foreground text-xs">
                                                                {maskPhone(order.customer?.phone ?? '')}
                                                            </span>
                                                            <Badge
                                                                variant={order[pauseKey] ? 'outline' : 'secondary'}
                                                                className={`w-fit ${priorityMeta(order, type).className}`}
                                                            >
                                                                {priorityMeta(order, type).label}
                                                            </Badge>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>{order.user?.name ?? 'Não definido'}</TableCell>
                                                    <TableCell>
                                                        <StatusBadge category="ordem" value={order.service_status} />
                                                    </TableCell>
                                                    {type === 'payment' && (
                                                        <TableCell>
                                                            <Badge variant="secondary" className="w-fit bg-rose-100 text-rose-900 hover:bg-rose-100">
                                                                {formatBRL(order.remaining_amount ?? 0)}
                                                            </Badge>
                                                        </TableCell>
                                                    )}
                                                    <TableCell>
                                                        <div className="flex flex-col gap-1">
                                                            <span>{order.communication_days_pending} dia(s)</span>
                                                            <Badge variant="secondary" className={`w-fit ${aging.className}`}>
                                                                {aging.label}
                                                            </Badge>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        {order[pauseKey] ? (
                                                            <div className="flex flex-col gap-1">
                                                                <span className="text-sm">Automação pausada</span>
                                                                <span className="text-muted-foreground text-xs">
                                                                    {order[pauseReasonKey] || 'Motivo não informado'}
                                                                </span>
                                                            </div>
                                                        ) : order.last_communication?.created_at ? (
                                                            <div className="flex flex-col gap-1">
                                                                <span className="text-sm">{communicationLabel(order.last_communication)}</span>
                                                                <span className="text-muted-foreground text-xs">
                                                                    {order.last_communication?.trigger === 'automatic' ? 'automático' : 'manual'} •{' '}
                                                                    {moment(order.last_communication.created_at).fromNow()}
                                                                </span>
                                                            </div>
                                                        ) : (
                                                            <span className="text-muted-foreground text-sm">Sem contato</span>
                                                        )}
                                                    </TableCell>
                                                    <TableCell className="min-w-[280px]">
                                                        <div className="flex flex-wrap justify-end gap-2">
                                                            {canManageOrders && !order[responseKey] && (
                                                                <Button
                                                                    type="button"
                                                                    size="icon"
                                                                    variant="outline"
                                                                    title="Registrar retorno do cliente"
                                                                    aria-label={`Registrar retorno da ordem ${order.order_number}`}
                                                                    onClick={() => handleResponse(order.id)}
                                                                >
                                                                    <CheckCircle2 className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {canManageOrders && automaticFollowUpsEnabled && !order[pauseKey] && (
                                                                <Button
                                                                    type="button"
                                                                    size="icon"
                                                                    variant="outline"
                                                                    title="Pausar automação"
                                                                    aria-label={`Pausar automação da ordem ${order.order_number}`}
                                                                    onClick={() => handlePause(order.id)}
                                                                >
                                                                    <PauseCircle className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {canManageOrders && order[pauseKey] && (
                                                                <Button
                                                                    type="button"
                                                                    size="icon"
                                                                    variant="outline"
                                                                    title="Retomar automação"
                                                                    aria-label={`Retomar automação da ordem ${order.order_number}`}
                                                                    onClick={() => setOrderToResume(order.id)}
                                                                >
                                                                    <PlayCircle className="h-4 w-4" />
                                                                </Button>
                                                            )}
                                                            {canManageOrders && !order[pauseKey] && (
                                                                <Button
                                                                    type="button"
                                                                    size="sm"
                                                                    variant="outline"
                                                                    onClick={() => handleAction(order.id)}
                                                                >
                                                                    <Mail className="h-4 w-4" />
                                                                    {type === 'budget' ? 'Cobrar orçamento' : 'Cobrar saldo'}
                                                                </Button>
                                                            )}
                                                            <Button
                                                                asChild
                                                                size="icon"
                                                                className="!bg-solar-blue-primary hover:!bg-solar-blue-primary/90 !text-white hover:!text-white"
                                                            >
                                                                <a
                                                                    target="_blank"
                                                                    href={route('os.token', order.tracking_token)}
                                                                    title="Link público da ordem"
                                                                    aria-label={`Abrir link público da ordem ${order.order_number}`}
                                                                >
                                                                    <LinkIcon className="h-4 w-4" />
                                                                </a>
                                                            </Button>
                                                            <Button
                                                                asChild
                                                                size="icon"
                                                                className="bg-orange-500 text-white hover:bg-orange-600"
                                                                title="Abrir ordem"
                                                            >
                                                                <Link
                                                                    href={route('app.orders.edit', order.id)}
                                                                    aria-label={`Abrir ordem ${order.order_number}`}
                                                                >
                                                                    <Wrench className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                    </TableBody>
                                </Table>
                            </div>
                            <AppPagination data={orders} />
                        </>
                    )}
                </CardContent>
            </Card>

            <AlertDialog open={orderToResume !== null} onOpenChange={(open) => !open && setOrderToResume(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Reativar automação?</AlertDialogTitle>
                        <AlertDialogDescription>
                            O envio automático de acompanhamento desta ordem voltará a seguir o intervalo configurado.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            onClick={() => {
                                if (orderToResume !== null) handleResume(orderToResume);
                            }}
                        >
                            Reativar
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}

export default function FollowUps({ filters, summary, budgetOrders, paymentOrders, technicians, trends }: any) {
    const { auth } = usePage<{ auth?: { role?: string; permissions?: string[] } }>().props;
    const canManageOrders = Boolean(auth?.role !== 'technician' && auth?.permissions?.includes('orders'));
    const canManageOtherSettings = Boolean(auth?.permissions?.includes('other_settings'));
    const [search, setSearch] = useState(filters?.search ?? '');
    const responseStatus = filters?.response_status ?? 'all';
    const [metricsDateRange, setMetricsDateRange] = useState<any>({
        from: filters?.from,
        to: filters?.to,
    });

    const applyFilters = (next: Record<string, string | undefined>) => {
        router.get(
            route('app.follow-ups.index'),
            {
                search: next.search ?? filters?.search ?? undefined,
                technician_id: next.technician_id ?? filters?.technician_id ?? undefined,
                type: next.type ?? filters?.type ?? 'all',
                response_status: next.response_status ?? filters?.response_status ?? 'all',
                from: next.from ?? filters?.from,
                to: next.to ?? filters?.to,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const handleSearchSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        applyFilters({ search, type: filters?.type, technician_id: filters?.technician_id });
    };

    const onMetricsDateRangeChange = (range: any) => {
        setMetricsDateRange(range);

        if (range?.from && range?.to) {
            applyFilters({
                from: moment(range.from).format('YYYY-MM-DD'),
                to: moment(range.to).format('YYYY-MM-DD'),
                search,
                technician_id: filters?.technician_id,
                type: activeType,
                response_status: filters?.response_status,
            });
        }
    };

    const activeType = filters?.type ?? 'all';
    const metricsPeriodLabel =
        metricsDateRange?.from && metricsDateRange?.to ? `${formatDateRange(metricsDateRange.from)} até ${formatDateRange(metricsDateRange.to)}` : '';

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Retornos ao cliente" />

            <div className="flex min-h-20 items-center justify-between gap-4 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={MessageCircle} className="h-8 w-8" />
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">Retornos ao cliente</h2>
                        <p className="text-muted-foreground text-sm">
                            Lista ativa de clientes que precisam de retorno. Contatos feitos saem da fila pelo prazo configurado.
                        </p>
                    </div>
                </div>
            </div>

            <FollowUpTabs />

            <div className="p-4">
                <div className="mb-4 flex flex-col gap-3 xl:flex-row xl:items-center xl:justify-between">
                    <div className="flex items-center gap-2">
                        <span className="text-muted-foreground text-xs font-medium">Período dos indicadores: {metricsPeriodLabel}</span>
                    </div>
                    <div className="w-full sm:w-auto">
                        <DatePicker mode={'range'} setDate={onMetricsDateRangeChange} date={metricsDateRange} />
                    </div>
                </div>

                <div className="grid gap-4 sm:grid-cols-3">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Orçamentos parados</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.budget_follow_ups ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cobranças pendentes</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.payment_follow_ups ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Dias para entrar na lista</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.threshold_days ?? 0}d</CardContent>
                    </Card>
                </div>

                <div className="mt-4 rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-500/30 dark:bg-blue-950/20 dark:text-blue-100">
                    Pendência ativa é todo orçamento ou cobrança ainda aberto, sem pausa, sem adiamento e sem contato recente nos últimos{' '}
                    {summary?.cooldown_days ?? summary?.threshold_days ?? 0} dia(s). O histórico continua disponível nos resultados dos contatos.
                </div>

                <div className="mt-4 grid gap-4 md:grid-cols-2 xl:grid-cols-5">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Quem deve cuidar destes contatos?</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <p className="text-muted-foreground text-sm">
                                Estes contatos são responsabilidade do atendimento ou financeiro. O técnico aparece na ordem apenas como responsável
                                pelo serviço.
                            </p>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Orçamentos retomados</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-3xl font-bold">{summary?.recovery?.budget?.rate ?? 0}%</div>
                            <div className="text-muted-foreground text-sm">
                                {summary?.recovery?.budget?.recovered ?? 0} aprovado(s) ou movimentado(s) depois de{' '}
                                {summary?.recovery?.budget?.contacted ?? 0} contato(s)
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cobranças resolvidas</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-3xl font-bold">{summary?.recovery?.payment?.rate ?? 0}%</div>
                            <div className="text-muted-foreground text-sm">
                                {summary?.recovery?.payment?.recovered ?? 0} pagamento(s) recebido(s) depois de{' '}
                                {summary?.recovery?.payment?.contacted ?? 0} contato(s)
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Orçamento: manual x automático</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {['manual', 'automatic'].map((trigger) => (
                                <div key={trigger} className="flex items-center justify-between rounded-lg border p-3">
                                    <div className="flex flex-col">
                                        <span className="font-medium">{trigger === 'manual' ? 'Manual' : 'Automático'}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {summary?.trigger_performance?.budget?.[trigger]?.recovered ?? 0} de{' '}
                                            {summary?.trigger_performance?.budget?.[trigger]?.contacted ?? 0}
                                        </span>
                                    </div>
                                    <Badge variant="secondary" className="bg-blue-100 text-blue-900 hover:bg-blue-100">
                                        {summary?.trigger_performance?.budget?.[trigger]?.rate ?? 0}%
                                    </Badge>
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cobrança: manual x automático</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {['manual', 'automatic'].map((trigger) => (
                                <div key={trigger} className="flex items-center justify-between rounded-lg border p-3">
                                    <div className="flex flex-col">
                                        <span className="font-medium">{trigger === 'manual' ? 'Manual' : 'Automático'}</span>
                                        <span className="text-muted-foreground text-xs">
                                            {summary?.trigger_performance?.payment?.[trigger]?.recovered ?? 0} de{' '}
                                            {summary?.trigger_performance?.payment?.[trigger]?.contacted ?? 0}
                                        </span>
                                    </div>
                                    <Badge variant="secondary" className="bg-blue-100 text-blue-900 hover:bg-blue-100">
                                        {summary?.trigger_performance?.payment?.[trigger]?.rate ?? 0}%
                                    </Badge>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-4 grid gap-4 xl:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Aprovação após contato</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-3xl font-bold">{summary?.commercial?.budget?.rate ?? 0}%</div>
                            <div className="text-muted-foreground text-sm">
                                {summary?.commercial?.budget?.approved ?? 0} aprovados de {summary?.commercial?.budget?.contacted ?? 0} contato(s)
                            </div>
                            <div className="flex flex-wrap gap-2 pt-1">
                                <Badge variant="secondary" className="bg-emerald-100 text-emerald-900 hover:bg-emerald-100">
                                    Aprovados: {summary?.commercial?.budget?.approved ?? 0}
                                </Badge>
                                <Badge variant="secondary" className="bg-rose-100 text-rose-900 hover:bg-rose-100">
                                    Reprovados: {summary?.commercial?.budget?.rejected ?? 0}
                                </Badge>
                                <Badge variant="secondary" className="bg-amber-100 text-amber-900 hover:bg-amber-100">
                                    Pendentes: {summary?.commercial?.budget?.pending ?? 0}
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Pagamento após contato</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2">
                            <div className="text-3xl font-bold">{summary?.commercial?.payment?.rate ?? 0}%</div>
                            <div className="text-muted-foreground text-sm">
                                {summary?.commercial?.payment?.recovered ?? 0} recuperadas de {summary?.commercial?.payment?.contacted ?? 0}{' '}
                                contato(s)
                            </div>
                            <div className="flex flex-wrap gap-2 pt-1">
                                <Badge variant="secondary" className="bg-emerald-100 text-emerald-900 hover:bg-emerald-100">
                                    Recuperadas: {summary?.commercial?.payment?.recovered ?? 0}
                                </Badge>
                                <Badge variant="secondary" className="bg-amber-100 text-amber-900 hover:bg-amber-100">
                                    Em aberto: {summary?.commercial?.payment?.open ?? 0}
                                </Badge>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                <div className="mt-4 grid gap-4 xl:grid-cols-2">
                    <ChartFollowUpTrend
                        title="Evolução da aprovação de orçamento"
                        description="Mostra se os contatos feitos sobre orçamento parado estão ajudando a gerar aprovação."
                        trend={trends?.budget}
                    />
                    <ChartFollowUpTrend
                        title="Evolução de pagamento após cobrança"
                        description="Mostra se os contatos de cobrança estão ajudando a receber saldos pendentes."
                        trend={trends?.payment}
                    />
                </div>

                <div className="mt-4 rounded-lg border p-4">
                    <div className="mb-4 flex items-center gap-2">
                        <Filter className="h-4 w-4" />
                        <span className="text-sm font-medium">Filtros operacionais</span>
                    </div>
                    <div className="grid gap-3 xl:grid-cols-4">
                        <form onSubmit={handleSearchSubmit} className="flex gap-2">
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Buscar por ordem ou cliente"
                                autoComplete="off"
                            />
                            <Button type="submit" size="icon">
                                <Search className="h-4 w-4" />
                            </Button>
                        </form>

                        <div className="space-y-2 xl:space-y-0">
                            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                                <Label className="xl:min-w-14">Tipo</Label>
                                <Select
                                    value={activeType}
                                    onValueChange={(value) =>
                                        applyFilters({
                                            type: value,
                                            search,
                                            technician_id: filters?.technician_id,
                                            response_status: filters?.response_status,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Tipo" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos</SelectItem>
                                        <SelectItem value="budget">Somente orçamento</SelectItem>
                                        <SelectItem value="payment">Somente cobrança</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-2 xl:space-y-0">
                            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                                <Label className="xl:min-w-24">Técnico da OS</Label>
                                <Select
                                    value={filters?.technician_id ? String(filters.technician_id) : 'all'}
                                    onValueChange={(value) =>
                                        applyFilters({
                                            technician_id: value === 'all' ? undefined : value,
                                            search,
                                            type: activeType,
                                            response_status: filters?.response_status,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos</SelectItem>
                                        {technicians?.map((technician: any) => (
                                            <SelectItem key={technician.id} value={String(technician.id)}>
                                                {technician.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>

                        <div className="space-y-2 xl:space-y-0">
                            <div className="flex flex-col gap-2 xl:flex-row xl:items-center">
                                <Label className="xl:min-w-14">Retorno</Label>
                                <Select
                                    value={responseStatus}
                                    onValueChange={(value) =>
                                        applyFilters({
                                            response_status: value,
                                            search,
                                            technician_id: filters?.technician_id,
                                            type: activeType,
                                        })
                                    }
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Todos" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="all">Todos</SelectItem>
                                        <SelectItem value="none">Sem retorno registrado</SelectItem>
                                        <SelectItem value="responded">Cliente respondeu</SelectItem>
                                        <SelectItem value="no_interest">Sem interesse</SelectItem>
                                        <SelectItem value="waiting_piece">Aguardando peça</SelectItem>
                                        <SelectItem value="promised_payment">Prometeu pagar</SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    </div>
                </div>

                <div className="mt-4 space-y-4">
                    {activeType !== 'payment' && (
                        <FollowUpTable
                            title="Orçamento parado"
                            description={
                                <>
                                    Ordens com orçamento gerado e sem retorno dentro da janela configurada ({summary?.cooldown_days ?? 0} dia
                                    {(summary?.cooldown_days ?? 0) === 1 ? '' : 's'}).{' '}
                                    {canManageOtherSettings ? (
                                        <Link
                                            href={`${route('app.other-settings.index')}?tab=operational#intervalo-follow-up-cliente`}
                                            className="text-primary font-medium underline-offset-4 hover:underline"
                                        >
                                            Alterar janela
                                        </Link>
                                    ) : (
                                        <span>Alteração disponível em Outras configurações para usuários autorizados.</span>
                                    )}{' '}
                                    {!summary?.automatic_follow_ups_enabled && <span>O envio automático está desligado.</span>}
                                </>
                            }
                            orders={budgetOrders}
                            type="budget"
                            canManageOrders={canManageOrders}
                            automaticFollowUpsEnabled={Boolean(summary?.automatic_follow_ups_enabled)}
                        />
                    )}
                    {activeType !== 'budget' && (
                        <FollowUpTable
                            title="Cobrança pendente"
                            description="Ordens com saldo em aberto após a janela configurada para contato."
                            orders={paymentOrders}
                            type="payment"
                            canManageOrders={canManageOrders}
                            automaticFollowUpsEnabled={Boolean(summary?.automatic_follow_ups_enabled)}
                        />
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
