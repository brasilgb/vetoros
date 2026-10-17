import InputError from '@/components/input-error';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { statusSaas } from '@/Utils/dataSelect';
import { maskCep, maskCpfCnpj, maskPhone, unMask } from '@/Utils/mask';
import selectStyles from '@/Utils/selectStyles';
import Select from 'react-select';

/** Campos do cadastro da empresa no RootAdmin, compartilhados pelo cadastro e pela edição (VETOR-UX-ADMIN-03). */
export type TenantFormData = {
    company: string;
    name: string;
    cnpj: string;
    email: string;
    phone: string;
    whatsapp: string;
    zip_code: string;
    state: string;
    city: string;
    district: string;
    street: string;
    complement: string;
    number: string;
    plan_id: string | number;
    status: string | number;
    observations: string;
};

type SetData = <K extends keyof TenantFormData>(key: K, value: TenantFormData[K]) => void;
type FieldProps = { data: TenantFormData; setData: SetData; errors: Partial<Record<string, string>> };

const selectClass =
    'h-9 rounded-md border border-gray-300 p-0 text-gray-700 shadow-xs focus:ring-2 focus:ring-blue-500 focus:outline-none dark:border-input';

function TextField({
    id,
    label,
    value,
    onChange,
    error,
    className,
    ...props
}: {
    id: string;
    label: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    className?: string;
} & Omit<React.ComponentProps<'input'>, 'onChange' | 'value'>) {
    return (
        <div className={`grid gap-2 ${className ?? ''}`}>
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type="text" value={value ?? ''} onChange={(e) => onChange(e.target.value)} aria-invalid={Boolean(error)} {...props} />
            <InputError message={error} />
        </div>
    );
}

export function TenantGeneralFields({ data, setData, errors }: FieldProps) {
    return (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            <TextField
                id="company"
                label="Razão social"
                value={data.company}
                onChange={(v) => setData('company', v)}
                error={errors.company}
                className="md:col-span-2"
            />
            <TextField
                id="cnpj"
                label="CPF/CNPJ"
                value={maskCpfCnpj(data.cnpj)}
                onChange={(v) => setData('cnpj', v)}
                error={errors.cnpj}
                maxLength={18}
            />
            <TextField id="name" label="Contato" value={data.name} onChange={(v) => setData('name', v)} error={errors.name} />
            <TextField
                id="email"
                label="E-mail"
                value={data.email}
                onChange={(v) => setData('email', v)}
                error={errors.email}
                className="md:col-span-2"
            />
            <TextField
                id="phone"
                label="Telefone"
                value={maskPhone(data.phone)}
                onChange={(v) => setData('phone', v)}
                error={errors.phone}
                maxLength={15}
            />
            <TextField
                id="whatsapp"
                label="Whatsapp"
                value={maskPhone(data.whatsapp)}
                onChange={(v) => setData('whatsapp', v)}
                error={errors.whatsapp}
                maxLength={15}
            />
            <div className="grid gap-2 md:col-span-2 xl:col-span-4">
                <Label htmlFor="observations">Observações</Label>
                <Textarea id="observations" value={data.observations ?? ''} onChange={(e) => setData('observations', e.target.value)} />
                <InputError message={errors.observations} />
            </div>
        </div>
    );
}

export function TenantAddressFields({ data, setData, errors }: FieldProps) {
    // Preenche o endereço pelo CEP (ViaCEP), como antes.
    const getCep = (zipCode: string) => {
        const cleanCep = unMask(zipCode);
        if (!cleanCep) return;
        fetch(`https://viacep.com.br/ws/${cleanCep}/json/`)
            .then((response) => response.json())
            .then((result) => {
                if (result?.erro) return;
                setData('state', result.uf ?? '');
                setData('city', result.localidade ?? '');
                setData('district', result.bairro ?? '');
                setData('street', result.logradouro ?? '');
                setData('complement', result.complemento ?? '');
            })
            .catch((error) => console.error(error));
    };

    return (
        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-6">
            <TextField
                id="zip_code"
                label="CEP"
                value={maskCep(data.zip_code)}
                onChange={(v) => setData('zip_code', v)}
                onBlur={(e) => getCep(e.target.value)}
                error={errors.zip_code}
                maxLength={9}
            />
            <TextField id="state" label="UF" value={data.state} onChange={(v) => setData('state', v)} error={errors.state} />
            <TextField
                id="city"
                label="Cidade"
                value={data.city}
                onChange={(v) => setData('city', v)}
                error={errors.city}
                className="xl:col-span-2"
            />
            <TextField
                id="district"
                label="Bairro"
                value={data.district}
                onChange={(v) => setData('district', v)}
                error={errors.district}
                className="xl:col-span-2"
            />
            <TextField
                id="street"
                label="Endereço"
                value={data.street}
                onChange={(v) => setData('street', v)}
                error={errors.street}
                className="md:col-span-2 xl:col-span-3"
            />
            <TextField id="number" label="Número" value={data.number} onChange={(v) => setData('number', v)} error={errors.number} />
            <TextField
                id="complement"
                label="Complemento"
                value={data.complement}
                onChange={(v) => setData('complement', v)}
                error={errors.complement}
                className="xl:col-span-2"
            />
        </div>
    );
}

/** Plano tradicional. Com contrato personalizado fica bloqueado (a troca é só por "Voltar ao plano tradicional"). */
export function TenantPlanField({
    plans,
    value,
    onChange,
    error,
    locked = false,
}: {
    plans: { id: number; name: string }[];
    value: string | number;
    onChange: (value: string | number) => void;
    error?: string;
    locked?: boolean;
}) {
    const options = plans.map((plan) => ({ value: plan.id, label: plan.name }));
    const selected = options.find((option) => String(option.value) === String(value)) ?? null;

    return (
        <div className="grid max-w-xl gap-2">
            <Label htmlFor="plan">Plano</Label>
            <Select
                inputId="plan"
                value={selected}
                options={options}
                onChange={(option) => onChange(option?.value ?? '')}
                isDisabled={locked}
                placeholder="Selecione o plano"
                className={selectClass}
                styles={selectStyles}
            />
            {locked && (
                <p className="text-muted-foreground text-xs">
                    Bloqueado pelo contrato personalizado: o plano define só as funcionalidades e muda apenas em "Voltar ao plano tradicional".
                </p>
            )}
            <InputError message={error} />
        </div>
    );
}

/** Situação da conta (ativa, inativa, trial, pausada…). Controlado: não perde o valor ao trocar de aba. */
export function TenantStatusField({ value, onChange, error }: { value: string | number; onChange: (value: string) => void; error?: string }) {
    const selected = statusSaas.find((option: { value: string }) => String(option.value) === String(value)) ?? null;

    return (
        <div className="grid max-w-xl gap-2">
            <Label htmlFor="status">Status</Label>
            <Select
                inputId="status"
                value={selected}
                options={statusSaas}
                onChange={(option: { value: string } | null) => onChange(option?.value ?? '')}
                placeholder="Selecione o status"
                className={selectClass}
                styles={selectStyles}
            />
            <InputError message={error} />
        </div>
    );
}
