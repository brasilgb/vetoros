import { toastSuccess } from '@/components/app-toast-messages';
import AppearanceTabs from '@/components/appearance-tabs';
import HeadingSmall from '@/components/heading-small';
import { Icon } from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin/admin-layout';
import { formatWhatsappNumber } from '@/pages/site/components/site-contact';
import type { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Cog, Save } from 'lucide-react';

interface Settings {
    id: number;
    name: string;
    logo?: string | null;
    commercial_whatsapp?: string | null;
    commercial_whatsapp_name?: string | null;
    commercial_whatsapp_message?: string | null;
    commercial_whatsapp_public?: boolean;
    commercial_whatsapp_internal?: boolean;
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Configurações',
        href: '#',
    },
];

export default function SettingsIndex({ settings }: { settings: Settings }) {
    const { data, setData, processing, errors } = useForm({
        name: settings?.name,
        logo: null as File | null,
        commercial_whatsapp: settings?.commercial_whatsapp ?? '',
        commercial_whatsapp_name: settings?.commercial_whatsapp_name ?? '',
        commercial_whatsapp_message: settings?.commercial_whatsapp_message ?? '',
        commercial_whatsapp_public: settings?.commercial_whatsapp_public ?? true,
        commercial_whatsapp_internal: settings?.commercial_whatsapp_internal ?? true,
    });

    const handleSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();

        router.post(
            route('admin.settings.update', settings.id),
            {
                _method: 'put',
                name: data?.name,
                logo: data?.logo,
                commercial_whatsapp: data.commercial_whatsapp,
                commercial_whatsapp_name: data.commercial_whatsapp_name,
                commercial_whatsapp_message: data.commercial_whatsapp_message,
                commercial_whatsapp_public: data.commercial_whatsapp_public,
                commercial_whatsapp_internal: data.commercial_whatsapp_internal,
            },
            {
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Cadastro realizado com sucesso');
                },
            },
        );
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Configurações" />
            <div className="flex min-h-16 w-full flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Cog} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Configurações</h2>
                </div>
            </div>

            <div className="w-full p-4">
                <div className="bg-card w-full rounded-2xl border p-5 shadow-sm sm:p-6">
                    <div className="space-y-6">
                        <HeadingSmall title="Configurações de aparência" description="Escolha o tema claro, escuro ou o padrão do dispositivo." />
                        <AppearanceTabs />
                    </div>

                    <form onSubmit={handleSubmit} autoComplete="off" className="mt-8 w-full space-y-8">
                        <div className="space-y-3">
                            <Label>Imagem de logo</Label>
                            <div className="bg-muted/30 flex h-28 w-28 items-center justify-center overflow-hidden rounded-2xl border p-3">
                                <img
                                    src={settings.logo ? `/storage/logos/${settings.logo}` : '/images/default.png'}
                                    alt="Imagem de logo"
                                    className="max-h-full max-w-full object-contain"
                                    onError={(event) => {
                                        event.currentTarget.src = '/images/default.png';
                                    }}
                                />
                            </div>
                        </div>

                        <div className="grid w-full gap-4 xl:grid-cols-2">
                            <div className="grid gap-2 xl:col-span-2">
                                <Label htmlFor="logo">Logotipo</Label>
                                <Input type="file" id="logo" onChange={(e) => setData('logo', e.target.files?.[0] ?? null)} />
                                {errors.logo && <div className="text-sm text-red-500">{errors.logo}</div>}
                            </div>

                            <div className="grid gap-2 xl:max-w-md">
                                <Label htmlFor="name">Nome curto</Label>
                                <Input type="text" id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </div>
                        </div>

                        <section className="space-y-5 rounded-xl border p-5">
                            <HeadingSmall
                                title="Comunicação e WhatsApp"
                                description="Configure o canal comercial oficial usado pelos contatos públicos do VetorOS."
                            />
                            <div className="grid gap-4 md:grid-cols-2">
                                <div className="grid gap-2">
                                    <Label htmlFor="commercial_whatsapp">Número oficial (DDI + DDD + telefone)</Label>
                                    <Input
                                        id="commercial_whatsapp"
                                        inputMode="tel"
                                        placeholder="+55 (51) 99893-1325"
                                        value={formatWhatsappNumber(data.commercial_whatsapp)}
                                        onChange={(event) => setData('commercial_whatsapp', event.target.value)}
                                    />
                                    {errors.commercial_whatsapp && <p className="text-sm text-red-500">{errors.commercial_whatsapp}</p>}
                                    <p className="text-muted-foreground text-xs">
                                        Salvo apenas com dígitos. Deixe vazio para manter o número de compatibilidade atual.
                                    </p>
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="commercial_whatsapp_name">Nome do atendimento</Label>
                                    <Input
                                        id="commercial_whatsapp_name"
                                        value={data.commercial_whatsapp_name}
                                        onChange={(event) => setData('commercial_whatsapp_name', event.target.value)}
                                    />
                                </div>
                                <div className="grid gap-2 md:col-span-2">
                                    <Label htmlFor="commercial_whatsapp_message">Mensagem inicial padrão</Label>
                                    <Input
                                        id="commercial_whatsapp_message"
                                        value={data.commercial_whatsapp_message}
                                        onChange={(event) => setData('commercial_whatsapp_message', event.target.value)}
                                    />
                                </div>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <label className="flex items-start gap-3 text-sm">
                                    <Checkbox
                                        checked={data.commercial_whatsapp_public}
                                        onCheckedChange={(checked) => setData('commercial_whatsapp_public', checked === true)}
                                    />
                                    <span>
                                        <strong className="block">Exibir contato público</strong>
                                        <span className="text-muted-foreground">Mostra o canal nos botões e CTAs comerciais.</span>
                                    </span>
                                </label>
                                <label className="flex items-start gap-3 text-sm">
                                    <Checkbox
                                        checked={data.commercial_whatsapp_internal}
                                        onCheckedChange={(checked) => setData('commercial_whatsapp_internal', checked === true)}
                                    />
                                    <span>
                                        <strong className="block">Disponível para uso interno</strong>
                                        <span className="text-muted-foreground">Deixa o canal acessível às futuras ações internas compatíveis.</span>
                                    </span>
                                </label>
                            </div>
                        </section>

                        <div className="flex w-full justify-end">
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
