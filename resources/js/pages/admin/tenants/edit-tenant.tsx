import {
    CommercialChargesPanel,
    CommercialContractPanel,
    CommercialDialogs,
    commercialFieldTabs,
    SaasInvoicePanel,
    useTenantCommercial,
    type Commercial,
} from '@/components/admin/tenant-commercial-section';
import {
    TenantAddressFields,
    TenantGeneralFields,
    TenantPlanField,
    TenantStatusField,
    type TenantFormData,
} from '@/components/admin/tenant-form-fields';
import { TabBadge, tabTriggerClass, useTenantTabs, type TenantTab } from '@/components/admin/tenant-tabs';
import { toastSuccess } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, Building, ExternalLink, Loader2, Save } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Empresas', href: route('admin.tenants.index') },
    { title: 'Editar', href: '#' },
];

type Feature = { id: number; name: string };
type Period = { id: number; name: string; features?: Feature[] };
type Plan = { id: number; name: string; periods?: Period[] };
type TenantProps = Partial<Record<keyof TenantFormData, string | number | null>> & {
    id: number;
    plan: Plan | null;
    subscription_status: string | null;
    expires_at: string | null;
    status_label?: string | null;
    current_plan_period_label?: string | null;
    automatic_fiscal_emission_enabled?: boolean | number | null;
};
type Modules = { parts: boolean; sales: boolean; purchases: boolean; finance: boolean } | null;

// Campos do formulário principal e a aba de cada um (erros de validação levam à aba certa).
const mainFieldTabs: Record<string, TenantTab> = {
    company: 'general',
    name: 'general',
    cnpj: 'general',
    email: 'general',
    phone: 'general',
    whatsapp: 'general',
    zip_code: 'general',
    state: 'general',
    city: 'general',
    district: 'general',
    street: 'general',
    complement: 'general',
    number: 'general',
    observations: 'general',
    plan_id: 'subscription',
    period_id: 'subscription',
    status: 'modules',
    automatic_fiscal_emission_enabled: 'fiscal',
};

const emailScenarios = [
    { key: 'expires_in_3_days', label: 'Vence em 3 dias' },
    { key: 'expires_tomorrow', label: 'Vence amanhã' },
    { key: 'expires_today', label: 'Vence hoje' },
    { key: 'grace', label: 'Carência' },
    { key: 'blocked', label: 'Bloqueada' },
];

const moduleLabels: { key: keyof NonNullable<Modules>; label: string }[] = [
    { key: 'parts', label: 'Peças e produtos' },
    { key: 'sales', label: 'Vendas' },
    { key: 'purchases', label: 'Compras' },
    { key: 'finance', label: 'Financeiro' },
];

const customBadgeClass = 'border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-200';
const switchClass = 'dark:data-[state=unchecked]:bg-slate-600 dark:data-[state=unchecked]:border-slate-300';

function subscriptionTone(status: string | null) {
    if (status === 'active')
        return 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
    if (status === 'blocked') return 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200';
    return 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200';
}

/** Dias até o vencimento, calculados na tela (a edição não recebe o texto pronto da listagem). */
function remainingLabel(expiresAt: string | null) {
    if (!expiresAt) return 'Sem vencimento definido';
    const days = moment(expiresAt).startOf('day').diff(moment().startOf('day'), 'days');
    if (days > 0) return `${days} dia(s) restante(s)`;
    if (days === 0) return 'Expira hoje';
    return `Expirado há ${Math.abs(days)} dia(s)`;
}

function Section({ title, description, children }: { title: string; description?: string; children: React.ReactNode }) {
    return (
        <section className="bg-card space-y-4 rounded-lg border p-4">
            <div>
                <h3 className="font-semibold">{title}</h3>
                {description && <p className="text-muted-foreground text-sm">{description}</p>}
            </div>
            {children}
        </section>
    );
}

/**
 * Edição da empresa no RootAdmin (VETOR-UX-ADMIN-03), em abas. Todas as abas ficam montadas (só as inativas
 * ficam ocultas): o que foi digitado numa aba continua lá ao trocar de aba, até salvar.
 */
