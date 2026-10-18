import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { CalendarClock } from 'lucide-react';
import moment from 'moment';
import * as React from 'react';
import { ptBR } from 'react-day-picker/locale';

moment.locale('pt-br');

type DateTimePickerProps = {
    id?: string;
    value?: string | null;
    onChange: (value: string) => void;
    placeholder?: string;
};

function parseDateTime(value?: string | null): Date | undefined {
    if (!value) return undefined;

    const normalized = value.includes('T') ? value : value.replace(' ', 'T');
    const parsed = moment(normalized, ['YYYY-MM-DDTHH:mm', 'YYYY-MM-DDTHH:mm:ss'], true);

    return parsed.isValid() ? parsed.toDate() : undefined;
}

function toInputValue(date: Date): string {
    return moment(date).format('YYYY-MM-DDTHH:mm');
}

function toTimeValue(date?: Date): string {
    return date ? moment(date).format('HH:mm') : '09:00';
}

export function DateTimePicker({ id, value, onChange, placeholder = 'Selecione data e hora' }: DateTimePickerProps) {
    const [open, setOpen] = React.useState(false);
    const selectedDate = React.useMemo(() => parseDateTime(value), [value]);
    const [time, setTime] = React.useState(toTimeValue(selectedDate));

    React.useEffect(() => {
        setTime(toTimeValue(selectedDate));
    }, [selectedDate]);

    const displayValue = selectedDate ? moment(selectedDate).format('DD/MM/YYYY HH:mm') : placeholder;

    const commitDateTime = (date: Date | undefined, nextTime = time) => {
        if (!date) return;

        const [hours, minutes] = nextTime.split(':').map(Number);
        const nextDate = new Date(date);
        nextDate.setHours(Number.isFinite(hours) ? hours : 9, Number.isFinite(minutes) ? minutes : 0, 0, 0);
        onChange(toInputValue(nextDate));
    };

    const handleDateSelect = (date: Date | undefined) => {
        commitDateTime(date);
    };

    const handleTimeChange = (event: React.ChangeEvent<HTMLInputElement>) => {
        const nextTime = event.target.value;
        setTime(nextTime);

        if (selectedDate) {
            commitDateTime(selectedDate, nextTime);
        }
    };

    const handleQuickTime = (nextTime: string) => {
        setTime(nextTime);
        commitDateTime(selectedDate ?? new Date(), nextTime);
    };

    const handleNow = () => {
        const now = new Date();
        setTime(toTimeValue(now));
        onChange(toInputValue(now));
        setOpen(false);
    };

    return (
        <Popover open={open} onOpenChange={setOpen}>
            <PopoverTrigger asChild>
                <div className="relative">
                    <Input
                        id={id}
                        value={displayValue}
                        placeholder={placeholder}
                        className="bg-background cursor-pointer pr-10"
                        readOnly
                        onClick={() => setOpen(true)}
                        aria-label={placeholder}
                    />
                    <div className="text-muted-foreground absolute top-1/2 right-3 -translate-y-1/2">
                        <CalendarClock className="size-4" />
                    </div>
                </div>
            </PopoverTrigger>

            <PopoverContent className="w-auto space-y-3 p-3" align="start" sideOffset={8}>
                <Calendar locale={ptBR} mode="single" selected={selectedDate} onSelect={handleDateSelect} className="rounded-lg border shadow-sm" />

                <div className="grid gap-2">
                    <Input type="time" value={time} onChange={handleTimeChange} />
                    <div className="grid grid-cols-4 gap-2">
                        {['08:00', '09:00', '13:00', '14:00'].map((quickTime) => (
                            <button
                                key={quickTime}
                                type="button"
                                className="bg-muted text-foreground hover:bg-muted/80 rounded-md px-2 py-1.5 text-xs font-medium"
                                onClick={() => handleQuickTime(quickTime)}
                            >
                                {quickTime}
                            </button>
                        ))}
                    </div>
                    <button
                        type="button"
                        className="bg-primary text-primary-foreground hover:bg-primary/90 rounded-md px-3 py-2 text-sm font-medium"
                        onClick={handleNow}
                    >
                        Usar agora
                    </button>
                </div>
            </PopoverContent>
        </Popover>
    );
}
