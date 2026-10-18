import { apios } from '@/Utils/connectApi';
import selectStyles from '@/Utils/selectStyles';
import type { OptionType } from '@/types';
import { type GroupBase, type OptionsOrGroups, type SingleValue } from 'react-select';
import AsyncSelect from 'react-select/async';

interface AsyncResourceSelectProps<TOption extends OptionType = OptionType> {
    /** URL do endpoint de busca (ex: route('app.customers.search')). Recebe `?q=`. */
    searchUrl: string;
    value: TOption | null;
    onChange: (option: SingleValue<TOption>) => void;
    placeholder?: string;
    className?: string;
    isDisabled?: boolean;
    inputId?: string;
    /** Converte cada item retornado pela API em uma option. Default: usa `id`/`name`. */
    mapOption?: (item: any) => TOption;
    /** Carrega uma leva inicial (antes do usuário digitar), útil pra já mostrar algumas opções ao abrir. */
    loadInitialOptions?: boolean;
}

const defaultMapOption = (item: any): OptionType => ({
    value: item.id,
    label: item.name ?? item.equipment ?? String(item.id),
});

/**
 * Select com busca no servidor (debounced), pra substituir listas inteiras carregadas de uma vez
 * (clientes, peças, equipamentos). Reaproveita o mesmo `selectStyles` dos selects atuais.
 */
export default function AsyncResourceSelect<TOption extends OptionType = OptionType>({
    searchUrl,
    value,
    onChange,
    placeholder = 'Digite para buscar...',
    className,
    isDisabled,
    inputId,
    mapOption = defaultMapOption as (item: any) => TOption,
    loadInitialOptions = true,
}: AsyncResourceSelectProps<TOption>) {
    const loadOptions = (inputValue: string): Promise<OptionsOrGroups<TOption, GroupBase<TOption>>> => {
        return apios
            .get(searchUrl, { params: { q: inputValue } })
            .then((response) => (Array.isArray(response.data) ? response.data.map(mapOption) : []))
            .catch(() => []);
    };

    return (
        <AsyncSelect<TOption, false>
            inputId={inputId}
            isClearable
            isDisabled={isDisabled}
            menuPosition="fixed"
            menuPortalTarget={typeof document !== 'undefined' ? document.body : undefined}
            cacheOptions
            defaultOptions={loadInitialOptions}
            loadOptions={loadOptions}
            value={value}
            onChange={onChange}
            placeholder={placeholder}
            noOptionsMessage={({ inputValue }) => (inputValue ? 'Nenhum resultado encontrado' : 'Digite para buscar')}
            loadingMessage={() => 'Buscando...'}
            className={className}
            styles={selectStyles}
        />
    );
}
