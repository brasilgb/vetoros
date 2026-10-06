import { statusSaas } from '@/Utils/dataSelect';
import { maskCep, maskCpfCnpj, maskPhone, unMask } from '@/Utils/mask';
import { toastSuccess } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import selectStyles from '@/Utils/selectStyles';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Building, Save } from 'lucide-react';
import Select from 'react-select';
import moment from 'moment';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Empresas',
        href: route('admin.tenants.index'),
    },
    {
        title: 'Editar',
        href: '#',
    },
];

export default function EditTenant({ plans, tenant }: any) {
    const allPlans = plans.map((plan: any) => ({
        value: plan.id,
        label: plan.name,
    }));

    const { data, setData, patch, processing, reset, errors } = useForm({
        company: tenant.company,
        name: tenant.name,
        cnpj: tenant.cnpj,
        email: tenant.email,
        phone: tenant.phone,
        whatsapp: tenant.whatsapp,
        zip_code: tenant.zip_code,
        state: tenant.state,
        city: tenant.city,
        district: tenant.district,
        street: tenant.street,
        complement: tenant.complement,
        number: tenant.number,
        plan_id: tenant.plan_id,
        status: tenant.status,
        observations: tenant.observations,
        automatic_fiscal_emission_enabled: Boolean(tenant.automatic_fiscal_emission_enabled),
    });

    const handleSubmit = async (e: any) => {
        e.preventDefault();
        patch(route('admin.tenants.update', tenant.id), {
            onSuccess: () => {
                toastSuccess('Sucesso', 'Cadastro alterado com sucesso');
            },
        });
    };

    const getCep = (zip_code: string) => {
        const cleanCep = unMask(zip_code);
        fetch(`https://viacep.com.br/ws/${cleanCep}/json/`)
            .then((response) => response.json())
            .then((result) => {
                setData((data) => ({ ...data, state: result.uf }));
                setData((data) => ({ ...data, city: result.localidade }));
                setData((data) => ({ ...data, district: result.bairro }));
                setData((data) => ({ ...data, street: result.logradouro }));
                setData((data) => ({ ...data, complement: result.complemento }));
            })
            .catch((error) => console.error(error));
    };

    const changePlan = (selected: any) => {
        setData('plan_id', selected?.value);
    };

    const changeStatus = (selected: any) => {
        setData('status', selected?.value);
    };

    const sendSubscriptionEmail = (scenario: string) => {
        router.post(
            route('admin.tenants.subscription-email-send', [tenant.id, scenario]),
            {},
            {
                preserveScroll: true,
                onSuccess: () => {
                    toastSuccess('Sucesso', 'E-mail enviado com sucesso');
                },
            },
        );
    };

    const selectedPlan = allPlans.find((option: any) => String(option.value) === String(data.plan_id)) ?? null;
    const defaultStatusSaas = statusSaas?.filter((o: any) => o.value == tenant?.status).map((opt: any) => ({ value: opt.value, label: opt.label }));
    const subscriptionStatusLabel = tenant.subscription_status === 'active' ? 'Ativa' : tenant.subscription_status === 'blocked' ? 'Bloqueada' : tenant.subscription_status ?? 'Nao definido';
    const expirationLabel = tenant.expires_at ? moment(tenant.expires_at).format('DD/MM/YYYY') : 'Sem vencimento definido';
    const daysRemainingLabel = tenant.status_label ?? 'Sem informacao';
    const renewalReferenceLabel = tenant.period?.name ?? tenant.plan?.name ?? 'Sem plano definido';
    const currentPlanPeriodLabel = tenant.current_plan_period_label ?? 'Sem periodo definido';
    const subscriptionBadgeClassName =
        tenant.subscription_status === 'active'
            ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
            : tenant.subscription_status === 'blocked'
              ? 'border-rose-200 bg-rose-50 text-rose-700'
              : 'border-amber-200 bg-amber-50 text-amber-700';

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Empresas" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Building} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Empresas</h2>
                </div>
            </div>

            <div className="flex items-center justify-between p-4">
                <div>
                    <Button variant={'default'} asChild>
                        <Link href={route('admin.tenants.index')}>
                            <ArrowLeft className="h-4 w-4" />
                            <span>Voltar</span>
                        </Link>
                    </Button>
                </div>
                <div></div>
            </div>

            <div className="p-4">
                <div className="mb-4 grid gap-4 md:grid-cols-4">
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Plano atual</p>
                        <p className="mt-2 text-lg font-semibold">{tenant.plan?.name ?? 'Sem plano definido'}</p>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Periodo atual</p>
                        <p className="mt-2 text-lg font-semibold">{currentPlanPeriodLabel}</p>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Vence em</p>
                        <p className="mt-2 text-lg font-semibold">{expirationLabel}</p>
                        <p className="mt-1 text-sm text-muted-foreground">{daysRemainingLabel}</p>
                    </div>
                    <div className="rounded-lg border bg-card p-4">
                        <p className="text-sm text-muted-foreground">Assinatura</p>
                        <div className="mt-2 flex items-center gap-2">
                            <Badge variant="outline" className={subscriptionBadgeClassName}>
                                {subscriptionStatusLabel}
                            </Badge>
                            <Badge variant="secondary">{renewalReferenceLabel}</Badge>
                        </div>
                    </div>
                </div>

                <div className="mb-4 rounded-lg border bg-card p-4">
                    <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                        <div>
                            <p className="text-sm text-muted-foreground">Prévias de comunicação</p>
                            <p className="mt-1 text-sm text-muted-foreground">Abra os modelos automáticos de assinatura sem enviar e-mail real.</p>
                        </div>
                        <div className="flex flex-wrap gap-2">
                            <Button variant="outline" asChild>
                                <a href={route('admin.tenants.subscription-email-preview', [tenant.id, 'expires_in_3_days'])} target="_blank" rel="noreferrer">
                                    Vence em 3 dias
                                </a>
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => sendSubscriptionEmail('expires_in_3_days')}>
                                Enviar 3 dias
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={route('admin.tenants.subscription-email-preview', [tenant.id, 'expires_tomorrow'])} target="_blank" rel="noreferrer">
                                    Vence amanhã
                                </a>
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => sendSubscriptionEmail('expires_tomorrow')}>
                                Enviar amanhã
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={route('admin.tenants.subscription-email-preview', [tenant.id, 'expires_today'])} target="_blank" rel="noreferrer">
                                    Vence hoje
                                </a>
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => sendSubscriptionEmail('expires_today')}>
                                Enviar hoje
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={route('admin.tenants.subscription-email-preview', [tenant.id, 'grace'])} target="_blank" rel="noreferrer">
                                    Carência
                                </a>
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => sendSubscriptionEmail('grace')}>
                                Enviar carência
                            </Button>
                            <Button variant="outline" asChild>
                                <a href={route('admin.tenants.subscription-email-preview', [tenant.id, 'blocked'])} target="_blank" rel="noreferrer">
                                    Bloqueada
                                </a>
                            </Button>
                            <Button type="button" variant="secondary" onClick={() => sendSubscriptionEmail('blocked')}>
                                Enviar bloqueio
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="rounded-lg border p-2">
                    <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                        <div className="mt-4 grid gap-4 md:grid-cols-9">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="company">Razão social</Label>
                                <Input type="text" id="company" value={data.company} onChange={(e) => setData('company', e.target.value)} />
                                {errors.company && <div className="text-sm text-red-500">{errors.company}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="cnpj">CPF/CNPJ</Label>
                                <Input
                                    type="text"
                                    id="cnpj"
                                    value={maskCpfCnpj(data.cnpj)}
                                    onChange={(e) => setData('cnpj', e.target.value)}
                                    maxLength={18}
                                />
                                {errors.cnpj && <div className="text-sm text-red-500">{errors.cnpj}</div>}
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="email">E-mail</Label>
                                <Input type="text" id="email" value={data.email} onChange={(e) => setData('email', e.target.value)} />
                                {errors.email && <div className="text-sm text-red-500">{errors.email}</div>}
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="name">Contato</Label>
                                <Input type="text" id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                                {errors.name && <div className="text-sm text-red-500">{errors.name}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="phone">Telefone</Label>
                                <Input
                                    type="text"
                                    id="phone"
                                    value={maskPhone(data.phone)}
                                    onChange={(e) => setData('phone', e.target.value)}
                                    maxLength={15}
                                />
                                {errors.phone && <div className="text-sm text-red-500">{errors.phone}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="whatsapp">Whatsapp</Label>
                                <Input
                                    type="text"
                                    id="whatsapp"
                                    value={maskPhone(data.whatsapp)}
                                    onChange={(e) => setData('whatsapp', e.target.value)}
                                    maxLength={15}
                                />
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-6">
                            <div className="grid gap-2">
                                <Label htmlFor="zip_code">CEP</Label>
                                <Input
                                    type="text"
                                    id="zip_code"
                                    value={maskCep(data.zip_code)}
                                    onChange={(e) => setData('zip_code', e.target.value)}
                                    onBlur={(e) => getCep(e.target.value)}
                                    maxLength={9}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="state">UF</Label>
                                <Input type="text" id="state" value={data.state} onChange={(e) => setData('state', e.target.value)} />
                                {errors.state && <div>{errors.state}</div>}
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="city">Cidade</Label>
                                <Input type="text" id="city" value={data.city} onChange={(e) => setData('city', e.target.value)} />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="district">Bairro</Label>
                                <Input type="text" id="district" value={data.district} onChange={(e) => setData('district', e.target.value)} />
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-4">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="street">Endereço</Label>
                                <Input type="text" id="street" value={data.street} onChange={(e) => setData('street', e.target.value)} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="complement">Complemento</Label>
                                <Input type="text" id="complement" value={data.complement} onChange={(e) => setData('complement', e.target.value)} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="number">Número</Label>
                                <Input type="text" id="number" value={data.number} onChange={(e) => setData('number', e.target.value)} />
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-4">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="plan">Plano</Label>
                                <Select
                                    value={selectedPlan}
                                    options={allPlans}
                                    onChange={changePlan}
                                    placeholder="Selecione o plano"
                                    className="h-9 rounded-md border border-gray-300 p-0 text-gray-700 shadow-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    styles={selectStyles}
                                />
                                <InputError className="mt-2" message={errors.plan_id} />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="status">Status</Label>
                                <Select
                                    defaultValue={defaultStatusSaas}
                                    options={statusSaas}
                                    onChange={changeStatus}
                                    placeholder="Selecione o status"
                                    className="h-9 rounded-md border border-gray-300 p-0 text-gray-700 shadow-xs focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                    styles={selectStyles}
                                />
                                <InputError className="mt-2" message={errors.status} />
                            </div>
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                            <div>
                                <Label htmlFor="automatic_fiscal_emission_enabled">Emissão fiscal automática</Label>
                                <p className="text-muted-foreground text-sm">
                                    Libera NF-e, NFC-e e NFS-e emitidas pelo sistema. O cliente ainda precisa concluir o cadastro fiscal e enviar o
                                    certificado.
                                </p>
                            </div>
                            <Switch
                                id="automatic_fiscal_emission_enabled"
                                checked={data.automatic_fiscal_emission_enabled}
                                onCheckedChange={(checked) => setData('automatic_fiscal_emission_enabled', checked)}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="observations">Observações</Label>
                            <Textarea id="observations" value={data.observations} onChange={(e) => setData('observations', e.target.value)} />
                        </div>

                        <div className="flex justify-end">
                            <Button type="submit" disabled={processing}>
                                <Save />
                                Salvar
                            </Button>
                        </div>
                    </form>
                </div>
            </div>
        </AdminLayout>
    );
}
