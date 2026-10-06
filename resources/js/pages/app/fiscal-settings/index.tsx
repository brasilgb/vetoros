import HeadingSmall from '@/components/heading-small';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { AlertTriangle, FileKey, Landmark, Save, Upload } from 'lucide-react';
import { FormEvent, useState } from 'react';

type Model = 'nfe' | 'nfce' | 'nfse';

type FiscalSettingProps = {
    enabled: boolean;
    registration_status: 'pending' | 'registered' | 'error';
    registration_error?: string | null;
    registered_at?: string | null;
    emission_environment: 'homologation' | 'production';
    certificate_subject?: string | null;
    certificate_expires_at?: string | null;
    company_tax_regime?: string | null;
    state_registration?: string | null;
    municipal_registration?: string | null;
    service_city_code?: string | null;
    service_list_item?: string | null;
    default_iss_rate?: number | null;
    nfe_enabled: boolean;
    nfce_enabled: boolean;
    nfse_enabled: boolean;
    nfse_mode: 'national' | 'municipal';
    default_nfe_series?: string | null;
    default_nfce_series?: string | null;
    default_nfse_series?: string | null;
    nfce_csc_id?: string | null;
    nfce_csc_set: boolean;
    nfe_allowed: boolean;
    nfce_allowed: boolean;
    nfse_allowed: boolean;
    production_released_at?: string | null;
    default_commercial_unit?: string | null;
    default_icms_origin?: string | null;
    default_icms_situation?: string | null;
    default_pis_situation?: string | null;
    default_cofins_situation?: string | null;
    nfse_taxation_type?: string | null;
    tax_settings_confirmed_at?: string | null;
};

type Props = {
    platformAvailable: boolean;
    tenantAllowed: boolean;
    setting: FiscalSettingProps;
    blockers: Record<Model, string | null>;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Painel', href: route('app.dashboard') },
    { title: 'Sistema e módulos', href: route('app.other-settings.index') },
    { title: 'Emissão fiscal', href: '#' },
];

const modelLabels: Record<Model, string> = {
    nfe: 'NF-e (vendas com cliente identificado)',
    nfce: 'NFC-e (PDV / consumidor final)',
    nfse: 'NFS-e (serviços das ordens)',
};

const nfseTaxationTypes = [
    { value: 'taxationInMunicipality', label: 'Tributado no município' },
    { value: 'taxationOutsideMunicipality', label: 'Tributado fora do município' },
    { value: 'exemption', label: 'Isento' },
    { value: 'immune', label: 'Imune' },
    { value: 'suspendedByCourt', label: 'Suspenso por decisão judicial' },
    { value: 'suspendedByAdministrativeProcedure', label: 'Suspenso por procedimento administrativo' },
    { value: 'exportation', label: 'Exportação de serviço' },
    { value: 'nonIncidence', label: 'Não incidência' },
];

const taxRegimes = [
    { value: '1', label: 'Simples Nacional' },
    { value: '2', label: 'Simples Nacional — excesso de sublimite' },
    { value: '3', label: 'Regime Normal' },
    { value: '4', label: 'MEI' },
];

function formatDate(value?: string | null) {
    return value ? new Date(value).toLocaleDateString('pt-BR') : '-';
}

