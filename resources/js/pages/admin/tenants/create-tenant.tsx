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
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Building, Loader2, Save } from 'lucide-react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Empresas', href: route('admin.tenants.index') },
    { title: 'Adicionar', href: '#' },
];

const fieldTabs: Record<string, TenantTab> = {
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
};

/**
 * Cadastro da empresa no RootAdmin (VETOR-UX-ADMIN-03), na mesma estrutura de abas da edição. Financeiro e
 * Fiscal dependem da empresa já criada: ficam indicados e liberados na edição.
 */
export default function CreateTenant({ plans }: { plans: { id: number; name: string }[] }) {
    const { tab, setTab, errorTabs, goToErrors } = useTenantTabs('general');
    const { data, setData, post, processing, reset, errors } = useForm<TenantFormData>({
        cnpj: '',
        name: '',
        company: '',
        email: '',
        phone: '',
        whatsapp: '',
        zip_code: '',
        state: '',
        city: '',
        district: '',
        street: '',
        complement: '',
        number: '',
        plan_id: '',
        status: '',
        observations: '',
    });

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        post(route('admin.tenants.store'), {
            onSuccess: () => {
                toastSuccess('Sucesso', 'Cadastro realizado com sucesso');
                reset();
            },
            onError: (fieldErrors) => goToErrors(fieldErrors, fieldTabs),
        });
    };

    const counts = errorTabs(errors, fieldTabs);

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Nova empresa" />
            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Building} className="h-7 w-7" />
                    <h2 className="text-xl font-semibold tracking-tight">Nova empresa</h2>
                </div>
                <Button variant="outline" asChild>
                    <Link href={route('admin.tenants.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        Voltar
                    </Link>
                </Button>
            </div>

            <form onSubmit={handleSubmit} autoComplete="off">
                <div className="px-4 pb-24">
                    <Tabs value={tab} onValueChange={(value) => setTab(value as TenantTab)}>
                        <TabsList className="grid h-auto w-full grid-cols-2 gap-1 sm:grid-cols-3 lg:grid-cols-5">
                            <TabsTrigger className={tabTriggerClass} value="general">
                                Dados gerais <TabBadge count={counts.general} />
                            </TabsTrigger>
                            <TabsTrigger className={tabTriggerClass} value="subscription">
                                Assinatura e planos <TabBadge count={counts.subscription} />
                            </TabsTrigger>
                            <TabsTrigger className={tabTriggerClass} value="finance" disabled title="Disponível depois do cadastro">
                                Financeiro e cobranças
                            </TabsTrigger>
                            <TabsTrigger className={tabTriggerClass} value="modules">
                                Módulos e funcionalidades <TabBadge count={counts.modules} />
                            </TabsTrigger>
                            <TabsTrigger className={tabTriggerClass} value="fiscal" disabled title="Disponível depois do cadastro">
                                Fiscal
                            </TabsTrigger>
                        </TabsList>

                        <TabsContent value="general" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                            <section className="bg-card space-y-4 rounded-lg border p-4">
                                <h3 className="font-semibold">Identificação e contato</h3>
                                <TenantGeneralFields data={data} setData={setData} errors={errors} />
                            </section>
                            <section className="bg-card space-y-4 rounded-lg border p-4">
                                <h3 className="font-semibold">Endereço</h3>
                                <TenantAddressFields data={data} setData={setData} errors={errors} />
                            </section>
                        </TabsContent>

                        <TabsContent value="subscription" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                            <section className="bg-card space-y-4 rounded-lg border p-4">
                                <h3 className="font-semibold">Plano tradicional</h3>
                                <TenantPlanField
                                    plans={plans}
                                    value={data.plan_id}
                                    onChange={(value) => setData('plan_id', value)}
                                    error={errors.plan_id}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Contrato personalizado, cobranças e NFS-e do SaaS são configurados na edição, depois do cadastro.
                                </p>
                            </section>
                        </TabsContent>

                        <TabsContent value="modules" forceMount className="mt-4 space-y-4 data-[state=inactive]:hidden">
                            <section className="bg-card space-y-4 rounded-lg border p-4">
                                <h3 className="font-semibold">Situação da conta</h3>
                                <TenantStatusField value={data.status} onChange={(value) => setData('status', value)} error={errors.status} />
                            </section>
                        </TabsContent>
                    </Tabs>
                </div>

                <div className="bg-background/95 sticky bottom-0 z-10 flex justify-end border-t px-4 py-3 backdrop-blur">
                    <Button type="submit" disabled={processing}>
                        {processing ? <Loader2 className="size-4 animate-spin" /> : <Save />}
                        Cadastrar empresa
                    </Button>
                </div>
            </form>
        </AdminLayout>
    );
}
