import { Select, SelectContent, SelectGroup, SelectItem, SelectLabel, SelectTrigger } from '@/components/ui/select';

import { router, usePage } from '@inertiajs/react';

interface SelectFilterProps {
    dataStatus: Array<{
        value: string | number;
        label: string;
    }>;
    specialFilters?: Array<{
        value: string;
        label: string;
        param?: 'filter' | 'status';
    }>;
    url: string;
    noOrder?: boolean;
}

export default function SelectFilter({ dataStatus, specialFilters = [], url, noOrder }: SelectFilterProps) {
    const { ziggy } = usePage<{ ziggy?: { query?: Record<string, string> } }>().props;
    const currentQuery = ziggy?.query ?? {};
    const search = ziggy?.query?.search ?? '';
    const status = ziggy?.query?.status ?? '';
    const filter = ziggy?.query?.filter ?? '';
    const selectedValue = filter ? `filter:${filter}` : status ? `status:${status}` : undefined;
    const specialFilterLabel = specialFilters.find((item) => `${item.param ?? 'filter'}:${item.value}` === selectedValue)?.label;
    const statusLabel = dataStatus.find((item) => `status:${String(item.value)}` === selectedValue)?.label;
    const selectedLabel = (!search && !filter && !status && 'Opções de filtro') || specialFilterLabel || statusLabel || 'Opções de filtro';

    function handleSubmit(value: string) {
        const [type, selected] = value.split(':');

        router.get(
            route(url),
            {
                ...currentQuery,
                page: undefined,
                status: type === 'status' ? selected : undefined,
                filter: type === 'filter' ? selected : undefined,
            },
            {
                preserveState: true,
                replace: true,
            },
        );
    }

    return (
        <div className="flex items-center space-x-2">
            <Select value={selectedValue} onValueChange={handleSubmit}>
                <SelectTrigger className="w-full">
                    <span className={selectedValue ? '' : 'text-muted-foreground'}>{selectedLabel}</span>
                </SelectTrigger>

                <SelectContent>
                    {specialFilters.length > 0 && (
                        <SelectGroup>
                            <SelectLabel>Filtros</SelectLabel>

                            {specialFilters.map((item) => (
                                <SelectItem key={`${item.param ?? 'filter'}:${item.value}`} value={`${item.param ?? 'filter'}:${item.value}`}>
                                    {item.label}
                                </SelectItem>
                            ))}
                        </SelectGroup>
                    )}

                    <SelectGroup>
                        <SelectLabel>{noOrder ? 'Status' : 'Status e Feedback'}</SelectLabel>

                        {dataStatus.map((item) => (
                            <SelectItem key={String(item.value)} value={`status:${String(item.value)}`}>
                                {item.label}
                            </SelectItem>
                        ))}
                    </SelectGroup>
                </SelectContent>
            </Select>
        </div>
    );
}