export default function EditTenant({
    plans,
    tenant,
    commercial,
    modules,
}: {
    plans: Plan[];
    tenant: TenantProps;
    commercial: Commercial;
    modules?: Modules;
}) {
    const { tab, setTab, errorTabs, goToErrors } = useTenantTabs('general');
    const [emailScenario, setEmailScenario] = useState<(typeof emailScenarios)[number] | null>(null);
    const [sendingEmail, setSendingEmail] = useState(false);

    const { data, setData, patch, processing, errors, isDirty, setDefaults } = useForm<
        TenantFormData & { automatic_fiscal_emission_enabled: boolean }
    >({
        company: String(tenant.company ?? ''),
        name: String(tenant.name ?? ''),
        cnpj: String(tenant.cnpj ?? ''),
        email: String(tenant.email ?? ''),
        phone: String(tenant.phone ?? ''),
        whatsapp: String(tenant.whatsapp ?? ''),
        zip_code: String(tenant.zip_code ?? ''),
        state: String(tenant.state ?? ''),
        city: String(tenant.city ?? ''),
        district: String(tenant.district ?? ''),
        street: String(tenant.street ?? ''),
        complement: String(tenant.complement ?? ''),
        number: String(tenant.number ?? ''),
        plan_id: tenant.plan_id ?? '',
        status: String(tenant.status ?? ''),
        observations: String(tenant.observations ?? ''),
        automatic_fiscal_emission_enabled: Boolean(tenant.automatic_fiscal_emission_enabled),
    });

    const ctl = useTenantCommercial({
        tenantId: tenant.id,
        commercial,
        plans,
        onErrors: (fieldErrors) => goToErrors(fieldErrors, commercialFieldTabs),
    });

    const handleSubmit = (e?: React.FormEvent) => {
        e?.preventDefault();
        patch(route('admin.tenants.update', tenant.id), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setDefaults();
                toastSuccess('Sucesso', 'Cadastro alterado com sucesso');
            },
            onError: (fieldErrors) => goToErrors(fieldErrors, mainFieldTabs),
        });
    };

    const sendSubscriptionEmail = () => {
        if (!emailScenario) return;
        router.post(
            route('admin.tenants.subscription-email-send', [tenant.id, emailScenario.key]),
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSendingEmail(true),
                onFinish: () => {
                    setSendingEmail(false);
                    setEmailScenario(null);
                },
                onSuccess: () => toastSuccess('Sucesso', 'E-mail enviado com sucesso'),
            },
        );
    };

    const plansLocked = commercial?.billing_mode === 'custom';
    const isCustom = plansLocked;
    // tenant.plan vem com periods.features (a lista de planos do seletor não traz as funcionalidades).
    const currentPlan = (tenant.plan as Plan | null) ?? plans.find((plan) => String(plan.id) === String(tenant.plan_id)) ?? null;
    const features = ((currentPlan?.periods ?? []) as Period[]).flatMap((period) =>
        (period.features ?? []).map((feature: Feature) => ({ ...feature, period: period.name })),
    );
    const mainErrorTabs = errorTabs(errors, mainFieldTabs);
    const commercialErrorTabs = errorTabs(ctl.form.errors, commercialFieldTabs);
    const tabErrors = (key: TenantTab) => (mainErrorTabs[key] ?? 0) + (commercialErrorTabs[key] ?? 0);

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title={`Empresa — ${tenant.company ?? tenant.name}`} />

            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-start sm:justify-between">
                <div className="flex min-w-0 items-start gap-3">
                    <Icon iconNode={Building} className="mt-1 h-7 w-7 shrink-0" />
                    <div className="min-w-0">
                        <h2 className="truncate text-xl font-semibold tracking-tight">{tenant.company || tenant.name}</h2>
                        <div className="mt-1 flex flex-wrap items-center gap-2">
                            <Badge variant="outline" className={subscriptionTone(tenant.subscription_status)}>
                                {commercial.subscription_label}
                            </Badge>
                            <Badge variant="outline" className={isCustom ? customBadgeClass : undefined}>
                                {isCustom
                                    ? `Contrato ${commercial.contract?.contract_number ?? 'personalizado'}`
                                    : `Plano ${tenant.plan?.name ?? '-'}`}
                            </Badge>
                            <span className="text-muted-foreground text-xs">
                                Vence {tenant.expires_at ? moment(tenant.expires_at).format('DD/MM/YYYY') : 'sem data'}
                            </span>
                        </div>
                    </div>
                </div>
                <Button variant="outline" asChild>
                    <Link href={route('admin.tenants.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        Voltar
                    </Link>
                </Button>
            </div>

            <div className="px-4 pb-24">
                <Tabs value={tab} onValueChange={(value) => setTab(value as TenantTab)}>
                    <TabsList className="grid h-auto w-full grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-5">
                        <TabsTrigger className={tabTriggerClass} value="general">
                            Dados gerais <TabBadge count={tabErrors('general')} />
                        </TabsTrigger>
                        <TabsTrigger className={tabTriggerClass} value="subscription">
                            Assinatura e planos <TabBadge count={tabErrors('subscription')} />
                        </TabsTrigger>
                        <TabsTrigger className={tabTriggerClass} value="finance">
                            Financeiro e cobranças
                        </TabsTrigger>
                        <TabsTrigger className={tabTriggerClass} value="modules">
                            Módulos e funcionalidades <TabBadge count={tabErrors('modules')} />
                        </TabsTrigger>
                        <TabsTrigger className={tabTriggerClass} value="fiscal">
                            Fiscal <TabBadge count={tabErrors('fiscal')} />
                        </TabsTrigger>
                    </TabsList>

                    <TabsContent value="general" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                        <Section title="Identificação e contato">
                            <TenantGeneralFields data={data} setData={setData} errors={errors} />
                        </Section>
                        <Section title="Endereço">
                            <TenantAddressFields data={data} setData={setData} errors={errors} />
                        </Section>
                    </TabsContent>

                    <TabsContent value="subscription" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            {[
                                ['Plano atual', tenant.plan?.name ?? 'Sem plano definido'],
                                ['Período atual', tenant.current_plan_period_label ?? 'Sem período definido'],
                                ['Vence em', tenant.expires_at ? moment(tenant.expires_at).format('DD/MM/YYYY') : 'Sem vencimento'],
                                ['Restante', remainingLabel(tenant.expires_at)],
                            ].map(([label, value]) => (
                                <div key={label} className="bg-card rounded-lg border p-3">
                                    <p className="text-muted-foreground text-xs">{label}</p>
                                    <p className="mt-1 font-semibold">{value}</p>
                                </div>
                            ))}
                        </div>
                        <Section title="Plano tradicional">
                            <TenantPlanField
                                plans={plans}
                                value={data.plan_id}
                                onChange={(value) => setData('plan_id', value)}
                                error={errors.plan_id}
                                locked={plansLocked}
                            />
                        </Section>
                        <Section title="Contrato personalizado" description="Somente o RootAdmin altera preço, tipo de contratação e vencimento.">
                            <CommercialContractPanel ctl={ctl} />
                        </Section>
                    </TabsContent>

                    <TabsContent value="finance" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                        <Section title="Cobranças">
                            <CommercialChargesPanel ctl={ctl} />
                        </Section>
                        <Section
                            title="Comunicações de assinatura"
                            description="Prévia abre o modelo sem enviar; Enviar dispara o e-mail real ao cliente."
                        >
                            <div className="divide-y rounded-md border">
                                {emailScenarios.map((scenario) => (
                                    <div key={scenario.key} className="flex flex-wrap items-center justify-between gap-2 p-2">
                                        <span className="text-sm">{scenario.label}</span>
                                        <div className="flex gap-2">
                                            <Button variant="outline" size="sm" asChild>
                                                <a
                                                    href={route('admin.tenants.subscription-email-preview', [tenant.id, scenario.key])}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                >
                                                    Prévia
                                                </a>
                                            </Button>
                                            <Button type="button" size="sm" variant="secondary" onClick={() => setEmailScenario(scenario)}>
                                                Enviar
                                            </Button>
                                        </div>
                                    </div>
                                ))}
                            </div>
                            <Button variant="link" size="sm" className="px-0" asChild>
                                <a href={route('admin.tenants.subscription-invoice-email-preview', tenant.id)} target="_blank" rel="noreferrer">
                                    Prévia do e-mail de fatura paga <ExternalLink className="size-3" />
                                </a>
                            </Button>
                        </Section>
                    </TabsContent>

                    <TabsContent value="modules" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                        <Section title="Situação da conta" description="Ativa, suspende ou pausa o acesso da empresa.">
                            <TenantStatusField value={data.status} onChange={(value) => setData('status', value)} error={errors.status} />
                        </Section>
                        <Section
                            title="Funcionalidades do plano"
                            description={`Definidas pelo plano ${currentPlan?.name ?? '-'}. Alteradas em Planos.`}
                        >
                            {features.length > 0 ? (
                                <ul className="grid gap-1 text-sm sm:grid-cols-2">
                                    {features.map((feature) => (
                                        <li key={feature.id} className="flex items-center gap-2">
                                            <span aria-hidden className="size-1.5 rounded-full bg-emerald-600" />
                                            {feature.name}
                                            <span className="text-muted-foreground text-xs">({feature.period})</span>
                                        </li>
                                    ))}
                                </ul>
                            ) : (
                                <p className="text-muted-foreground text-sm">Nenhuma funcionalidade cadastrada para este plano.</p>
                            )}
                        </Section>
                        <Section
                            title="Módulos ativados pelo cliente"
                            description='Ligados pela própria empresa em "Sistema e módulos" (somente leitura).'
                        >
                            {modules ? (
                                <div className="flex flex-wrap gap-2">
                                    {moduleLabels.map(({ key, label }) => (
                                        <Badge key={key} variant={modules[key] ? 'default' : 'outline'}>
                                            {label}: {modules[key] ? 'ativo' : 'inativo'}
                                        </Badge>
                                    ))}
                                </div>
                            ) : (
                                <p className="text-muted-foreground text-sm">A empresa ainda não abriu as configurações de módulos.</p>
                            )}
                        </Section>
                    </TabsContent>

                    <TabsContent value="fiscal" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                        <Section title="Emissão fiscal da empresa">
                            <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                                <div>
                                    <Label htmlFor="automatic_fiscal_emission_enabled">Emissão fiscal automática</Label>
                                    <p className="text-muted-foreground text-sm">
                                        Libera NF-e, NFC-e e NFS-e; o cliente ainda conclui o cadastro e envia o certificado.
                                    </p>
                                </div>
                                <Switch
                                    id="automatic_fiscal_emission_enabled"
                                    className={switchClass}
                                    checked={data.automatic_fiscal_emission_enabled}
                                    onCheckedChange={(checked) => setData('automatic_fiscal_emission_enabled', checked)}
                                />
                            </div>
                            <Button variant="link" size="sm" className="px-0" asChild>
                                <Link href={route('admin.fiscal.companies.index', { search: tenant.cnpj ?? tenant.company })}>
                                    Modelos, certificado e Spedy em Fiscal → Empresas emissoras <ExternalLink className="size-3" />
                                </Link>
                            </Button>
                        </Section>
                        <Section title="NFS-e do SaaS">
                            <SaasInvoicePanel ctl={ctl} />
                        </Section>
                    </TabsContent>
                </Tabs>
            </div>

            {/* Barra fixa do formulário principal: dados gerais, plano, situação da conta e emissão fiscal. */}
            <div className="bg-background/95 sticky bottom-0 z-10 flex flex-wrap items-center justify-end gap-3 border-t px-4 py-3 backdrop-blur">
                {isDirty && (
                    <span role="status" className="text-xs font-medium text-amber-700 dark:text-amber-400">
                        Alterações não salvas nos dados da empresa
                    </span>
                )}
                <Button type="button" onClick={() => handleSubmit()} disabled={processing}>
                    {processing ? <Loader2 className="size-4 animate-spin" /> : <Save />}
                    Salvar dados da empresa
                </Button>
            </div>

            <CommercialDialogs ctl={ctl} />

            <AlertDialog open={emailScenario !== null} onOpenChange={(value) => !value && !sendingEmail && setEmailScenario(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Enviar e-mail "{emailScenario?.label}"?</AlertDialogTitle>
                        <AlertDialogDescription>
                            O e-mail real será enviado para {tenant.email}. Use "Prévia" para conferir sem enviar.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={sendingEmail}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={sendingEmail}
                            onClick={(e) => {
                                e.preventDefault();
                                sendSubscriptionEmail();
                            }}
                        >
                            {sendingEmail && <Loader2 className="size-4 animate-spin" />}
                            Enviar e-mail
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AdminLayout>
    );
}
