import { Icon } from '@/components/icon';
import AppLayout from '@/layouts/app-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Calendar, Camera, ClipboardCheck, CreditCard, Edit, Eye, FileText, Loader2, MapPin, Plus, Smartphone, Timer, X } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import InputSearch from '@/components/inputSearch';
import ScheduleCalendarModal from '@/components/Schedules/ScheduleCalendarModal';
import SelectFilter from '@/components/SelectFilter';
import { StatusBadge } from '@/components/StatusBadge';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { MoneyInput } from '@/components/ui/money-input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { statusAgenda } from '@/Utils/dataSelect';
import { isValidWhatsappPhone } from '@/Utils/mask';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Agendamentos',
        href: '#',
    },
];

function formatMaterialChecklist(materials: any): string {
    if (!Array.isArray(materials)) return '';

    return materials
        .map((item: any) => {
            if (typeof item === 'string') return item;

            const name = String(item?.name || item?.label || item?.item || '').trim();
            const quantity = Math.max(1, parseInt(String(item?.quantity ?? 1), 10) || 1);

            return name ? `${quantity}x ${name}` : '';
        })
        .filter(Boolean)
        .join(', ');
}

const DEFAULT_TECHNICIAN_SCHEDULE_TEMPLATE =
    '{{ saudacao }}, {{ tecnico }}!\nVisita agendada para {{ data_visita }}.\nServiço: {{ servico }}.\nMateriais: {{ materiais }}.\nCliente: {{ cliente }}.\nEndereço: {{ endereco }}.';

function normalizePlaceholderKey(key: string) {
    return key.trim().toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/\s+/g, '_').replace(/-/g, '_');
}

function applyTemplate(template: string, values: Record<string, string>) {
    return template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, rawKey) => values[normalizePlaceholderKey(rawKey)] ?? '');
}

function getTechnicianWhatsappMessage(schedule: any, template?: string) {
    const visitDate = moment(schedule.schedules).format('DD/MM/YYYY HH:mm');
    const greetingHour = new Date().getHours();
    const greeting = greetingHour < 12 ? 'Bom dia' : greetingHour < 18 ? 'Boa tarde' : 'Boa noite';

    const addressParts = [
        schedule.customer?.street,
        schedule.customer?.number,
        schedule.customer?.complement,
        schedule.customer?.district,
        schedule.customer?.city,
    ].filter(Boolean);

    const materialChecklist = formatMaterialChecklist(schedule.material_checklist);

    const values = {
        saudacao: greeting,
        tecnico: schedule.user?.name ?? '',
        data_visita: visitDate,
        servico: schedule.service || 'ainda não informado',
        materiais: materialChecklist || 'nenhum material informado',
        cliente: schedule.customer?.name ?? '',
        endereco: addressParts.join(', '),
    };

    return applyTemplate(template || DEFAULT_TECHNICIAN_SCHEDULE_TEMPLATE, values)
        .replace(/\n{2,}/g, '\n')
        .trim();
}

function getMobileStage(schedule: any) {
    const summary = schedule.mobile_summary;

    if (!summary?.sent_to_technician) {
        return { label: 'Não enviado', className: 'border-muted-foreground/30 text-muted-foreground' };
    }

    if (summary.has_check_out) {
        return { label: 'Finalizado no app', className: 'border-emerald-200 bg-emerald-50 text-emerald-700' };
    }

    if (summary.has_check_in) {
        return { label: 'Em atendimento', className: 'border-sky-200 bg-sky-50 text-sky-700' };
    }

    return { label: 'Enviado ao técnico', className: 'border-amber-200 bg-amber-50 text-amber-700' };
}

function getScheduleShowHref(schedule: any, page?: number, search?: string, hash = '') {
    return `${route('app.schedules.show', { schedule: schedule.id, page, search })}${hash}`;
}

