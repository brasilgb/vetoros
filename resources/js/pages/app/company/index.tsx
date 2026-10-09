import { toastWarning } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { BreadcrumbItem } from '@/types';
import { maskCep, maskCnpj, maskPhone, unMask } from '@/Utils/mask';
import { Head, useForm, usePage } from '@inertiajs/react';
import { Building, Save, UploadCloud } from 'lucide-react';
import { DragEvent, useEffect, useRef, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Dados da empresa',
        href: '#',
    },
];

export default function Company({ company }: any) {
    const { auth } = usePage<{ auth?: { permissions?: string[] } }>().props;
    const canManageCompany = auth?.permissions?.includes('company');
    const [isDraggingLogo, setIsDraggingLogo] = useState(false);
    const [logoPreview, setLogoPreview] = useState<string | null>(null);
    const logoInputRef = useRef<HTMLInputElement>(null);
    const { data, setData, processing, errors, post, transform } = useForm({
        shortname: company?.shortname,
        companyname: company?.companyname,
        cnpj: company?.cnpj,
        logo: null as File | null,
        zip_code: company?.zip_code,
        state: company?.state,
        city: company?.city,
        district: company?.district,
        street: company?.street,
        number: company?.number,
        complement: company?.complement,
        telephone: company?.telephone,
        whatsapp: company?.whatsapp,
        site: company?.site,
        email: company?.email,
    });
    const currentLogoSrc = company?.logo ? `/storage/logos/${company.logo}` : '/images/default.png';

    const handleLogoFile = (file?: File) => {
        if (!file) return;

        if (!['image/png', 'image/jpeg', 'image/gif', 'image/webp'].includes(file.type)) {
            toastWarning('Erro', 'Apenas imagens são permitidas para o logotipo.');
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            toastWarning('Erro', 'O logotipo deve ter no máximo 2 MB.');
            return;
        }

        if (logoPreview) {
            URL.revokeObjectURL(logoPreview);
        }

        setData('logo', file);
        setLogoPreview(URL.createObjectURL(file));
    };

    const handleLogoDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDraggingLogo(false);

        if (!canManageCompany) return;

        handleLogoFile(e.dataTransfer.files?.[0]);
    };

    useEffect(() => {
        return () => {
            if (logoPreview) {
                URL.revokeObjectURL(logoPreview);
            }
        };
    }, [logoPreview]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (processing || !canManageCompany) return;

        transform((values) => ({ ...values, _method: 'put' }));
        post(route('app.company.update', company.id), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                setData('logo', null);
                setLogoPreview(null);
                if (logoInputRef.current) logoInputRef.current.value = '';
            },
            onError: (validationErrors) => {
                toastWarning('Revise os dados informados', Object.values(validationErrors).join(' '));
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

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Dados da empresa" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Building} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Dados da empresa</h2>
                </div>
            </div>

            <div className="flex items-center justify-between p-4">
                <div></div>
            </div>

            <div className="p-4">
                <div className="rounded-lg border p-2">
                    <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                        <div className="grid gap-2">
                            <Label htmlFor="logo">Logotipo</Label>
                            <div
                                onDragEnter={(e) => {
                                    e.preventDefault();
                                    if (canManageCompany) setIsDraggingLogo(true);
                                }}
                                onDragOver={(e) => {
                                    e.preventDefault();
                                    if (canManageCompany) setIsDraggingLogo(true);
                                }}
                                onDragLeave={(e) => {
                                    e.preventDefault();
                                    if (e.currentTarget.contains(e.relatedTarget as Node)) return;
                                    setIsDraggingLogo(false);
                                }}
                                onDrop={handleLogoDrop}
                                onClick={() => canManageCompany && logoInputRef.current?.click()}
                                onKeyDown={(e) => {
                                    if (!canManageCompany) return;
                                    if (e.key === 'Enter' || e.key === ' ') {
                                        e.preventDefault();
                                        logoInputRef.current?.click();
                                    }
                                }}
                                role="button"
                                tabIndex={canManageCompany ? 0 : -1}
                                aria-disabled={!canManageCompany}
                                className={cn(
                                    'border-muted-foreground/30 bg-muted/20 flex min-h-36 items-center gap-4 rounded-lg border-2 border-dashed p-4 transition-colors',
                                    canManageCompany ? 'cursor-pointer' : 'cursor-not-allowed opacity-70',
                                    isDraggingLogo && 'border-primary bg-primary/5',
                                )}
                            >
                                <div className="bg-background flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-md border p-2">
                                    <img
                                        src={logoPreview ?? currentLogoSrc}
                                        alt="Imagem de logo"
                                        className="max-h-full max-w-full object-contain"
                                        onError={(event) => {
                                            event.currentTarget.src = '/images/default.png';
                                        }}
                                    />
                                </div>
                                <div className="min-w-0">
                                    <UploadCloud className="text-muted-foreground mb-2 h-6 w-6" />
                                    <p className="text-sm font-medium">Arraste o logotipo aqui ou clique para selecionar</p>
                                    <p className="text-muted-foreground mt-1 text-xs">PNG, JPG, GIF ou WEBP até 2 MB.</p>
                                    {data.logo && <p className="text-muted-foreground mt-2 truncate text-xs">{(data.logo as File).name}</p>}
                                </div>
                                <Input
                                    ref={logoInputRef}
                                    type="file"
                                    id="logo"
                                    accept="image/png,image/jpeg,image/gif,image/webp"
                                    disabled={!canManageCompany}
                                    onChange={(e) => handleLogoFile(e.target.files?.[0])}
                                    className="sr-only"
                                />
                            </div>
                            {errors.logo && <div className="text-sm text-red-500">{errors.logo}</div>}
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="name">CPF/CNPJ</Label>
                                <Input
                                    type="text"
                                    id="cnpj"
                                    value={maskCnpj(data.cnpj)}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('cnpj', e.target.value)}
                                    maxLength={18}
                                />
                                {errors.cnpj && <div className="text-sm text-red-500">{errors.cnpj}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="shortname">Nome curto (para etiqueta)</Label>
                                <Input
                                    type="text"
                                    id="shortname"
                                    value={data.shortname}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('shortname', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="companyname">Razão social</Label>
                                <Input
                                    type="text"
                                    id="companyname"
                                    value={data.companyname}
                                    maxLength={150}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('companyname', e.target.value)}
                                />
                                <p className="text-muted-foreground text-xs">
                                    Igual ao cadastro na Receita (até 150 caracteres). Na emissão automática de notas, o emissor aceita até 80.
                                </p>
                                {errors.companyname && <div className="text-sm text-red-500">{errors.companyname}</div>}
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-6">
                            <div className="grid gap-2">
                                <Label htmlFor="zip_code">CEP</Label>
                                <Input
                                    type="text"
                                    id="zip_code"
                                    value={maskCep(data.zip_code)}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('zip_code', e.target.value)}
                                    onBlur={(e) => getCep(e.target.value)}
                                    maxLength={9}
                                />
                                {errors.zip_code && <div className="text-sm text-red-500">{errors.zip_code}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="state">UF</Label>
                                <Input
                                    type="text"
                                    id="state"
                                    value={data.state}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('state', e.target.value)}
                                />
                                {errors.state && <div>{errors.state}</div>}
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="city">Cidade</Label>
                                <Input
                                    type="text"
                                    id="city"
                                    value={data.city}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('city', e.target.value)}
                                />
                                {errors.city && <div className="text-sm text-red-500">{errors.city}</div>}
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="district">Bairro</Label>
                                <Input
                                    type="text"
                                    id="district"
                                    value={data.district}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('district', e.target.value)}
                                />
                                {errors.district && <div className="text-sm text-red-500">{errors.district}</div>}
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-4">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="street">Logradouro</Label>
                                <Input
                                    type="text"
                                    id="street"
                                    value={data.street}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('street', e.target.value)}
                                />
                                {errors.street && <div className="text-sm text-red-500">{errors.street}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="number">Número</Label>
                                <Input
                                    type="text"
                                    id="number"
                                    value={data.number}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('number', e.target.value)}
                                />
                                {errors.number && <div className="text-sm text-red-500">{errors.number}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="complement">Complemento</Label>
                                <Input
                                    type="text"
                                    id="complement"
                                    value={data.complement}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('complement', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="mt-4 grid gap-4 md:grid-cols-6">
                            <div className="grid gap-2">
                                <Label htmlFor="telephone">Telefone</Label>
                                <Input
                                    type="text"
                                    id="telephone"
                                    value={maskPhone(data.telephone)}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('telephone', e.target.value)}
                                    maxLength={15}
                                />
                                {errors.telephone && <div className="text-sm text-red-500">{errors.telephone}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="whatsapp">Whatsapp</Label>
                                <Input
                                    type="text"
                                    id="whatsapp"
                                    value={maskPhone(data.whatsapp)}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('whatsapp', e.target.value)}
                                    maxLength={15}
                                />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="site">Site</Label>
                                <Input
                                    type="text"
                                    id="site"
                                    value={data.site}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('site', e.target.value)}
                                />
                            </div>

                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="email">E-mail</Label>
                                <Input
                                    type="text"
                                    id="email"
                                    value={data.email}
                                    disabled={!canManageCompany}
                                    onChange={(e) => setData('email', e.target.value)}
                                />
                            </div>
                        </div>

                        <div className="flex justify-end">
                            {canManageCompany && (
                                <Button type="submit" disabled={processing}>
                                    <Save />
                                    Salvar
                                </Button>
                            )}
                        </div>
                    </form>
                </div>
            </div>
        </AppLayout>
    );
}
