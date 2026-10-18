import { Icon } from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { CircleCheckBig, ClipboardList, Clock3, Star, UserPlus, Wrench } from 'lucide-react';
import { useMemo, useState } from 'react';
import FollowUpTabs from './follow-up-tabs';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Central de pendências', href: route('app.follow-ups.tasks') },
];

export default function FollowUpTasks({ filters, summary, dailyAgenda, technicians }: any) {
    const { auth } = usePage<{ auth?: { user?: { id?: number }; role?: string; permissions?: string[] } }>().props;
    const canManageOrders = Boolean(auth?.role !== 'technician' && auth?.permissions?.includes('orders'));
    const currentUserId = auth?.user?.id;
    const [selectedTasks, setSelectedTasks] = useState<string[]>([]);
    const selectedCount = selectedTasks.length;

    const applyFilters = (next: Record<string, string>) => {
        router.get(
            route('app.follow-ups.tasks'),
            {
                type: next.type ?? filters?.type ?? 'all',
                priority: next.priority ?? filters?.priority ?? 'all',
                sort: next.sort ?? filters?.sort ?? 'priority',
                assigned_to: next.assigned_to ?? filters?.assigned_to ?? 'all',
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    };

    const handleCompleteTask = (orderId: number, scope: 'budget' | 'payment' | 'feedback') => {
        const reason = window.prompt('Informe o motivo da conclusão desta tarefa:');

        if (!reason || !reason.trim()) {
            return;
        }

        router.post(
            route('app.follow-ups.complete-task', orderId),
            {
                scope,
                reason: reason.trim(),
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handleSnoozeTask = (orderId: number, scope: 'budget' | 'payment') => {
        const choice = window.prompt('Adiar por quantos dias? (1 a 30)', '2');
        const days = choice ? Number(choice) : NaN;

        if (!Number.isInteger(days) || days < 1 || days > 30) {
            return;
        }

        router.post(
            route('app.follow-ups.snooze-task', orderId),
            { scope, days },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handleAssignTask = (orderId: number, scope: 'budget' | 'payment' | 'feedback', userId: string) => {
        router.post(
            route('app.follow-ups.assign-task', orderId),
            {
                scope,
                user_id: userId === 'unassigned' ? null : Number(userId),
            },
            {
                preserveScroll: true,
                preserveState: true,
            },
        );
    };

    const handleClaimTask = (orderId: number, scope: 'budget' | 'payment' | 'feedback') => {
        if (!currentUserId) {
            return;
        }

        handleAssignTask(orderId, scope, String(currentUserId));
    };

    const taskKey = (item: any) => `${item.id}:${item.scope}`;

    const selectedTaskPayload = useMemo(
        () => dailyAgenda.filter((item: any) => selectedTasks.includes(taskKey(item))).map((item: any) => ({ order_id: item.id, scope: item.scope })),
        [dailyAgenda, selectedTasks],
    );

    const toggleTask = (item: any, checked: boolean) => {
        const key = taskKey(item);
        setSelectedTasks((current) => (checked ? [...new Set([...current, key])] : current.filter((value) => value !== key)));
    };

    const toggleAll = (checked: boolean) => {
        setSelectedTasks(checked ? dailyAgenda.map((item: any) => taskKey(item)) : []);
    };

    const handleBulkAssignToMe = () => {
        if (!currentUserId || selectedTaskPayload.length === 0) {
            return;
        }

        router.post(
            route('app.follow-ups.assign-selected'),
            {
                user_id: currentUserId,
                tasks: selectedTaskPayload,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => setSelectedTasks([]),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Central de pendências" />

            <div className="flex min-h-20 items-center justify-between gap-4 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ClipboardList} className="h-8 w-8" />
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">Central de pendências</h2>
                        <p className="text-muted-foreground text-sm">Fila ativa de contatos, cobranças e tratativas que precisam de ação agora.</p>
                    </div>
                </div>
            </div>

            <FollowUpTabs />

            <div className="space-y-4 p-4">
                <div className="rounded-md border border-blue-200 bg-blue-50 p-3 text-sm text-blue-950 dark:border-blue-500/30 dark:bg-blue-950/20 dark:text-blue-100">
                    Ao concluir uma tarefa ou enviar contato, ela sai da fila pelo prazo configurado. Ela volta somente se o orçamento, pagamento ou
                    retorno continuar pendente depois desse período. Em feedback, concluir resolve a tratativa.
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Filtros operacionais</CardTitle>
                    </CardHeader>
                    <CardContent className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                        <div className="space-y-2">
                            <span className="text-sm font-medium">Tipo</span>
                            <Select value={filters?.type ?? 'all'} onValueChange={(value) => applyFilters({ type: value })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    <SelectItem value="budget">Orçamento</SelectItem>
                                    <SelectItem value="payment">Cobrança</SelectItem>
                                    <SelectItem value="feedback">Cliente insatisfeito</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <span className="text-sm font-medium">Prioridade</span>
                            <Select value={filters?.priority ?? 'all'} onValueChange={(value) => applyFilters({ priority: value })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Todas" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todas</SelectItem>
                                    <SelectItem value="critica">Somente críticas</SelectItem>
                                    <SelectItem value="alta">Somente altas</SelectItem>
                                    <SelectItem value="normal">Somente normais</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <span className="text-sm font-medium">Responsável pelo contato</span>
                            <Select value={filters?.assigned_to ?? 'all'} onValueChange={(value) => applyFilters({ assigned_to: value })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Todos" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">Todos</SelectItem>
                                    <SelectItem value="unassigned">Sem responsável pelo contato</SelectItem>
                                    {technicians?.map((technician: any) => (
                                        <SelectItem key={`filter-${technician.id}`} value={String(technician.id)}>
                                            {technician.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <span className="text-sm font-medium">Ordenação</span>
                            <Select value={filters?.sort ?? 'priority'} onValueChange={(value) => applyFilters({ sort: value })}>
                                <SelectTrigger>
                                    <SelectValue placeholder="Prioridade" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="priority">Prioridade e dias</SelectItem>
                                    <SelectItem value="days">Mais tempo sem retorno</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </CardContent>
                </Card>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Pendências abertas</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.today_tasks ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Orçamento</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.budget_tasks ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Cobrança</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.payment_tasks ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Clientes insatisfeitos</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.feedback_tasks ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Sem responsável</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.unassigned_tasks ?? 0}</CardContent>
                    </Card>
                    <Card>
                        <CardHeader>
                            <CardTitle className="text-base">Críticas</CardTitle>
                        </CardHeader>
                        <CardContent className="text-3xl font-bold">{summary?.critical_tasks ?? 0}</CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                            <CardTitle className="text-base">Lista do que precisa ser feito</CardTitle>
                            {canManageOrders && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={handleBulkAssignToMe}
                                    disabled={selectedCount === 0 || !currentUserId}
                                >
                                    <UserPlus className="h-4 w-4" />
                                    Assumir selecionadas{selectedCount > 0 ? ` (${selectedCount})` : ''}
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent>
                        {!dailyAgenda?.length ? (
                            <p className="text-muted-foreground text-sm">Nenhum contato ou tratativa prioritária no momento.</p>
                        ) : (
                            <div className="max-h-[620px] overflow-auto rounded-lg border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead className="w-10">
                                                {canManageOrders && (
                                                    <Checkbox
                                                        checked={selectedCount > 0 && selectedCount === dailyAgenda.length}
                                                        onCheckedChange={(checked) => toggleAll(Boolean(checked))}
                                                        aria-label="Selecionar todas as tarefas"
                                                    />
                                                )}
                                            </TableHead>
                                            <TableHead>#</TableHead>
                                            <TableHead>Cliente</TableHead>
                                            <TableHead>Técnico da OS</TableHead>
                                            <TableHead>Responsável pelo contato</TableHead>
                                            <TableHead>Tipo</TableHead>
                                            <TableHead>Dias</TableHead>
                                            <TableHead>Próxima ação</TableHead>
                                            <TableHead className="text-right">Ações</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {dailyAgenda.map((item: any) => (
                                            <TableRow
                                                key={`${item.scope}-${item.id}`}
                                                className={item.assigned_to_id ? undefined : 'bg-amber-50/70 dark:bg-amber-950/10'}
                                            >
                                                <TableCell>
                                                    {canManageOrders && (
                                                        <Checkbox
                                                            checked={selectedTasks.includes(taskKey(item))}
                                                            onCheckedChange={(checked) => toggleTask(item, Boolean(checked))}
                                                            aria-label={`Selecionar tarefa ${item.order_number}`}
                                                        />
                                                    )}
                                                </TableCell>
                                                <TableCell>{item.order_number}</TableCell>
                                                <TableCell>{item.customer}</TableCell>
                                                <TableCell>{item.technician}</TableCell>
                                                <TableCell className="min-w-[190px]">
                                                    {canManageOrders ? (
                                                        <Select
                                                            value={item.assigned_to_id ? String(item.assigned_to_id) : 'unassigned'}
                                                            onValueChange={(value) => handleAssignTask(item.id, item.scope, value)}
                                                        >
                                                            <SelectTrigger className="w-full">
                                                                <SelectValue placeholder="Definir responsável pelo acompanhamento" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="unassigned">Sem responsável pelo contato</SelectItem>
                                                                {technicians?.map((technician: any) => (
                                                                    <SelectItem key={`task-${technician.id}`} value={String(technician.id)}>
                                                                        {technician.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    ) : (
                                                        <span className="text-sm">{item.assigned_to ?? 'Sem responsável pelo contato'}</span>
                                                    )}
                                                </TableCell>
                                                <TableCell className="capitalize">
                                                    <div className="flex items-center gap-2">
                                                        {item.scope === 'feedback' ? <Star className="h-4 w-4 text-rose-500" /> : null}
                                                        <span>{item.type}</span>
                                                        {item.scope === 'feedback' && item.rating ? (
                                                            <Badge variant="destructive">Nota {item.rating}</Badge>
                                                        ) : null}
                                                    </div>
                                                </TableCell>
                                                <TableCell>{item.days_pending}</TableCell>
                                                <TableCell>
                                                    <div className="flex items-center gap-2">
                                                        <span>{item.next_action?.label}</span>
                                                        <Badge
                                                            variant="secondary"
                                                            className={
                                                                item.next_action?.priority === 'critica'
                                                                    ? 'bg-rose-100 text-rose-900 hover:bg-rose-100'
                                                                    : item.next_action?.priority === 'alta'
                                                                      ? 'bg-amber-100 text-amber-900 hover:bg-amber-100'
                                                                      : 'bg-blue-100 text-blue-900 hover:bg-blue-100'
                                                            }
                                                        >
                                                            {item.next_action?.priority === 'critica'
                                                                ? 'Crítica'
                                                                : item.next_action?.priority === 'alta'
                                                                  ? 'Alta'
                                                                  : 'Normal'}
                                                        </Badge>
                                                    </div>
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <div className="flex justify-end gap-2">
                                                        {canManageOrders && (
                                                            <>
                                                                {!item.assigned_to_id && currentUserId && (
                                                                    <Button
                                                                        type="button"
                                                                        size="icon"
                                                                        variant="outline"
                                                                        title="Assumir tarefa"
                                                                        onClick={() => handleClaimTask(item.id, item.scope)}
                                                                    >
                                                                        <UserPlus className="h-4 w-4" />
                                                                    </Button>
                                                                )}
                                                                <Button
                                                                    type="button"
                                                                    size="icon"
                                                                    variant="outline"
                                                                    title="Concluir tarefa do dia"
                                                                    onClick={() => handleCompleteTask(item.id, item.scope)}
                                                                >
                                                                    <CircleCheckBig className="h-4 w-4" />
                                                                </Button>
                                                                {item.scope !== 'feedback' && (
                                                                    <Button
                                                                        type="button"
                                                                        size="icon"
                                                                        variant="outline"
                                                                        title="Adiar tarefa"
                                                                        onClick={() => handleSnoozeTask(item.id, item.scope)}
                                                                    >
                                                                        <Clock3 className="h-4 w-4" />
                                                                    </Button>
                                                                )}
                                                            </>
                                                        )}
                                                        {item.scope === 'feedback' ? (
                                                            <Button asChild size="icon" variant="outline" title="Abrir tratativa">
                                                                <Link href={route('app.quality.index')}>
                                                                    <Star className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                        ) : (
                                                            <Button asChild size="icon" variant="outline" title="Abrir ordem">
                                                                <Link href={route('app.orders.edit', item.id)}>
                                                                    <Wrench className="h-4 w-4" />
                                                                </Link>
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
