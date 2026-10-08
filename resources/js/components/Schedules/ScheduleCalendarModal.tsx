import { Button } from '@/components/ui/button';
import { Calendar as AppCalendar } from '@/components/ui/calendar';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { Link, usePage } from '@inertiajs/react';
import { ArrowRight, Calendar, Plus } from 'lucide-react';
import moment from 'moment';
import { useMemo, useState } from 'react';

type ScheduleCalendarItem = {
    id?: number | string;
    schedules?: string;
    created_at?: string;
    name?: string;
    service?: string;
    customer?: {
        name?: string;
    };
};

type ScheduleCalendarModalProps = {
    schedules: ScheduleCalendarItem[];
    iconSize?: number;
    triggerLabel?: string;
    triggerClassName?: string;
};

export default function ScheduleCalendarModal({ schedules, iconSize, triggerLabel, triggerClassName }: ScheduleCalendarModalProps) {
    const [selectedDate, setSelectedDate] = useState<Date | undefined>(new Date());
    const { auth } = usePage<{ auth?: { role?: string; permissions?: string[] } }>().props;
    const canManageSchedules = auth?.role !== 'technician' && auth?.permissions?.includes('schedules');

    const schedulesByDay = useMemo(() => {
        const grouped = new Map<string, ScheduleCalendarItem[]>();

        schedules?.forEach((schedule) => {
            const dateField = schedule.schedules || schedule.created_at;
            if (!dateField) return;

            const dayKey = moment(dateField).format('YYYY-MM-DD');
            const list = grouped.get(dayKey) || [];
            list.push(schedule);
            grouped.set(dayKey, list);
        });

        return grouped;
    }, [schedules]);

    const calendarDaysWithVisits = useMemo(() => {
        return Array.from(schedulesByDay.keys()).map((dayKey) => moment(dayKey, 'YYYY-MM-DD').toDate());
    }, [schedulesByDay]);

    const selectedDateKey = selectedDate ? moment(selectedDate).format('YYYY-MM-DD') : '';
    const selectedDateVisits = selectedDateKey ? schedulesByDay.get(selectedDateKey) || [] : [];

    return (
        <Dialog>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DialogTrigger className={triggerClassName ?? 'flex h-full cursor-pointer items-center justify-center'} aria-label="Abrir agenda">
                        <Calendar size={iconSize} className="text-primary" />
                        {triggerLabel && <span>{triggerLabel}</span>}
                    </DialogTrigger>
                </TooltipTrigger>
                <TooltipContent>Abrir agenda</TooltipContent>
            </Tooltip>

            <DialogContent className="max-h-[90vh] w-full overflow-y-auto sm:max-w-2xl">
                <div className="flex flex-col gap-4 pr-8 sm:flex-row sm:items-start sm:justify-between">
                    <DialogHeader>
                        <DialogTitle>Calendário de visitas</DialogTitle>
                        <DialogDescription>
                            Visualização dos agendamentos exibidos nesta página. Dias com visitas ficam destacados em azul.
                        </DialogDescription>
                    </DialogHeader>

                    {canManageSchedules && (
                        <Tooltip>
                            <TooltipTrigger asChild>
                                <Button asChild size="icon" className="shrink-0">
                                    <Link href={route('app.schedules.create')} aria-label="Novo agendamento">
                                        <Plus className="h-4 w-4" />
                                    </Link>
                                </Button>
                            </TooltipTrigger>
                            <TooltipContent side="bottom">Novo agendamento</TooltipContent>
                        </Tooltip>
                    )}
                </div>

                <div className="flex flex-col gap-6">
                    <div className="flex justify-center">
                        <AppCalendar
                            mode="single"
                            selected={selectedDate}
                            onSelect={setSelectedDate}
                            modifiers={{ hasVisit: calendarDaysWithVisits }}
                            modifiersClassNames={{
                                hasVisit: 'font-semibold text-blue-700 underline decoration-blue-500 decoration-2 underline-offset-4',
                            }}
                            className="rounded-md border"
                        />
                    </div>

                    <div className="rounded-md border p-4">
                        <h4 className="mb-3 text-sm font-semibold">
                            {selectedDate ? `Visitas em ${moment(selectedDate).format('DD/MM/YYYY')}` : 'Selecione um dia'}
                        </h4>

                        {selectedDateVisits.length === 0 ? (
                            <p className="text-sm text-gray-500">Nenhuma visita agendada para este dia.</p>
                        ) : (
                            <div className="max-h-64 space-y-2 overflow-y-auto">
                                {selectedDateVisits
                                    .slice()
                                    .sort((a, b) => {
                                        const dateA = moment(a.schedules || a.created_at);
                                        const dateB = moment(b.schedules || b.created_at);
                                        return dateA.valueOf() - dateB.valueOf();
                                    })
                                    .map((schedule) => (
                                        <div key={schedule.id} className="bg-muted/30 rounded-md border p-3 text-sm">
                                            <div className="text-foreground font-medium">
                                                {moment(schedule.schedules || schedule.created_at).format('HH:mm')}
                                            </div>
                                            <div className="text-foreground/80">
                                                {schedule.customer?.name || schedule.name || 'Cliente não informado'}
                                            </div>
                                            {schedule.service && <div className="text-muted-foreground text-xs">{schedule.service}</div>}

                                            {schedule.id && (
                                                <div className="mt-3 border-t pt-2">
                                                    <Button asChild variant="link" size="sm" className="h-auto px-0 py-0">
                                                        <Link href={route('app.schedules.show', { schedule: schedule.id })}>
                                                            Acessar agendamento
                                                            <ArrowRight className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                </div>
                                            )}
                                        </div>
                                    ))}
                            </div>
                        )}
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