export default function FiscalSettings({ platformAvailable, tenantAllowed, setting, blockers }: Props) {
    const available = platformAvailable && tenantAllowed;
    const registered = setting.registration_status === 'registered';
    const certificateExpiresSoon =
        setting.certificate_expires_at && new Date(setting.certificate_expires_at).getTime() - Date.now() < 30 * 24 * 60 * 60 * 1000;

    const { data, setData, put, processing, errors } = useForm({
        company_tax_regime: setting.company_tax_regime ?? '',
        state_registration: setting.state_registration ?? '',
        municipal_registration: setting.municipal_registration ?? '',
        service_city_code: setting.service_city_code ?? '',
        service_list_item: setting.service_list_item ?? '',
        default_iss_rate: setting.default_iss_rate ?? '',
        emission_environment: setting.emission_environment ?? 'homologation',
        nfe_enabled: setting.nfe_enabled,
        nfce_enabled: setting.nfce_enabled,
        nfse_enabled: setting.nfse_enabled,
        nfse_mode: setting.nfse_mode ?? 'national',
        default_nfe_series: setting.default_nfe_series ?? '',
        default_nfce_series: setting.default_nfce_series ?? '',
        default_nfse_series: setting.default_nfse_series ?? '',
        nfce_csc_id: setting.nfce_csc_id ?? '',
        nfce_csc: '',
        // Sem valores tributários pré-preenchidos: devem vir da contabilidade.
        default_commercial_unit: setting.default_commercial_unit ?? '',
        default_icms_origin: setting.default_icms_origin ?? '',
        default_icms_situation: setting.default_icms_situation ?? '',
        default_pis_situation: setting.default_pis_situation ?? '',
        default_cofins_situation: setting.default_cofins_situation ?? '',
        nfse_taxation_type: setting.nfse_taxation_type ?? '',
        tax_settings_confirmed: false,
    });

    const [certificate, setCertificate] = useState<File | null>(null);
    const [certificatePassword, setCertificatePassword] = useState('');
    const [sending, setSending] = useState(false);

    const submit = (e: FormEvent) => {
        e.preventDefault();

        if (data.emission_environment === 'production' && setting.emission_environment !== 'production') {
            const confirmed = window.confirm('Em produção as notas têm validade fiscal. Confirma a mudança para produção?');
            if (!confirmed) return;
        }

        put(route('app.fiscal-settings.update'), {
            preserveScroll: true,
            onSuccess: () => setData((current) => ({ ...current, nfce_csc: '', tax_settings_confirmed: false })),
        });
    };

    const uploadCertificate = (e: FormEvent) => {
        e.preventDefault();
        if (!certificate) return;

        setSending(true);
        router.post(
            route('app.fiscal-settings.certificate'),
            { certificate, password: certificatePassword },
            {
                forceFormData: true,
                preserveScroll: true,
                onFinish: () => {
                    setSending(false);
                    setCertificatePassword('');
                },
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Emissão fiscal" />
            <div className="flex min-h-16 w-full flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Landmark} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Emissão fiscal</h2>
                </div>
            </div>

            <div className="w-full space-y-4 p-4">
                {!available && (
                    <Alert>
                        <AlertTriangle className="h-4 w-4" />
                        <AlertTitle>Emissão automática indisponível</AlertTitle>
                        <AlertDescription>
                            {platformAvailable
                                ? 'A emissão automática de notas ainda não foi liberada para esta conta. Fale com o suporte. O registro manual de notas continua disponível.'
                                : 'A emissão automática de notas ainda não está disponível. O registro manual de notas continua disponível.'}
                        </AlertDescription>
                    </Alert>
                )}

                <div className="bg-card space-y-6 rounded-2xl border p-5 shadow-sm sm:p-6">
                    <HeadingSmall
                        title="Situação da emissão"
                        description="As notas são emitidas pelo próprio sistema. Os dados da empresa (CNPJ, razão social e endereço) vêm de Dados da empresa."
                    />

                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="rounded-2xl border p-4">
                            <p className="text-muted-foreground text-sm">Empresa emissora</p>
                            <div className="mt-1 flex items-center gap-2">
                                {registered ? (
                                    <Badge className="bg-emerald-600">Cadastrada</Badge>
                                ) : setting.registration_status === 'error' ? (
                                    <Badge variant="destructive">Erro no cadastro</Badge>
                                ) : (
                                    <Badge variant="secondary">Pendente</Badge>
                                )}
                            </div>
                            {setting.registration_error && <p className="text-destructive mt-2 text-sm">{setting.registration_error}</p>}
                            {!registered && available && (
                                <p className="mt-3 text-sm">O cadastro na emissora é feito pela administração depois de validar seus dados.</p>
                            )}
                            <p className="text-muted-foreground mt-2 text-xs">
                                Mantenha atualizados os{' '}
                                <Link href={route('app.company.index')} className="underline">
                                    dados da empresa
                                </Link>{' '}
                                e o regime tributário abaixo.
                            </p>
                        </div>

                        <div className="rounded-2xl border p-4">
                            <p className="text-muted-foreground text-sm">Certificado digital A1</p>
                            <p className="mt-1 font-medium">
                                {setting.certificate_expires_at ? `Válido até ${formatDate(setting.certificate_expires_at)}` : 'Não enviado'}
                            </p>
                            {setting.certificate_subject && (
                                <p className="text-muted-foreground mt-1 truncate text-xs">{setting.certificate_subject}</p>
                            )}
                            {certificateExpiresSoon && <p className="mt-2 text-sm text-amber-600">O certificado vence em menos de 30 dias.</p>}
                        </div>

                        <div className="rounded-2xl border p-4">
                            <p className="text-muted-foreground text-sm">Ambiente</p>
                            <p className="mt-1 font-medium">
                                {setting.emission_environment === 'production'
                                    ? 'Produção (com validade fiscal)'
                                    : 'Homologação (sem validade fiscal)'}
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-2">
                        {(Object.keys(modelLabels) as Model[]).map((model) => (
                            <div key={model} className="flex flex-wrap items-center justify-between gap-2 rounded-xl border px-4 py-2 text-sm">
                                <span>{modelLabels[model]}</span>
                                {blockers[model] ? (
                                    <span className="text-muted-foreground">{blockers[model]}</span>
                                ) : (
                                    <Badge className="bg-emerald-600">Pronta para emitir</Badge>
                                )}
                            </div>
                        ))}
                    </div>
                </div>

                {registered && (
                    <form onSubmit={uploadCertificate} className="bg-card space-y-4 rounded-2xl border p-5 shadow-sm sm:p-6">
                        <HeadingSmall
                            title="Enviar certificado digital"
                            description="Arquivo .pfx/.p12 do certificado A1 da empresa. O arquivo e a senha são repassados ao emissor e não ficam armazenados no sistema."
                        />
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="certificate">Arquivo do certificado</Label>
                                <Input
                                    id="certificate"
                                    type="file"
                                    accept=".pfx,.p12"
                                    onChange={(e) => setCertificate(e.target.files?.[0] ?? null)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="certificate_password">Senha do certificado</Label>
                                <Input
                                    id="certificate_password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={certificatePassword}
                                    onChange={(e) => setCertificatePassword(e.target.value)}
                                />
                            </div>
                            <div className="flex items-end">
                                <Button type="submit" disabled={!certificate || !certificatePassword || sending}>
                                    <Upload className="h-4 w-4" />
                                    Enviar certificado
                                </Button>
                            </div>
                        </div>
                    </form>
                )}

                <form onSubmit={submit} autoComplete="off" className="bg-card space-y-8 rounded-2xl border p-5 shadow-sm sm:p-6">
                    <div className="space-y-4">
                        <HeadingSmall title="Dados tributários" description="Confirme estes dados com a contabilidade da empresa." />
                        <div className="grid gap-4 md:grid-cols-3">
                            <div className="grid gap-2">
                                <Label htmlFor="company_tax_regime">Regime tributário</Label>
                                <Select value={data.company_tax_regime} onValueChange={(value) => setData('company_tax_regime', value)}>
                                    <SelectTrigger id="company_tax_regime">
                                        <SelectValue placeholder="Selecione" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {taxRegimes.map((regime) => (
                                            <SelectItem key={regime.value} value={regime.value}>
                                                {regime.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.company_tax_regime} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="state_registration">Inscrição estadual</Label>
                                <Input
                                    id="state_registration"
                                    value={data.state_registration}
                                    onChange={(e) => setData('state_registration', e.target.value)}
                                />
                                <InputError message={errors.state_registration} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="municipal_registration">Inscrição municipal</Label>
                                <Input
                                    id="municipal_registration"
                                    value={data.municipal_registration}
                                    onChange={(e) => setData('municipal_registration', e.target.value)}
                                />
                                <InputError message={errors.municipal_registration} />
                            </div>
                        </div>
                        <div className="grid gap-2 md:max-w-sm">
                            <Label htmlFor="emission_environment">Ambiente de emissão</Label>
                            <Select
                                value={data.emission_environment}
                                onValueChange={(value) => setData('emission_environment', value as 'homologation' | 'production')}
                            >
                                <SelectTrigger id="emission_environment">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="homologation">Homologação (testes, sem validade fiscal)</SelectItem>
                                    <SelectItem value="production" disabled={!setting.production_released_at}>
                                        Produção (notas com validade fiscal)
                                        {setting.production_released_at ? '' : ' — aguarda aprovação da administração'}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={errors.emission_environment} />
                        </div>
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall
                            title="Produtos (NF-e e NFC-e)"
                            description="Valores padrão aplicados aos itens. NCM e CFOP vêm do cadastro de cada peça/produto."
                        />
                        <div className="grid gap-4 md:grid-cols-2">
                            <ToggleCard
                                id="nfe_enabled"
                                title="NF-e"
                                description="Vendas para cliente identificado (CPF/CNPJ e endereço)."
                                checked={data.nfe_enabled}
                                onChange={(checked) => setData('nfe_enabled', checked)}
                            />
                            <ToggleCard
                                id="nfce_enabled"
                                title="NFC-e"
                                description="Cupom fiscal eletrônico do PDV para consumidor final."
                                checked={data.nfce_enabled}
                                onChange={(checked) => setData('nfce_enabled', checked)}
                            />
                        </div>
                        <div className="grid gap-4 md:grid-cols-4">
                            <Field
                                id="default_nfe_series"
                                label="Série da NF-e"
                                value={data.default_nfe_series}
                                error={errors.default_nfe_series}
                                onChange={(v) => setData('default_nfe_series', v)}
                            />
                            <Field
                                id="default_nfce_series"
                                label="Série da NFC-e"
                                value={data.default_nfce_series}
                                error={errors.default_nfce_series}
                                onChange={(v) => setData('default_nfce_series', v)}
                            />
                            <Field
                                id="nfce_csc_id"
                                label="ID do CSC (NFC-e)"
                                value={data.nfce_csc_id}
                                error={errors.nfce_csc_id}
                                onChange={(v) => setData('nfce_csc_id', v)}
                            />
                            <div className="grid gap-2">
                                <Label htmlFor="nfce_csc">CSC (NFC-e)</Label>
                                <Input
                                    id="nfce_csc"
                                    type="password"
                                    autoComplete="new-password"
                                    placeholder={setting.nfce_csc_set ? 'Configurado — preencha para trocar' : 'Obtido na SEFAZ'}
                                    value={data.nfce_csc}
                                    onChange={(e) => setData('nfce_csc', e.target.value)}
                                />
                                <InputError message={errors.nfce_csc} />
                            </div>
                        </div>
                        <div className="grid gap-4 md:grid-cols-5">
                            <Field
                                id="default_commercial_unit"
                                label="Unidade"
                                value={data.default_commercial_unit}
                                error={errors.default_commercial_unit}
                                onChange={(v) => setData('default_commercial_unit', v)}
                            />
                            <Field
                                id="default_icms_origin"
                                label="Origem ICMS"
                                value={data.default_icms_origin}
                                error={errors.default_icms_origin}
                                onChange={(v) => setData('default_icms_origin', v)}
                            />
                            <Field
                                id="default_icms_situation"
                                label="CSOSN / CST ICMS"
                                value={data.default_icms_situation}
                                error={errors.default_icms_situation}
                                onChange={(v) => setData('default_icms_situation', v)}
                            />
                            <Field
                                id="default_pis_situation"
                                label="CST PIS"
                                value={data.default_pis_situation}
                                error={errors.default_pis_situation}
                                onChange={(v) => setData('default_pis_situation', v)}
                            />
                            <Field
                                id="default_cofins_situation"
                                label="CST COFINS"
                                value={data.default_cofins_situation}
                                error={errors.default_cofins_situation}
                                onChange={(v) => setData('default_cofins_situation', v)}
                            />
                        </div>
                    </div>

                    <div className="space-y-4">
                        <HeadingSmall title="Serviços (NFS-e)" description="Emitida a partir dos serviços lançados na ordem de serviço." />
                        <ToggleCard
                            id="nfse_enabled"
                            title="NFS-e"
                            description="Nota de serviço para as ordens de serviço."
                            checked={data.nfse_enabled}
                            onChange={(checked) => setData('nfse_enabled', checked)}
                        />
                        <div className="grid gap-4 md:grid-cols-4">
                            <Field
                                id="service_city_code"
                                label="Código IBGE do município"
                                value={data.service_city_code}
                                error={errors.service_city_code}
                                onChange={(v) => setData('service_city_code', v)}
                            />
                            <Field
                                id="service_list_item"
                                label="Item da lista de serviços (LC 116)"
                                value={data.service_list_item}
                                error={errors.service_list_item}
                                onChange={(v) => setData('service_list_item', v)}
                            />
                            <Field
                                id="default_iss_rate"
                                label="Alíquota ISS (%)"
                                type="number"
                                value={String(data.default_iss_rate ?? '')}
                                error={errors.default_iss_rate}
                                onChange={(v) => setData('default_iss_rate', v)}
                            />
                            <Field
                                id="default_nfse_series"
                                label="Série do RPS/DPS"
                                value={data.default_nfse_series}
                                error={errors.default_nfse_series}
                                onChange={(v) => setData('default_nfse_series', v)}
                            />
                        </div>
                        <div className="grid gap-2 md:max-w-sm">
                            <Label htmlFor="nfse_taxation_type">Tipo de tributação da NFS-e</Label>
                            <Select value={data.nfse_taxation_type} onValueChange={(value) => setData('nfse_taxation_type', value)}>
                                <SelectTrigger id="nfse_taxation_type">
                                    <SelectValue placeholder="Selecione" />
                                </SelectTrigger>
                                <SelectContent>
                                    {nfseTaxationTypes.map((type) => (
                                        <SelectItem key={type.value} value={type.value}>
                                            {type.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={errors.nfse_taxation_type} />
                        </div>
                        <div className="grid gap-2 md:max-w-sm">
                            <Label htmlFor="nfse_mode">Padrão da NFS-e</Label>
                            <Select value={data.nfse_mode} onValueChange={(value) => setData('nfse_mode', value as 'national' | 'municipal')}>
                                <SelectTrigger id="nfse_mode">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="national">Ambiente Nacional</SelectItem>
                                    <SelectItem value="municipal">Provedor do município</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>

                    <div className="flex items-start gap-3 rounded-2xl border p-4">
                        <Checkbox
                            id="tax_settings_confirmed"
                            checked={data.tax_settings_confirmed}
                            onCheckedChange={(checked) => setData('tax_settings_confirmed', checked === true)}
                        />
                        <div className="grid gap-1">
                            <Label htmlFor="tax_settings_confirmed">Confirmo que os dados tributários acima foram validados pela contabilidade</Label>
                            <p className="text-muted-foreground text-sm">
                                {setting.tax_settings_confirmed_at
                                    ? `Última confirmação em ${formatDate(setting.tax_settings_confirmed_at)}. Alterar dados tributários exige nova confirmação.`
                                    : 'Sem esta confirmação a emissão automática fica bloqueada. O sistema não presume CFOP, CST, alíquotas nem tipo de tributação.'}
                            </p>
                        </div>
                    </div>

                    <div className="flex justify-end">
                        <Button type="submit" disabled={processing}>
                            <Save className="h-4 w-4" />
                            Salvar configurações
                        </Button>
                    </div>
                </form>

                <p className="text-muted-foreground flex items-center gap-2 text-xs">
                    <FileKey className="h-4 w-4" />
                    Credenciais de emissão, CSC e certificado nunca são exibidos depois de salvos.
                </p>
            </div>
        </AppLayout>
    );
}

function ToggleCard({
    id,
    title,
    description,
    checked,
    onChange,
}: {
    id: string;
    title: string;
    description: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <div className="bg-card text-card-foreground flex items-center justify-between gap-4 rounded-2xl border p-4 shadow-sm">
            <div>
                <Label htmlFor={id} className="font-medium">
                    {title}
                </Label>
                <p className="text-muted-foreground text-sm">{description}</p>
            </div>
            <Switch id={id} checked={checked} onCheckedChange={onChange} />
        </div>
    );
}

function Field({
    id,
    label,
    value,
    error,
    onChange,
    type = 'text',
}: {
    id: string;
    label: string;
    value: string;
    error?: string;
    onChange: (value: string) => void;
    type?: string;
}) {
    return (
        <div className="grid gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input id={id} type={type} step={type === 'number' ? '0.01' : undefined} value={value} onChange={(e) => onChange(e.target.value)} />
            <InputError message={error} />
        </div>
    );
}
