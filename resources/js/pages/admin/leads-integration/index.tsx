import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AdminLayout from '@/layouts/admin/admin-layout';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { FormEvent } from 'react';

type Settings = {
    mode: 'manual' | 'api';
    endpoint_url: string | null;
    token_configured: boolean;
    api_ready: boolean;
    last_test_at: string | null;
    last_test_ok: boolean | null;
    last_test_error: string | null;
    last_sync_at: string | null;
    last_sync_error: string | null;
};

type Failure = {
    id: number;
    type: string;
    company: string | null;
    attempts: number;
    last_error: string | null;
    occurred_at: string;
};

type Props = {
    settings: Settings;
    counts: { pending: number; failed: number; sent: number };
    failures: Failure[];
    mode_options: { value: string; label: string }[];
};

const formatDate = (value: string | null) => (value ? new Date(value).toLocaleString('pt-BR') : '—');

export default function LeadsIntegrationIndex({ settings, counts, failures }: Props) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const form = useForm({
        mode: settings.mode,
        endpoint_url: settings.endpoint_url ?? '',
        token: '',
        clear_token: false,
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        form.patch(route('admin.leads-integration.update'), {
            preserveScroll: true,
            onSuccess: () => form.setData('token', ''),
        });
    };

    return (
        <AdminLayout breadcrumbs={[{ title: 'Configurações', href: route('admin.settings.index') }, { title: 'Acompanhamento VetorLeads', href: route('admin.leads-integration.index') }]}>
            <Head title="Acompanhamento VetorLeads" />

            <div className="flex flex-col gap-6 p-4">
                <div>
                    <h1 className="text-2xl font-semibold">Acompanhamento VetorLeads</h1>
                    <p className="text-sm text-muted-foreground">
                        No modo API, cadastro, início e prorrogação do teste, assinatura ativada e teste encerrado sem contratação são enviados ao VetorLeads. O envio nunca
                        bloqueia cadastro nem pagamento.
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Conexão</CardTitle>
                        <CardDescription>
                            {settings.mode !== 'api' ? (
                                <Badge variant="outline">Modo manual</Badge>
                            ) : !settings.api_ready ? (
                                <Badge variant="destructive">API incompleta</Badge>
                            ) : settings.last_test_ok === true ? (
                                <Badge variant="secondary">Conexão testada</Badge>
                            ) : (
                                <Badge variant="outline">Não testada: teste a conexão antes de usar</Badge>
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="flex flex-col gap-4">
                        <form onSubmit={submit} className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="mode">Acompanhamento</Label>
                                <select
                                    id="mode"
                                    className="h-9 rounded-md border border-input bg-background px-3 text-sm"
                                    value={form.data.mode}
                                    onChange={(event) => form.setData('mode', event.target.value as 'manual' | 'api')}
                                >
                                    <option value="manual">Manual</option>
                                    <option value="api">API</option>
                                </select>
                                <InputError message={errors.mode ?? form.errors.mode} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="endpoint_url">Endpoint do VetorLeads (HTTPS)</Label>
                                <Input
                                    id="endpoint_url"
                                    value={form.data.endpoint_url}
                                    onChange={(event) => form.setData('endpoint_url', event.target.value)}
                                    placeholder="https://vetorleads.com.br/api/integrations/sales/events"
                                />
                                <InputError message={errors.endpoint_url ?? form.errors.endpoint_url} />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="token">Token {settings.token_configured ? '(já configurado; preencha só para trocar)' : ''}</Label>
                                <Input
                                    id="token"
                                    type="password"
                                    autoComplete="off"
                                    value={form.data.token}
                                    onChange={(event) => form.setData('token', event.target.value)}
                                />
                                <InputError message={form.errors.token} />
                            </div>

                            <div className="flex items-end gap-2">
                                <Button type="submit" disabled={form.processing}>
                                    Salvar
                                </Button>
                                {settings.token_configured && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            router.patch(
                                                route('admin.leads-integration.update'),
                                                { mode: settings.mode, endpoint_url: settings.endpoint_url ?? '', clear_token: true },
                                                { preserveScroll: true },
                                            )
                                        }
                                    >
                                        Remover token
                                    </Button>
                                )}
                            </div>
                        </form>

                        <div className="flex flex-wrap items-center gap-3 border-t pt-4 text-sm">
                            <Button
                                type="button"
                                variant="secondary"
                                disabled={!settings.api_ready}
                                onClick={() => router.post(route('admin.leads-integration.test'), {}, { preserveScroll: true })}
                            >
                                Testar conexão
                            </Button>
                            <span>
                                Último teste: {formatDate(settings.last_test_at)}{' '}
                                {settings.last_test_ok === null ? (
                                    ''
                                ) : settings.last_test_ok ? (
                                    <Badge variant="secondary">ok</Badge>
                                ) : (
                                    <Badge variant="destructive">falhou</Badge>
                                )}
                            </span>
                            {settings.last_test_error && <span className="text-destructive">{settings.last_test_error}</span>}
                            <InputError message={errors.test} />
                        </div>

                        <div className="flex flex-wrap items-center gap-3 text-sm">
                            <span>
                                Última sincronização: {formatDate(settings.last_sync_at)} · Pendentes: {counts.pending} · Com falha: {counts.failed} ·
                                Enviados: {counts.sent}
                            </span>
                            {settings.last_sync_error && <span className="text-destructive">{settings.last_sync_error}</span>}
                            {counts.failed > 0 && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="sm"
                                    disabled={!settings.api_ready}
                                    onClick={() => router.post(route('admin.leads-integration.retry'), {}, { preserveScroll: true })}
                                >
                                    Tentar novamente
                                </Button>
                            )}
                            <InputError message={errors.retry} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Falhas recentes</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {failures.length === 0 ? (
                            <p className="text-sm text-muted-foreground">Nenhuma falha de envio.</p>
                        ) : (
                            <ul className="divide-y text-sm">
                                {failures.map((failure) => (
                                    <li key={failure.id} className="flex flex-col gap-1 py-2">
                                        <span className="font-medium">
                                            {failure.type} · {failure.company ?? 'Conta removida'} · {formatDate(failure.occurred_at)}
                                        </span>
                                        <span className="text-muted-foreground">
                                            Tentativas: {failure.attempts}. {failure.last_error}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
