import { toastSuccess } from '@/components/app-toast-messages';
import { DatePicker } from '@/components/date-picker';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { useZipcodeAutocomplete } from '@/hooks/useZipcodeAutocomplete';
import { Customer } from '@/types';
import { maskCep, maskCpfCnpj, maskPhone, unMask } from '@/Utils/mask';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Loader2Icon, Save, Search } from 'lucide-react';
import { useEffect, useState } from 'react';

type DuplicateCustomer = {
    id: number;
    customer_number: number;
    name: string;
};

export default function CustomerForm({ initialData }: { initialData?: Customer }) {
    const isEdit = !!initialData;

    const [zipcodeToSearch, setZipcodeToSearch] = useState<string | null>(null);
    const [duplicateCustomers, setDuplicateCustomers] = useState<DuplicateCustomer[]>([]);

    const { data, setData, post, processing, reset, setError, clearErrors, errors, put } = useForm<Customer>(initialData || ({} as Customer));

    // Quando marcado, o WhatsApp acompanha o telefone; na edição, já vem marcado se os dois forem iguais.
    const [phoneIsWhatsapp, setPhoneIsWhatsapp] = useState(() => !!initialData?.phone && initialData.phone === initialData.whatsapp);

    const handlePhoneChange = (value: string) => {
        setData('phone', value);
        if (phoneIsWhatsapp) setData('whatsapp', value);
    };

    const togglePhoneIsWhatsapp = (checked: boolean) => {
        setPhoneIsWhatsapp(checked);
        if (checked) setData('whatsapp', data.phone ?? '');
    };

    const handleCreate = () => {
        post(route('app.customers.store'), {
            onSuccess: () => {
                toastSuccess('Cliente salvo', 'Cadastro realizado com sucesso');
                reset();
            },
        });
    };

    const handleUpdate = () => {
        put(route('app.customers.update', initialData!.id), {
            onSuccess: () => {
                toastSuccess('Cliente alterado', 'Edição realizada com sucesso');
            },
        });
    };

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEdit) {
            handleUpdate();
        } else {
            handleCreate();
        }
    };

    const { isZipcodeLoading } = useZipcodeAutocomplete<Customer>({
        zipcode: zipcodeToSearch || '',
        paths: {
            zipcode: 'zipcode',
            state: 'state',
            city: 'city',
            district: 'district',
            street: 'street',
            complement: 'complement',
        },
        setData: setData as any,
        setError,
        clearErrors,
    } as any);

    useEffect(() => {
        const cpfcnpj = unMask(data.cpfcnpj) ?? '';
        const phone = unMask(data.phone) ?? '';
        const whatsapp = unMask(data.whatsapp) ?? '';
        const email = String(data.email ?? '').trim();

        if (!cpfcnpj && !phone && !whatsapp && !email) {
            setDuplicateCustomers([]);
            return;
        }

        const timeout = window.setTimeout(() => {
            const params = new URLSearchParams({
                cpfcnpj,
                phone,
                whatsapp,
                email,
                ...(initialData?.id ? { exclude_id: String(initialData.id) } : {}),
            });

            fetch(`${route('app.customers.duplicate-check')}?${params.toString()}`, {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            })
                .then((response) => (response.ok ? response.json() : Promise.reject()))
                .then((response) => setDuplicateCustomers(response.matches ?? []))
                .catch(() => setDuplicateCustomers([]));
        }, 450);

        return () => window.clearTimeout(timeout);
    }, [data.cpfcnpj, data.phone, data.whatsapp, data.email, initialData?.id]);

    return (
        <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
            {duplicateCustomers.length > 0 && (
                <div className="flex flex-col gap-3 rounded-lg border border-amber-200 bg-amber-50/80 px-4 py-3 text-amber-950 md:flex-row md:items-center md:justify-between dark:border-amber-900 dark:bg-amber-950/20 dark:text-amber-100">
                    <div className="flex items-start gap-2">
                        <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                        <div>
                            <p className="text-sm font-medium">Possível cliente já cadastrado</p>
                            <p className="mt-1 text-xs opacity-80">Confira documento ou contato antes de salvar. O aviso não bloqueia o cadastro.</p>
                        </div>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {duplicateCustomers.map((customer) => (
                            <Button key={customer.id} type="button" size="sm" variant="outline" asChild>
                                <a href={route('app.customers.edit', customer.id)}>
                                    #{customer.customer_number} · {customer.name}
                                </a>
                            </Button>
                        ))}
                    </div>
                </div>
            )}
            <Card>
                <CardTitle className="border-b px-6 pb-4">Dados principais</CardTitle>
                <CardContent className="pt-6">
                    <div className="grid gap-4 md:grid-cols-6">
                        <div className="grid gap-2">
                            <Label htmlFor="cpfcnpj">CPF/CNPJ</Label>
                            <Input
                                type="text"
                                id="cpfcnpj"
                                value={maskCpfCnpj(data.cpfcnpj)}
                                onChange={(e) => setData('cpfcnpj', unMask(e.target.value) ?? '')}
                                maxLength={18}
                            />
                            {errors.cpfcnpj && <div className="text-sm text-red-500">{errors.cpfcnpj}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="birth">Nascimento</Label>
                            <DatePicker
                                mode="single"
                                date={data.birth}
                                setDate={(value) => {
                                    if (!value) {
                                        setData('birth', '');
                                        return;
                                    }
                                    const d = value as Date;
                                    const formatted = [
                                        d.getFullYear(),
                                        String(d.getMonth() + 1).padStart(2, '0'),
                                        String(d.getDate()).padStart(2, '0'),
                                    ].join('-');

                                    setData('birth', formatted);
                                }}
                            />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="name">Nome</Label>
                            <Input type="text" id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            {errors.name && <div className="text-sm text-red-500">{errors.name}</div>}
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="email">E-mail</Label>
                            <Input type="email" id="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                            {errors.email && <div className="text-sm text-red-500">{errors.email}</div>}
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Endereço</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-4 md:grid-cols-6">
                        <div className="grid gap-2">
                            <Label htmlFor="zipcode">CEP</Label>
                            <div className="relative flex gap-2">
                                <div className="relative w-full">
                                    <Input
                                        type="text"
                                        id="zipcode"
                                        value={maskCep(data.zipcode || '')}
                                        onChange={(e) => {
                                            const val = unMask(e.target.value) ?? '';
                                            setData('zipcode', val);
                                            clearErrors('zipcode' as any);

                                            // Dispara busca ao completar 8 dígitos, caso contrário reseta o gatilho
                                            if (val.length === 8) {
                                                setZipcodeToSearch(val);
                                            } else if (zipcodeToSearch !== null) {
                                                setZipcodeToSearch(null);
                                            }
                                        }}
                                        maxLength={9}
                                        disabled={isZipcodeLoading}
                                    />
                                    {isZipcodeLoading && (
                                        <Loader2Icon className="text-muted-foreground absolute top-1/2 right-3 h-4 w-4 -translate-y-1/2 animate-spin" />
                                    )}
                                </div>
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="outline"
                                    onClick={() => {
                                        const val = unMask(data.zipcode) ?? '';
                                        if (val.length === 8) {
                                            // Forçamos o reset para garantir que o hook dispare a busca novamente
                                            setZipcodeToSearch(null);
                                            setTimeout(() => setZipcodeToSearch(val), 50);
                                        }
                                    }}
                                    disabled={isZipcodeLoading || unMask(data.zipcode)?.length !== 8}
                                >
                                    <Search className="h-4 w-4" />
                                </Button>
                            </div>
                            {errors.zipcode && <div className="text-sm text-red-500">{errors.zipcode}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="state">UF</Label>
                            <Input type="text" id="state" value={data.state} onChange={(e) => setData('state', e.target.value)} />
                            {errors.state && <div className="text-sm text-red-500">{errors.state}</div>}
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="city">Cidade</Label>
                            <Input type="text" id="city" value={data.city} onChange={(e) => setData('city', e.target.value)} />
                            {errors.city && <div className="text-sm text-red-500">{errors.city}</div>}
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="district">Bairro</Label>
                            <Input type="text" id="district" value={data.district} onChange={(e) => setData('district', e.target.value)} />
                            {errors.district && <div className="text-sm text-red-500">{errors.district}</div>}
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-4">
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="street">Endereço</Label>
                            <Input type="text" id="street" value={data.street} onChange={(e) => setData('street', e.target.value)} />
                            {errors.street && <div className="text-sm text-red-500">{errors.street}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="complement">Complemento</Label>
                            <Input type="text" id="complement" value={data.complement} onChange={(e) => setData('complement', e.target.value)} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="number">Número</Label>
                            <Input type="text" id="number" value={data.number ?? ''} onChange={(e) => setData('number', e.target.value)} />
                            {errors.number && <div className="text-sm text-red-500">{errors.number}</div>}
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Contato</CardTitle>
                <CardContent className="pt-6">
                    <div className="grid gap-4 md:grid-cols-5">
                        <div className="grid gap-2">
                            <Label htmlFor="phone">Telefone</Label>
                            <Input
                                type="text"
                                id="phone"
                                value={maskPhone(data.phone)}
                                onChange={(e) => handlePhoneChange(unMask(e.target.value) ?? '')}
                                maxLength={15}
                            />
                            {errors.phone && <div className="text-sm text-red-500">{errors.phone}</div>}
                            <div className="flex items-center gap-2">
                                <Checkbox
                                    id="phone_is_whatsapp"
                                    checked={phoneIsWhatsapp}
                                    onCheckedChange={(v) => togglePhoneIsWhatsapp(v === true)}
                                />
                                <Label htmlFor="phone_is_whatsapp" className="cursor-pointer font-normal">
                                    Telefone também é WhatsApp
                                </Label>
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="whatsapp">Whatsapp</Label>
                            <Input
                                type="text"
                                id="whatsapp"
                                value={maskPhone(data.whatsapp)}
                                onChange={(e) => setData('whatsapp', unMask(e.target.value) ?? '')}
                                disabled={phoneIsWhatsapp}
                                maxLength={15}
                            />
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="contactname">Contato</Label>
                            <Input type="text" id="contactname" value={data.contactname} onChange={(e) => setData('contactname', e.target.value)} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="contactphone">Telefone do contato</Label>
                            <Input
                                type="text"
                                id="contactphone"
                                value={maskPhone(data.contactphone)}
                                onChange={(e) => setData('contactphone', unMask(e.target.value) ?? '')}
                                maxLength={15}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Observações</CardTitle>
                <CardContent className="pt-6">
                    <div className="grid gap-2">
                        <Label htmlFor="observations">Observações</Label>
                        <Textarea id="observations" value={data.observations} onChange={(e) => setData('observations', e.target.value)} />
                    </div>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    <Save />
                    Salvar
                </Button>
            </div>
        </form>
    );
}
