import { Calendar } from '@/components/ui/calendar';
import { Input } from '@/components/ui/input';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { CalendarIcon } from 'lucide-react';
import moment from 'moment';
import * as React from 'react';
import { DateRange, SelectRangeEventHandler, SelectSingleEventHandler } from 'react-day-picker';
import { ptBR } from 'react-day-picker/locale';
moment.locale('pt-br');

type DatePickerMode = 'single' | 'range';
type RangeInput = {
    from?: Date | string;
    to?: Date | string;
};

interface DatePickerProps {
    date: Date | string | DateRange | RangeInput | undefined;
    setDate: (date: Date | DateRange | undefined) => void;
    mode?: DatePickerMode;
}

// Arrays fixos para evitar recriação a cada render
const MONTHS = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
const currentYear = new Date().getFullYear();
const YEARS = Array.from({ length: 100 }, (_, i) => currentYear - 80 + i);

function isRangeInput(value: DatePickerProps['date']): value is DateRange | RangeInput {
    return typeof value === 'object' && value !== null && ('from' in value || 'to' in value);
}

export function DatePicker({ date, setDate, mode = 'range' }: DatePickerProps) {
    const [open, setOpen] = React.useState(false);

    function parseLocalDate(value?: string | Date) {
        if (!value) return undefined;
        if (value instanceof Date) return value;

        const [year, month, day] = value.split('-').map(Number);
        if (Number.isFinite(year) && Number.isFinite(month) && Number.isFinite(day)) {
            return new Date(year, month - 1, day);
        }

        const parsed = new Date(value);
        return Number.isNaN(parsed.getTime()) ? undefined : parsed;
    }

    const parsedDate = React.useMemo<Date | DateRange | undefined>(() => {
        const parse = (d: Date | string | undefined) => {
            if (!d) return undefined;
            return parseLocalDate(d);
        };

        if (mode === 'range') {
            const range = isRangeInput(date) ? date : undefined;
            return {
                from: parse(range?.from),
                to: parse(range?.to),
            };
        }

        return typeof date === 'string' || date instanceof Date ? parse(date) : undefined;
    }, [date, mode]);

    const initialMonth = React.useMemo(() => {
        if (mode === 'range') {
            const range = parsedDate as DateRange | undefined;
            return range?.from || new Date();
        }
        return (parsedDate as Date) || new Date();
    }, [parsedDate, mode]);

    const [month, setMonth] = React.useState<Date>(initialMonth);

    React.useEffect(() => {
        if (open) {
            setMonth(initialMonth);
        }
    }, [open, initialMonth]);

    const hasSelectedFirst = React.useRef(false);

    const displayValue = React.useMemo(() => {
        if (mode === 'range') {
            const range = parsedDate as DateRange | undefined;
            return range?.from
                ? range.to
                    ? `${moment(range.from).format('DD/MM/YYYY')} - ${moment(range.to).format('DD/MM/YYYY')}`
                    : moment(range.from).format('DD/MM/YYYY')
                : 'Selecione o intervalo';
        }
        const single = parsedDate as Date | undefined;
        return single ? moment(single).format('DD/MM/YYYY') : 'Selecione a data';
    }, [parsedDate, mode]);

    const handleSelectRange: SelectRangeEventHandler = (range) => {
        if (!range) {
            setDate(undefined);
            hasSelectedFirst.current = false;
            return;
        }

        const currentRange = parsedDate as DateRange | undefined;

        if (currentRange?.from && currentRange?.to) {
            // Reinicia nova seleção
            setDate({ from: range.from, to: undefined });
            hasSelectedFirst.current = true;
            return;
        }

        // Caso o usuário clique duas vezes no mesmo dia
        if (range.from && range.to && range.from.getTime() === range.to.getTime()) {
            setDate({ from: range.from, to: undefined });
            hasSelectedFirst.current = true;
            return;
        }

        setDate(range);

        if (!hasSelectedFirst.current) {
            hasSelectedFirst.current = true;
        } else if (range.from && range.to) {
            hasSelectedFirst.current = false;
            setOpen(false);
        }
    };

    const handleSelectSingle: SelectSingleEventHandler = (day) => {
        setDate(day);
        setOpen(false);
    };

    const handleMonthChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const newMonth = parseInt(e.target.value);
        const newDate = new Date(month);
        newDate.setMonth(newMonth);
        setMonth(newDate);
    };

    const handleYearChange = (e: React.ChangeEvent<HTMLSelectElement>) => {
        const newYear = parseInt(e.target.value);
        const newDate = new Date(month);
        newDate.setFullYear(newYear);
        setMonth(newDate);
    };

    return (
        <div className="flex w-full flex-col gap-3">
            <Popover open={open} onOpenChange={setOpen}>
                <PopoverTrigger asChild>
                    <div className="relative">
                        <Input
                            id="date"
                            value={displayValue}
                            placeholder={mode === 'range' ? 'Selecione o intervalo' : 'Selecione a data'}
                            className="bg-background cursor-pointer pr-10"
                            readOnly
                            onClick={() => setOpen(true)}
                            aria-label="Selecionar data"
                        />
                        <div className="text-muted-foreground absolute top-1/2 right-3 -translate-y-1/2">
                            <CalendarIcon className="size-3.5" />
                        </div>
                    </div>
                </PopoverTrigger>

                <PopoverContent className="w-auto overflow-hidden p-3" align="end" alignOffset={-8} sideOffset={10}>
                    {/* Cabeçalho de seleção de mês e ano */}
                    <div className="mb-2 flex items-center justify-between gap-2">
                        <select
                            className="bg-background text-foreground focus:ring-primary rounded-md border px-2 py-1 text-sm focus:ring-2 focus:outline-none"
                            value={new Date(month).getMonth()}
                            onChange={handleMonthChange}
                        >
                            {MONTHS.map((m, idx) => (
                                <option key={idx} value={idx}>
                                    {m.charAt(0).toUpperCase() + m.slice(1)}
                                </option>
                            ))}
                        </select>

                        <select
                            className="bg-background text-foreground focus:ring-primary rounded-md border px-2 py-1 text-sm focus:ring-2 focus:outline-none"
                            value={new Date(month).getFullYear()}
                            onChange={handleYearChange}
                        >
                            {YEARS.map((y) => (
                                <option key={y} value={y}>
                                    {y}
                                </option>
                            ))}
                        </select>
                    </div>

                    {/* Calendário principal */}
                    {mode === 'range' ? (
                        <Calendar
                            locale={ptBR}
                            mode="range"
                            selected={parsedDate as DateRange | undefined}
                            month={new Date(month)}
                            onMonthChange={setMonth}
                            onSelect={handleSelectRange}
                            className="rounded-lg border shadow-sm"
                        />
                    ) : (
                        <Calendar
                            locale={ptBR}
                            mode="single"
                            selected={parsedDate as Date | undefined}
                            month={new Date(month)}
                            onMonthChange={setMonth}
                            onSelect={handleSelectSingle}
                            className="rounded-lg border shadow-sm"
                        />
                    )}

                    {/* Botões de ação */}
                    <div className="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            onClick={() => {
                                const today = new Date();
                                if (mode === 'range') {
                                    setDate({ from: today, to: today });
                                } else {
                                    setDate(today);
                                }
                                setMonth(today);
                                setOpen(false);
                                hasSelectedFirst.current = false;
                            }}
                            className="text-primary hover:text-primary/80 text-sm font-medium transition-colors"
                        >
                            Hoje
                        </button>
                        {date && (
                            <button
                                type="button"
                                onClick={() => {
                                    setDate(undefined);
                                    hasSelectedFirst.current = false;
                                }}
                                className="text-muted-foreground hover:text-foreground text-sm transition-colors"
                            >
                                Limpar
                            </button>
                        )}
                    </div>
                </PopoverContent>
            </Popover>
        </div>
    );
}
