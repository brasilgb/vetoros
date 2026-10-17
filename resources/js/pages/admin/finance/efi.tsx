import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin/admin-layout';
import { formatDate, money } from '@/pages/admin/fiscal/fiscal-tabs';
import { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';

type Settings = {
    blocked_reason: string | null;
    environment: 'homologation' | 'production';
    charges_configured: boolean;
    pix_credentials_configured: boolean;
    pix_certificate_configured: boolean;
    pix_certificate_subject: string | null;
    pix_certificate_expires_at: string | null;
    pix_key: string | null;
    payee_code: string | null;
    account_reference: string | null;
    methods: string[];
    homologated_at: string | null;
    last_connection_test_at: string | null;
    last_connection_ok: boolean | null;
    last_connection_message: string | null;
    pix_webhook_registered_at: string | null;
    last_pix_notification_at: string | null;
    last_charges_notification_at: string | null;
};

type PaymentRow = {
    id: number;
    tenant: string | null;
    method: string;
    status: string;
    amount: string;
    reference: string | null;
    created_at: string | null;
    paid_at: string | null;
    error: string | null;
    can_refund: boolean;
};

type Props = {
    settings: Settings;
    method_options: { value: string; label: string }[];
    errors_log: { at: string | null; source: string; message: string | null }[];
    legacy: {
        gateways: string[];
        total: number;
        groups: Record<string, { count: number; amount: string }>;
        pending_valid: number;
        pending_expired: number;
    };
    payments: PaymentRow[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Financeiro · Efí Bank', href: '#' },
];

const dateTime = (value: string | null) => (value ? new Date(value).toLocaleString('pt-BR') : '-');
const configured = (ok: boolean) => (ok ? <Badge variant="secondary">configurado</Badge> : <Badge variant="outline">não configurado</Badge>);
const methodLabels: Record<string, string> = { pix: 'Pix', billet: 'Boleto/Bolix', card: 'Cartão', card_subscription: 'Cartão recorrente' };
const statusLabels: Record<string, string> = {
    pending: 'Pendente',
    paid: 'Paga',
    refunded: 'Estornada',
    cancelled: 'Cancelada',
    expired: 'Expirada',
    failed: 'Recusada',
};

const legacyGroupLabels: Record<string, string> = {
    quitados: 'Quitados',
    pendentes: 'Pendentes',
    estornados: 'Estornados',
    contestados: 'Contestados',
    cancelados_ou_recusados: 'Cancelados ou recusados',
    outros: 'Outros',
};

export default function EfiSettings({ settings, method_options, errors_log, legacy, payments }: Props) {
    const form = useForm({
        environment: settings.environment,
        charges_client_id: '',
        charges_client_secret: '',
        pix_client_id: '',
        pix_client_secret: '',
        pix_key: settings.pix_key ?? '',
        payee_code: settings.payee_code ?? '',
        account_reference: settings.account_reference ?? '',
        methods: settings.methods,
        password: '',
    });
    const certificate = useForm<{ certificate: File | null; certificate_password: string; password: string }>({
        certificate: null,
        certificate_password: '',
        password: '',
    });
    const homologation = useForm({ action: settings.homologated_at ? 'suspend' : 'homologate', confirm: false, password: '' });
    const [refundPassword, setRefundPassword] = useState('');

    const toggleMethod = (method: string, checked: boolean) =>
        form.setData('methods', checked ? [...form.data.methods, method] : form.data.methods.filter((item) => item !== method));

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('admin.finance.efi.update'), {
            preserveScroll: true,
            onSuccess: () => form.reset('charges_client_id', 'charges_client_secret', 'pix_client_id', 'pix_client_secret', 'password'),
        });
    };

    const efiActive = Boolean(settings.homologated_at);

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Financeiro — Efí Bank" />

            <div className="grid gap-4 p-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Recebimento das assinaturas SaaS</CardTitle>
                        <CardDescription>
                            Só as cobranças que os tenants pagam pelo VetorOS. Nenhuma chamada à Efí é feita ao abrir esta tela.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>
                            Novas cobranças:{' '}
                            <Badge variant={settings.blocked_reason ? 'destructive' : 'default'}>
                                {settings.blocked_reason ? 'bloqueadas' : 'liberadas'}
                            </Badge>
                        </p>
                        {settings.blocked_reason && <p className="text-xs text-rose-600">{settings.blocked_reason}</p>}
                        <p>
                            Ambiente Efí: <Badge variant="outline">{settings.environment === 'production' ? 'produção' : 'homologação'}</Badge>
                        </p>
                        <p>Homologada em: {dateTime(settings.homologated_at)}</p>
                        <p>API Cobranças: {configured(settings.charges_configured)}</p>
                        <p>
                            API Pix: {configured(settings.pix_credentials_configured)} · certificado {configured(settings.pix_certificate_configured)}
                            {settings.pix_certificate_expires_at && ` (vence em ${formatDate(settings.pix_certificate_expires_at)})`}
                        </p>
                        <p>Conta recebedora: {settings.account_reference || '-'}</p>
                        <p>
                            Teste de conexão: {dateTime(settings.last_connection_test_at)}{' '}
                            {settings.last_connection_ok === true && <Badge variant="secondary">ok</Badge>}
                            {settings.last_connection_ok === false && <Badge variant="destructive">falhou</Badge>}
                        </p>
                        {settings.last_connection_message && <p className="text-muted-foreground text-xs">{settings.last_connection_message}</p>}
                        <p>Webhook Pix cadastrado: {dateTime(settings.pix_webhook_registered_at)}</p>
                        <p>Última notificação Pix: {dateTime(settings.last_pix_notification_at)}</p>
                        <p>Última notificação Cobranças: {dateTime(settings.last_charges_notification_at)}</p>
                        <div className="flex flex-wrap gap-2 pt-2">
                            <Button variant="outline" onClick={() => router.post(route('admin.finance.efi.test'), {}, { preserveScroll: true })}>
                                Testar conexão
                            </Button>
                            <Button variant="outline" onClick={() => router.post(route('admin.finance.efi.webhook'), {}, { preserveScroll: true })}>
                                Cadastrar webhook Pix
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Homologação</CardTitle>
                        <CardDescription>
                            A Efí é o único gateway. Sem homologação, novas cobranças externas ficam bloqueadas; nenhum outro provedor é usado.
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <form
                            className="space-y-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                homologation.post(route('admin.finance.efi.homologation'), {
                                    preserveScroll: true,
                                    onSuccess: () => homologation.reset('password', 'confirm'),
                                });
                            }}
                        >
                            <p className="font-medium">
                                {efiActive ? 'Suspender novas cobranças pela Efí' : 'Concluir a homologação e liberar as cobranças'}
                            </p>
                            <label className="flex items-center gap-2">
                                <Checkbox
                                    checked={homologation.data.confirm}
                                    onCheckedChange={(value) => homologation.setData('confirm', value === true)}
                                />
                                {efiActive ? 'Confirmo a suspensão das novas cobranças' : 'Confirmo que a homologação foi concluída'}
                            </label>
                            <Input
                                type="password"
                                placeholder="Sua senha"
                                value={homologation.data.password}
                                onChange={(e) => homologation.setData('password', e.target.value)}
                            />
                            <InputError message={homologation.errors.action ?? homologation.errors.confirm ?? homologation.errors.password} />
                            <Button type="submit" variant={efiActive ? 'destructive' : 'default'} disabled={homologation.processing}>
                                {efiActive ? 'Suspender cobranças' : 'Homologar e liberar'}
                            </Button>
                        </form>

                        <div className="border-t pt-3">
                            <p className="font-medium">Histórico de provedor anterior (somente leitura)</p>
                            <p className="text-muted-foreground text-xs">
                                {legacy.total === 0 ? 'Nenhum registro.' : ` registro(s) preservados (). Não são recriados nem alterados.`}
                            </p>
                            {Object.entries(legacy.groups)
                                .filter(([, group]) => group.count > 0)
                                .map(([name, group]) => (
                                    <p key={name}>
                                        {legacyGroupLabels[name] ?? name}: {group.count} · {money(group.amount)}
                                    </p>
                                ))}
                            {legacy.pending_valid > 0 && (
                                <p className="text-xs text-rose-600">
                                    {legacy.pending_valid} pendente(s) ainda válido(s): concilie antes da implantação
                                    (vetoros:legacy-payments-report).
                                </p>
                            )}
                        </div>
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>Credenciais e meios de pagamento</CardTitle>
                        <CardDescription>
                            Use uma aplicação Efí exclusiva do VetorOS na conta existente; não altere as aplicações das outras operações. Credenciais
                            são somente escrita: deixe em branco para manter.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="environment">Ambiente</Label>
                                <select
                                    id="environment"
                                    className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                    value={form.data.environment}
                                    onChange={(e) => form.setData('environment', e.target.value as Settings['environment'])}
                                >
                                    <option value="homologation">Homologação</option>
                                    <option value="production">Produção</option>
                                </select>
                                <p className="text-muted-foreground text-xs">Trocar ambiente ou credencial exige nova homologação.</p>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="account_reference">Referência da conta recebedora</Label>
                                <Input
                                    id="account_reference"
                                    value={form.data.account_reference}
                                    onChange={(e) => form.setData('account_reference', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="charges_client_id">API Cobranças · Client ID</Label>
                                <Input
                                    id="charges_client_id"
                                    autoComplete="off"
                                    value={form.data.charges_client_id}
                                    onChange={(e) => form.setData('charges_client_id', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="charges_client_secret">API Cobranças · Client Secret</Label>
                                <Input
                                    id="charges_client_secret"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.charges_client_secret}
                                    onChange={(e) => form.setData('charges_client_secret', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="pix_client_id">API Pix · Client ID</Label>
                                <Input
                                    id="pix_client_id"
                                    autoComplete="off"
                                    value={form.data.pix_client_id}
                                    onChange={(e) => form.setData('pix_client_id', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="pix_client_secret">API Pix · Client Secret</Label>
                                <Input
                                    id="pix_client_secret"
                                    type="password"
                                    autoComplete="new-password"
                                    value={form.data.pix_client_secret}
                                    onChange={(e) => form.setData('pix_client_secret', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="pix_key">Chave Pix recebedora</Label>
                                <Input id="pix_key" value={form.data.pix_key} onChange={(e) => form.setData('pix_key', e.target.value)} />
                                <InputError message={form.errors.pix_key} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="payee_code">Identificador de conta (tokenização do cartão)</Label>
                                <Input id="payee_code" value={form.data.payee_code} onChange={(e) => form.setData('payee_code', e.target.value)} />
                                <InputError message={form.errors.payee_code} />
                            </div>
                            <div className="grid gap-2 md:col-span-2">
                                <Label>Meios de pagamento habilitados</Label>
                                <div className="flex flex-wrap gap-4">
                                    {method_options.map((option) => (
                                        <label key={option.value} className="flex items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={form.data.methods.includes(option.value)}
                                                onCheckedChange={(value) => toggleMethod(option.value, value === true)}
                                            />
                                            {option.label}
                                        </label>
                                    ))}
                                </div>
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Sua senha</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    value={form.data.password}
                                    onChange={(e) => form.setData('password', e.target.value)}
                                />
                                <InputError message={form.errors.password} />
                            </div>
                            <div className="flex items-end justify-end">
                                <Button type="submit" disabled={form.processing}>
                                    Salvar configuração
                                </Button>
                            </div>
                        </form>

                        <form
                            className="mt-6 grid gap-4 border-t pt-4 md:grid-cols-4"
                            onSubmit={(e) => {
                                e.preventDefault();
                                certificate.post(route('admin.finance.efi.certificate'), {
                                    preserveScroll: true,
                                    forceFormData: true,
                                    onSuccess: () => certificate.reset(),
                                });
                            }}
                        >
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="certificate">Certificado da API Pix (.p12 ou .pem)</Label>
                                <Input
                                    id="certificate"
                                    type="file"
                                    accept=".p12,.pem"
                                    onChange={(e) => certificate.setData('certificate', e.target.files?.[0] ?? null)}
                                />
                                <InputError message={certificate.errors.certificate} />
                                {settings.pix_certificate_subject && (
                                    <p className="text-muted-foreground text-xs">Atual: {settings.pix_certificate_subject}</p>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="certificate_password">Senha do .p12 (se houver)</Label>
                                <Input
                                    id="certificate_password"
                                    type="password"
                                    value={certificate.data.certificate_password}
                                    onChange={(e) => certificate.setData('certificate_password', e.target.value)}
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="certificate_admin_password">Sua senha</Label>
                                <Input
                                    id="certificate_admin_password"
                                    type="password"
                                    value={certificate.data.password}
                                    onChange={(e) => certificate.setData('password', e.target.value)}
                                />
                                <InputError message={certificate.errors.password} />
                            </div>
                            <div className="flex justify-end md:col-span-4">
                                <Button type="submit" variant="outline" disabled={certificate.processing || !certificate.data.certificate}>
                                    Enviar certificado
                                </Button>
                            </div>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Últimos erros</CardTitle>
                        <CardDescription>Sem credenciais, tokens ou dados de cartão.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {errors_log.length === 0 && <p className="text-muted-foreground">Nenhum erro registrado.</p>}
                        {errors_log.map((item, index) => (
                            <div key={index} className="border-b pb-2">
                                <p className="text-muted-foreground text-xs">
                                    {dateTime(item.at)} · {item.source}
                                </p>
                                <p>{item.message}</p>
                            </div>
                        ))}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Últimas transações Efí</CardTitle>
                        <CardDescription>Conciliar consulta a Efí; devolução só para Pix recebido em divergência.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {payments.length === 0 && <p className="text-muted-foreground">Nenhuma transação.</p>}
                        {payments.length > 0 && (
                            <Input
                                type="password"
                                placeholder="Sua senha (para devolução)"
                                value={refundPassword}
                                onChange={(e) => setRefundPassword(e.target.value)}
                            />
                        )}
                        {payments.map((payment) => (
                            <div key={payment.id} className="flex flex-col gap-1 border-b pb-2 md:flex-row md:items-center md:justify-between">
                                <div>
                                    <p>
                                        {payment.tenant} · {methodLabels[payment.method] ?? payment.method} · {money(payment.amount)} ·{' '}
                                        {statusLabels[payment.status] ?? payment.status}
                                    </p>
                                    <p className="text-muted-foreground text-xs">
                                        {payment.reference} · criada {dateTime(payment.created_at)}
                                        {payment.paid_at && ` · paga ${dateTime(payment.paid_at)}`}
                                    </p>
                                    {payment.error && <p className="text-xs text-rose-600">{payment.error}</p>}
                                </div>
                                <div className="flex gap-1">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            router.post(route('admin.finance.efi.payments.reconcile', payment.id), {}, { preserveScroll: true })
                                        }
                                    >
                                        Conciliar
                                    </Button>
                                    {payment.can_refund && (
                                        <Button
                                            size="sm"
                                            variant="destructive"
                                            onClick={() =>
                                                router.post(
                                                    route('admin.finance.efi.payments.refund', payment.id),
                                                    { password: refundPassword },
                                                    { preserveScroll: true },
                                                )
                                            }
                                        >
                                            Devolver Pix
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
