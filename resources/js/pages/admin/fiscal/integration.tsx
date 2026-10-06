import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FiscalHeader, formatDate } from './fiscal-tabs';

type Integration = {
    environment: 'sandbox' | 'production';
    environment_source: string | null;
    owner_key_configured: boolean;
    owner_key_source: string | null;
    owner_key_last4: string | null;
    owner_key_rotated_at: string | null;
    webhook_secret_configured: boolean;
    webhook_secret_source: string | null;
    webhook_url: string | null;
    webhook_expected_url: string;
    webhook_configured_at: string | null;
    last_diagnostic_at: string | null;
    last_diagnostic_ok: boolean | null;
    last_diagnostic_message: string | null;
    registered_companies: number;
};

type Audit = { id: number; action: string; data?: Record<string, unknown> | null; created_at: string; user?: { name: string } | null };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Fiscal', href: '#' },
];

const sourceLabel = (source: string | null) =>
    source === 'database' ? 'painel' : source === 'environment' ? 'variável de ambiente' : 'não configurado';

export default function FiscalIntegration({ integration, audits }: { integration: Integration; audits: Audit[] }) {
    const { data, setData, put, processing, errors, reset } = useForm({
        environment: integration.environment,
        owner_api_key: '',
        password: '',
        confirm_environment_change: false,
    });
    const [webhookPassword, setWebhookPassword] = useState('');

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.fiscal.integration.update'), { preserveScroll: true, onSuccess: () => reset('owner_api_key', 'password') });
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal — Integração Spedy" />
            <FiscalHeader current="admin.fiscal.integration" />

            <div className="grid gap-4 p-4 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle>Estado da integração</CardTitle>
                        <CardDescription>Nenhuma chamada à Spedy é feita ao abrir esta tela.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>
                            Ambiente:{' '}
                            <Badge variant={integration.environment === 'production' ? 'default' : 'secondary'}>{integration.environment}</Badge>{' '}
                            <span className="text-muted-foreground">({sourceLabel(integration.environment_source)})</span>
                        </p>
                        <p>
                            Chave titular:{' '}
                            {integration.owner_key_configured ? (
                                <span>
                                    configurada{integration.owner_key_last4 ? ` (final ••••${integration.owner_key_last4})` : ''} —{' '}
                                    {sourceLabel(integration.owner_key_source)}
                                </span>
                            ) : (
                                <span className="text-destructive">não configurada</span>
                            )}
                        </p>
                        <p>Última troca da chave: {formatDate(integration.owner_key_rotated_at)}</p>
                        <p>Empresas cadastradas na Spedy: {integration.registered_companies}</p>
                        <p>
                            Último diagnóstico: {formatDate(integration.last_diagnostic_at)}{' '}
                            {integration.last_diagnostic_ok === null ? null : integration.last_diagnostic_ok ? (
                                <Badge className="bg-emerald-600">ok</Badge>
                            ) : (
                                <Badge variant="destructive">falhou</Badge>
                            )}
                        </p>
                        {integration.last_diagnostic_message && <p className="text-muted-foreground">{integration.last_diagnostic_message}</p>}
                        <Button
                            variant="outline"
                            disabled={!integration.owner_key_configured}
                            onClick={() => router.post(route('admin.fiscal.integration.diagnose'), {}, { preserveScroll: true })}
                        >
                            Testar conexão (sem emissão)
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Credencial e ambiente</CardTitle>
                        <CardDescription>A chave é somente escrita: nunca é exibida depois de salva. Exige sua senha.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form onSubmit={submit} className="space-y-4" autoComplete="off">
                            <div className="grid gap-2">
                                <Label htmlFor="environment">Ambiente da plataforma</Label>
                                <Select value={data.environment} onValueChange={(value) => setData('environment', value as 'sandbox' | 'production')}>
                                    <SelectTrigger id="environment">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="sandbox">Sandbox (conta de testes)</SelectItem>
                                        <SelectItem value="production">Produção</SelectItem>
                                    </SelectContent>
                                </Select>
                                <InputError message={errors.environment} />
                            </div>
                            {data.environment !== integration.environment && integration.registered_companies > 0 && (
                                <div className="flex items-start gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                                    <Checkbox
                                        id="confirm_environment_change"
                                        checked={data.confirm_environment_change}
                                        onCheckedChange={(checked) => setData('confirm_environment_change', checked === true)}
                                    />
                                    <Label htmlFor="confirm_environment_change">
                                        Entendo que os cadastros de empresas do ambiente atual serão descartados e precisarão ser refeitos.
                                    </Label>
                                </div>
                            )}
                            <InputError message={errors.confirm_environment_change} />
                            <div className="grid gap-2">
                                <Label htmlFor="owner_api_key">Nova chave da empresa titular</Label>
                                <Input
                                    id="owner_api_key"
                                    type="password"
                                    autoComplete="new-password"
                                    placeholder="Deixe em branco para manter a atual"
                                    value={data.owner_api_key}
                                    onChange={(e) => setData('owner_api_key', e.target.value)}
                                />
                                <InputError message={errors.owner_api_key} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="password">Sua senha</Label>
                                <Input
                                    id="password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={data.password}
                                    onChange={(e) => setData('password', e.target.value)}
                                />
                                <InputError message={errors.password} />
                            </div>
                            <Button type="submit" disabled={processing}>
                                Salvar
                            </Button>
                        </form>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Webhook</CardTitle>
                        <CardDescription>Evento invoice.status_changed para todas as empresas da conta.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        <p>URL esperada: {integration.webhook_expected_url}</p>
                        <p>URL configurada: {integration.webhook_url ?? '-'}</p>
                        <p>
                            Segredo de assinatura:{' '}
                            {integration.webhook_secret_configured
                                ? `configurado (${sourceLabel(integration.webhook_secret_source)})`
                                : 'não configurado'}
                        </p>
                        <p>Configurado em: {formatDate(integration.webhook_configured_at)}</p>
                        <div className="flex flex-wrap items-end gap-2">
                            <div className="grid gap-1">
                                <Label htmlFor="webhook_password">Sua senha</Label>
                                <Input
                                    id="webhook_password"
                                    type="password"
                                    autoComplete="current-password"
                                    value={webhookPassword}
                                    onChange={(e) => setWebhookPassword(e.target.value)}
                                />
                            </div>
                            <Button
                                variant="outline"
                                disabled={!integration.owner_key_configured || !webhookPassword}
                                onClick={() =>
                                    router.post(
                                        route('admin.fiscal.integration.webhook'),
                                        { password: webhookPassword },
                                        { preserveScroll: true, onFinish: () => setWebhookPassword('') },
                                    )
                                }
                            >
                                Configurar e verificar webhook
                            </Button>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Histórico</CardTitle>
                        <CardDescription>Alterações críticas da integração.</CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-2 text-sm">
                        {audits.length === 0 && <p className="text-muted-foreground">Sem registros.</p>}
                        {audits.map((audit) => (
                            <div key={audit.id} className="flex justify-between gap-2 border-b pb-1">
                                <span>{audit.action}</span>
                                <span className="text-muted-foreground">
                                    {audit.user?.name ?? 'Sistema'} · {formatDate(audit.created_at)}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