function TechnicianMobileSummary({ schedule, scheduleHref }: { schedule: any; scheduleHref: string }) {
    const summary = schedule.mobile_summary;
    const stage = getMobileStage(schedule);

    return (
        <div className="flex min-w-[190px] flex-wrap gap-1.5">
            <Badge variant="outline" className={stage.className}>
                <Smartphone className="h-3 w-3" />
                {stage.label}
            </Badge>
            {summary?.has_report && (
                <Badge asChild variant="outline" className="border-indigo-200 bg-indigo-50 text-indigo-700">
                    <Link href={scheduleHref}>
                        <FileText className="h-3 w-3" />
                        Relatório
                    </Link>
                </Badge>
            )}
            {summary?.has_checklist && (
                <Badge
                    variant="outline"
                    className={
                        summary.checklist_completed
                            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                            : 'border-amber-200 bg-amber-50 text-amber-700'
                    }
                >
                    <ClipboardCheck className="h-3 w-3" />
                    Checklist
                </Badge>
            )}
            {summary?.images_count > 0 && (
                <Badge asChild variant="outline" className="border-slate-200 bg-slate-50 text-slate-700">
                    <Link href={scheduleHref}>
                        <Camera className="h-3 w-3" />
                        {summary.images_count} foto{summary.images_count > 1 ? 's' : ''}
                    </Link>
                </Badge>
            )}
            {summary?.local_payment_received && (
                <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                    <CreditCard className="h-3 w-3" />
                    Pagamento
                </Badge>
            )}
        </div>
    );
}

function TechnicianMobileTable({ schedules, pagination, canManageSchedules }: { schedules: any[]; pagination: any; canManageSchedules: boolean }) {
    const [selectedSchedule, setSelectedSchedule] = useState<any | null>(null);
    const cashierForm = useForm({});
    const priceForm = useForm({ amount: '' });
    const selectedSummary = selectedSchedule?.mobile_summary;
    const selectedStage = selectedSchedule ? getMobileStage(selectedSchedule) : null;
    const selectedReportHref = selectedSchedule ? getScheduleShowHref(selectedSchedule, undefined, undefined, '#technician-report') : '#';
    const selectedImages = selectedSummary?.images || selectedSchedule?.images || [];

    if (!schedules?.length) {
        return (
            <div className="text-muted-foreground rounded-lg border p-6 text-center text-sm">
                Não há atendimentos técnicos para acompanhar no momento.
            </div>
        );
    }

    return (
        <>
            <div className="rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>#</TableHead>
                            <TableHead>Cliente</TableHead>
                            <TableHead>Visita</TableHead>
                            <TableHead>Status app</TableHead>
                            <TableHead className="w-[110px] text-right">Detalhes</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {schedules.map((schedule: any) => {
                            const stage = getMobileStage(schedule);

                            return (
                                <TableRow key={schedule.id}>
                                    <TableCell>{schedule.schedules_number}</TableCell>
                                    <TableCell>
                                        <div className="max-w-[260px] truncate font-medium">{schedule.customer?.name}</div>
                                    </TableCell>
                                    <TableCell>
                                        <div className="flex items-center gap-1.5 text-sm whitespace-nowrap">
                                            <Timer className="text-muted-foreground h-3.5 w-3.5" />
                                            {moment(schedule.schedules).format('DD/MM/YYYY HH:mm')}
                                        </div>
                                    </TableCell>
                                    <TableCell>
                                        <Badge variant="outline" className={stage.className}>
                                            {stage.label}
                                        </Badge>
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button variant="outline" size="sm" onClick={() => setSelectedSchedule(schedule)}>
                                            <Eye className="h-4 w-4" />
                                            Ver
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            );
                        })}
                    </TableBody>
                    <TableFooter>
                        <TableRow>
                            <TableCell colSpan={5}>
                                <AppPagination data={pagination} />
                            </TableCell>
                        </TableRow>
                    </TableFooter>
                </Table>
            </div>

            <Dialog open={Boolean(selectedSchedule)} onOpenChange={(open) => !open && setSelectedSchedule(null)}>
                <DialogContent className="sm:max-w-3xl">
                    {selectedSchedule && selectedStage && (
                        <>
                            <DialogHeader>
                                <DialogTitle>Agendamento #{selectedSchedule.schedules_number}</DialogTitle>
                                <DialogDescription>{selectedSchedule.customer?.name}</DialogDescription>
                            </DialogHeader>

                            <div className="space-y-4">
                                <div className="flex flex-wrap items-start justify-between gap-3">
                                    <div className="space-y-1">
                                        <div className="font-medium">{selectedSchedule.service}</div>
                                        <div className="text-muted-foreground text-sm">Técnico: {selectedSchedule.user?.name}</div>
                                        <div className="text-muted-foreground flex items-center gap-1.5 text-sm">
                                            <Timer className="h-3.5 w-3.5" />
                                            {moment(selectedSchedule.schedules).format('DD/MM/YYYY HH:mm')}
                                        </div>
                                    </div>
                                    <Badge variant="outline" className={selectedStage.className}>
                                        {selectedStage.label}
                                    </Badge>
                                </div>

                                <div className="grid grid-cols-2 gap-2 text-xs md:grid-cols-4">
                                    <div className="rounded-md border p-3">
                                        <div className="text-muted-foreground">Check-in</div>
                                        <div className="mt-1 font-medium">{selectedSummary?.has_check_in ? 'Realizado' : 'Pendente'}</div>
                                    </div>
                                    <div className="rounded-md border p-3">
                                        <div className="text-muted-foreground">Check-out</div>
                                        <div className="mt-1 font-medium">{selectedSummary?.has_check_out ? 'Realizado' : 'Pendente'}</div>
                                    </div>
                                    <div className="rounded-md border p-3">
                                        <div className="text-muted-foreground">Relatório</div>
                                        <Link href={selectedReportHref} className="text-primary mt-1 block font-medium hover:underline">
                                            {selectedSummary?.has_report ? 'Preenchido' : 'Pendente'}
                                        </Link>
                                    </div>
                                    <div className="rounded-md border p-3">
                                        <div className="text-muted-foreground">Fotos</div>
                                        <Link href={selectedReportHref} className="text-primary mt-1 block font-medium hover:underline">
                                            {selectedSummary?.images_count || 0}
                                        </Link>
                                    </div>
                                </div>

                                {selectedImages.length > 0 && (
                                    <div className="grid gap-2 sm:grid-cols-2 md:grid-cols-4">
                                        {selectedImages.map((image: any) => (
                                            <a
                                                key={image.id}
                                                href={image.url}
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                className="group bg-muted block overflow-hidden rounded-md border"
                                            >
                                                <img
                                                    src={image.url}
                                                    alt={`Foto do agendamento ${selectedSchedule.schedules_number}`}
                                                    className="aspect-square w-full object-cover transition-transform group-hover:scale-105"
                                                />
                                            </a>
                                        ))}
                                    </div>
                                )}

                                <div className="flex flex-wrap gap-1.5">
                                    {selectedSummary?.has_checklist && (
                                        <Badge
                                            variant="outline"
                                            className={
                                                selectedSummary.checklist_completed
                                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                                    : 'border-amber-200 bg-amber-50 text-amber-700'
                                            }
                                        >
                                            <ClipboardCheck className="h-3 w-3" />
                                            {selectedSummary.checklist_completed ? 'Checklist concluído' : 'Checklist pendente'}
                                        </Badge>
                                    )}
                                    {selectedSummary?.local_payment_received && (
                                        <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                                            <CreditCard className="h-3 w-3" />
                                            Pagamento local{' '}
                                            {formatBRL(selectedSummary?.local_payment_amount ?? selectedSchedule?.local_payment_amount)}
                                        </Badge>
                                    )}
                                    {selectedSummary?.service_closure_status === 'requested' && (
                                        <Badge
                                            variant="outline"
                                            className="border-amber-200 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/50 dark:text-amber-200"
                                        >
                                            <CreditCard className="h-3 w-3" />
                                            Aguardando definição do valor
                                        </Badge>
                                    )}
                                    {selectedSummary?.service_closure_status === 'priced' && (
                                        <Badge
                                            variant="outline"
                                            className="border-sky-200 bg-sky-50 text-sky-800 dark:border-sky-800 dark:bg-sky-950/50 dark:text-sky-200"
                                        >
                                            <CreditCard className="h-3 w-3" />
                                            Valor liberado: {formatBRL(selectedSummary.service_closure_amount)}
                                        </Badge>
                                    )}
                                    {selectedSummary?.local_payment_registered_in_cashier && (
                                        <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                                            <CreditCard className="h-3 w-3" />
                                            Inserido no caixa
                                        </Badge>
                                    )}
                                    {selectedSummary?.sent_to_technician && (
                                        <Badge variant="outline" className="border-slate-200 bg-slate-50 text-slate-700">
                                            <MapPin className="h-3 w-3" />
                                            Rota pelo app
                                        </Badge>
                                    )}
                                </div>

                                <div className="flex justify-end">
                                    {canManageSchedules ? (
                                        <div className="flex flex-wrap justify-end gap-2">
                                            {selectedSummary?.service_closure_status === 'requested' && (
                                                <div className="flex w-full flex-col gap-3 rounded-md border border-amber-200 bg-amber-50 p-3 text-amber-950 sm:w-auto dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-100">
                                                    <div>
                                                        <div className="text-sm font-semibold">Definição do valor</div>
                                                        <div className="text-xs text-amber-800 dark:text-amber-300">
                                                            Informe o total que será liberado ao técnico.
                                                        </div>
                                                    </div>
                                                    <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
                                                        <MoneyInput
                                                            value={priceForm.data.amount}
                                                            onValueChange={(value) => priceForm.setData('amount', value)}
                                                            placeholder="0,00"
                                                            aria-label="Valor do atendimento"
                                                            className="border-amber-300 bg-white text-slate-950 placeholder:text-slate-500 focus-visible:border-amber-500 focus-visible:ring-amber-500/30 sm:w-48 dark:border-amber-700 dark:bg-slate-950 dark:text-slate-50 dark:placeholder:text-slate-400"
                                                        />
                                                        <Button
                                                            type="button"
                                                            size="sm"
                                                            disabled={priceForm.processing || !priceForm.data.amount}
                                                            onClick={() =>
                                                                priceForm.patch(route('app.schedules.service-closure-price', selectedSchedule.id), {
                                                                    preserveScroll: true,
                                                                    onSuccess: () => {
                                                                        setSelectedSchedule(null);
                                                                        priceForm.reset();
                                                                    },
                                                                })
                                                            }
                                                        >
                                                            {priceForm.processing ? 'Liberando...' : 'Definir e liberar valor'}
                                                        </Button>
                                                    </div>
                                                </div>
                                            )}
                                            {selectedSummary?.can_register_local_payment_cashier && (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    disabled={cashierForm.processing}
                                                    onClick={() =>
                                                        cashierForm.post(route('app.schedules.local-payment-cashier', selectedSchedule.id), {
                                                            preserveScroll: true,
                                                        })
                                                    }
                                                >
                                                    <CreditCard className="h-4 w-4" />
                                                    {cashierForm.processing ? 'Inserindo...' : 'Inserir no caixa'}
                                                </Button>
                                            )}
                                            <Button asChild variant="outline" size="sm">
                                                <Link href={route('app.schedules.edit', selectedSchedule.id)}>Abrir agendamento</Link>
                                            </Button>
                                        </div>
                                    ) : (
                                        <Button variant="outline" size="sm" disabled>
                                            Acompanhamento visível
                                        </Button>
                                    )}
                                </div>
                            </div>
                        </>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

export default function Schedules({ schedules, search, status, tab, whats }: any) {
    const { auth } = usePage<{ auth?: { role?: string; permissions?: string[] } }>().props;
    const hasActiveFilters = Boolean(search || status);
    const isTechnician = auth?.role === 'technician';
    const canManageSchedules = auth?.role !== 'technician' && auth?.permissions?.includes('schedules');
    const defaultTab = tab === 'technician' || isTechnician ? 'technician' : 'list';
    const [sendingWhatsappId, setSendingWhatsappId] = useState<number | null>(null);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Agendamentos" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Calendar} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Agendamentos</h2>
                </div>
                <p className="text-muted-foreground text-sm">Organize os atendimentos, acompanhe os horários e distribua as atividades da equipe.</p>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Buscar agenda, serviço, cliente, telefone ou técnico" url="app.schedules.index" />
                </div>
                <div className="flex w-full flex-col gap-2 md:flex-row lg:w-auto lg:shrink-0">
                    <SelectFilter dataStatus={statusAgenda} url="app.schedules.index" noOrder />
                    {hasActiveFilters && (
                        <Button variant={'outline'} asChild className="w-full whitespace-nowrap md:w-auto">
                            <Link href={route('app.schedules.index')}>
                                <X className="mr-1 h-4 w-4" />
                                <span>Limpar filtros</span>
                            </Link>
                        </Button>
                    )}
                </div>
                <div className="flex w-full flex-col gap-2 md:flex-row lg:w-auto lg:shrink-0 lg:justify-end">
                    <ScheduleCalendarModal iconSize={38} schedules={schedules?.data || []} />

                    {canManageSchedules && (
                        <Button variant={'default'} asChild className="w-full whitespace-nowrap md:w-auto">
                            <Link href={route('app.schedules.create')}>
                                <Plus className="h-4 w-4" />
                                <span>Novo agendamento</span>
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <div className="p-4">
                <Tabs defaultValue={defaultTab} className="space-y-4">
                    <TabsList className="grid w-full grid-cols-2 md:w-fit md:min-w-[360px]">
                        <TabsTrigger value="list">Lista</TabsTrigger>
                        <TabsTrigger value="technician">App técnico</TabsTrigger>
                    </TabsList>

                    <TabsContent value="list">
                        <PaginationSummary data={schedules} />
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>#</TableHead>
                                        <TableHead>Cliente</TableHead>
                                        <TableHead>Visita</TableHead>
                                        <TableHead>Serviço</TableHead>
                                        <TableHead>Técnico</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead>App técnico</TableHead>
                                        <TableHead className="min-w-[140px]"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {schedules?.data.length > 0 ? (
                                        schedules?.data?.map((schedule: any) => {
                                            const isOverdue =
                                                [1, 2].includes(Number(schedule.status)) && moment(schedule.schedules).isBefore(moment());
                                            const overdueHours = isOverdue ? Math.max(1, moment().diff(moment(schedule.schedules), 'hours')) : 0;
                                            const overdueLabel =
                                                overdueHours >= 24
                                                    ? `Atrasado há ${Math.floor(overdueHours / 24)} ${Math.floor(overdueHours / 24) === 1 ? 'dia' : 'dias'}`
                                                    : `Atrasado há ${overdueHours} ${overdueHours === 1 ? 'hora' : 'horas'}`;

                                            return (
                                                <TableRow key={schedule.id} className={isOverdue ? 'bg-red-50/60 dark:bg-red-950/15' : ''}>
                                                    <TableCell>{schedule.schedules_number}</TableCell>
                                                    <TableCell>
                                                        <div className="space-y-1">
                                                            <div className="font-medium">{schedule.customer.name}</div>
                                                            <div className="text-muted-foreground text-xs">
                                                                Solicitado em {moment(schedule.created_at).format('DD/MM/YYYY')}
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="space-y-1">
                                                            <div className="font-medium">{moment(schedule.schedules).format('DD/MM/YYYY')}</div>
                                                            <div className="text-muted-foreground text-xs">
                                                                {moment(schedule.schedules).format('HH:mm')}
                                                            </div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="max-w-[280px] space-y-1">
                                                            <div className="font-medium">
                                                                {schedule.service || (
                                                                    <span className="text-muted-foreground text-sm font-normal">
                                                                        Serviço não informado
                                                                    </span>
                                                                )}
                                                            </div>
                                                            {formatMaterialChecklist(schedule.material_checklist) && (
                                                                <div className="text-muted-foreground truncate text-xs">
                                                                    Materiais: {formatMaterialChecklist(schedule.material_checklist)}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="space-y-1">
                                                            <div className="font-medium">{schedule.user.name}</div>
                                                            <div className="text-muted-foreground text-xs">Técnico responsável</div>
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="flex flex-col items-start gap-1.5">
                                                            <StatusBadge category="agenda" value={schedule.status} />
                                                            {isOverdue && <Badge variant="destructive">{overdueLabel}</Badge>}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        <TechnicianMobileSummary
                                                            schedule={schedule}
                                                            scheduleHref={getScheduleShowHref(
                                                                schedule,
                                                                schedules.current_page,
                                                                search,
                                                                '#technician-report',
                                                            )}
                                                        />
                                                    </TableCell>
                                                    <TableCell className="min-w-[140px]">
                                                        <div className="flex flex-wrap justify-end gap-2">
                                                            {canManageSchedules && (
                                                                <Button
                                                                    type="button"
                                                                    size="icon"
                                                                    className="bg-green-500 text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-40"
                                                                    title={
                                                                        sendingWhatsappId === schedule.id ? 'Enviando mensagem...' : 'Enviar WhatsApp'
                                                                    }
                                                                    aria-label={`Enviar WhatsApp para ${schedule.user?.name}`}
                                                                    disabled={
                                                                        !isValidWhatsappPhone(schedule.user?.whatsapp) ||
                                                                        sendingWhatsappId === schedule.id
                                                                    }
                                                                    onClick={() =>
                                                                        router.post(
                                                                            route('app.schedules.whatsapp.send', schedule.id),
                                                                            {
                                                                                message: getTechnicianWhatsappMessage(
                                                                                    schedule,
                                                                                    whats?.technicianschedule,
                                                                                ),
                                                                            },
                                                                            {
                                                                                preserveScroll: true,
                                                                                preserveState: true,
                                                                                onStart: () => setSendingWhatsappId(schedule.id),
                                                                                onFinish: () => setSendingWhatsappId(null),
                                                                            },
                                                                        )
                                                                    }
                                                                >
                                                                    {sendingWhatsappId === schedule.id ? (
                                                                        <Loader2 className="h-4 w-4 animate-spin" />
                                                                    ) : (
                                                                        <svg
                                                                            xmlns="http://www.w3.org/2000/svg"
                                                                            width="16"
                                                                            height="16"
                                                                            fill="currentColor"
                                                                            className="bi bi-whatsapp"
                                                                            viewBox="0 0 16 16"
                                                                        >
                                                                            <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.240-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.330.065-.134.034-.248-.015-.347-.050-.099-.445-1.076-.612-1.47-.160-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.710 1.916.810 2.049c.098.133 1.394 2.132 3.383 2.992.470.205.840.326 1.129.418.475.152.904.129 1.246.080.38-.058 1.171-.480 1.338-.943.164-.464.164-.860.114-.943-.049-.084-.182-.133-.38-.232" />
                                                                        </svg>
                                                                    )}
                                                                </Button>
                                                            )}

                                                            {canManageSchedules ? (
                                                                <Button
                                                                    asChild
                                                                    size="icon"
                                                                    className="bg-orange-500 text-white hover:bg-orange-600"
                                                                    title="Editar agendamento"
                                                                >
                                                                    <Link
                                                                        href={route('app.schedules.edit', schedule.id)}
                                                                        data={{ page: schedules.current_page, search: search }}
                                                                        aria-label={`Editar agendamento ${schedule.schedules_number}`}
                                                                    >
                                                                        <Edit className="h-4 w-4" />
                                                                    </Link>
                                                                </Button>
                                                            ) : (
                                                                <Button asChild size="icon" variant="outline" title="Ver ações do App técnico">
                                                                    <Link
                                                                        href={route('app.schedules.index', {
                                                                            search: schedule.schedules_number,
                                                                            tab: 'technician',
                                                                        })}
                                                                        aria-label={`Ver ações do App técnico do agendamento ${schedule.schedules_number}`}
                                                                    >
                                                                        <Eye className="h-4 w-4" />
                                                                    </Link>
                                                                </Button>
                                                            )}

                                                            {canManageSchedules && (
                                                                <ActionDelete
                                                                    title={'este agendamento'}
                                                                    url={'app.schedules.destroy'}
                                                                    param={schedule.id}
                                                                />
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })
                                    ) : (
                                        <TableRow>
                                            <TableCell colSpan={8} className="flex h-16 w-full items-center justify-center">
                                                Não há dados a serem mostrados no momento.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={8}>
                                            <AppPagination data={schedules} />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </TabsContent>

                    <TabsContent value="technician">
                        <TechnicianMobileTable
                            schedules={schedules?.data || []}
                            pagination={schedules}
                            canManageSchedules={Boolean(canManageSchedules)}
                        />
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
