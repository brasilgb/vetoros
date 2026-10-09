import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { useState } from 'react';
import { FiscalHeader, formatDate, money, SaasDocument, SaasPayment, SaasProps, SaasReceiver, statusLabels } from './fiscal-tabs';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Fiscal', href: route('admin.fiscal.integration') },
    { title: 'Notas do SaaS', href: '#' },
];

const selectClass = 'border-input bg-background h-9 w-full rounded-md border px-2 text-sm';

const issuerFields: Array<[string, string]> = [
    ['legal_name', 'Razão social'],
    ['trade_name', 'Nome fantasia'],
    ['cnpj', 'CNPJ'],
    ['municipal_registration', 'Inscrição municipal'],
    ['email', 'E-mail'],
    ['zip_code', 'CEP'],
    ['street', 'Logradouro'],
    ['number', 'Número'],
    ['complement', 'Complemento'],
    ['district', 'Bairro'],
    ['city', 'Cidade'],
    ['state', 'UF'],
    ['service_city_code', 'Código IBGE do município'],
    ['service_list_item', 'Item da lista de serviços (LC 116)'],
    ['default_iss_rate', 'Alíquota ISS (%)'],
    ['default_nfse_series', 'Série do RPS/DPS'],
    ['default_service_description', 'Descrição padrão do serviço'],
];

const taxationTypes = [
    ['taxationInMunicipality', 'Tributado no município'],
    ['taxationOutsideMunicipality', 'Tributado fora do município'],
    ['exemption', 'Isento'],
    ['immune', 'Imune'],
    ['suspendedByCourt', 'Suspenso por decisão judicial'],
    ['suspendedByAdministrativeProcedure', 'Suspenso por procedimento administrativo'],
    ['exportation', 'Exportação de serviço'],
    ['nonIncidence', 'Não incidência'],
];

const identityLabels: Record<string, string> = { cnpj: 'CNPJ', legal_name: 'razão social' };

/** Dados do tomador enviados à Spedy, para conferência antes de emitir. */
function ReceiverPreview({ receiver }: { receiver: SaasReceiver }) {
    const address = receiver.address;
    const city = [address?.city?.name, address?.city?.state].filter(Boolean).join('/');

    return (
        <div className="space-y-1 rounded-md border p-3">
            <p className="font-medium">Tomador da nota (como será enviado ao emissor)</p>
            <p>
                {receiver.name || '-'} · CPF/CNPJ {receiver.federal_tax_number || '-'} · {receiver.email || 'sem e-mail'}
            </p>
            <p className="text-muted-foreground">
                {address
                    ? `${address.street ?? ''}, ${address.number ?? 'S/N'} · ${address.district ?? ''} · ${city} · CEP ${address.postalCode ?? '-'}`
                    : 'Endereço não enviado (logradouro ou CEP ausente no cadastro do cliente).'}
            </p>
            {receiver.name_truncated && (
                <p className="text-amber-700 dark:text-amber-400">
                    O nome completo ({receiver.full_name}) passa do limite do emissor e será enviado cortado.
                </p>
            )}
            {receiver.identity_changed_at && (
                <p className="text-amber-700 dark:text-amber-400">
                    {receiver.identity_changes.map((field) => identityLabels[field] ?? field).join(' e ')} alterado(s) em{' '}
                    {formatDate(receiver.identity_changed_at)}
                    {receiver.identity_changed_by ? ` por ${receiver.identity_changed_by}` : ''}. Confira antes de emitir.
                </p>
            )}
        </div>
    );
}

export default function SaasInvoices({ issuer, tenants, selectedTenant, payments, documents }: SaasProps) {
    const { data, setData, put, processing, errors } = useForm({
        ...Object.fromEntries(issuerFields.map(([key]) => [key, issuer[key] ?? ''])),
        enabled: Boolean(issuer.enabled),
        tax_regime: issuer.tax_regime ?? '',
        nfse_taxation_type: issuer.nfse_taxation_type ?? '',
        nfse_mode: issuer.nfse_mode ?? 'national',
        emission_environment: issuer.emission_environment ?? 'homologation',
        tax_settings_confirmed: false,
        production_released: Boolean(issuer.production_released_at),
        password: '',
    });
    const [certificate, setCertificate] = useState<File | null>(null);
    const [certificatePassword, setCertificatePassword] = useState('');
    const [periods, setPeriods] = useState<Record<number, { start: string; end: string }>>({});
    const [emails, setEmails] = useState<Record<number, string>>({});

    const formErrors = errors as Record<string, string>;

    const saveIssuer = (e: React.FormEvent) => {
        e.preventDefault();
        put(route('admin.fiscal.saas.issuer.update'), { preserveScroll: true, onSuccess: () => setData('tax_settings_confirmed', false) });
    };

    const emit = (payment: SaasPayment) => {
        const period = periods[payment.id] ?? { start: payment.suggested_start, end: payment.suggested_end ?? '' };
        const receiver = selectedTenant?.receiver;
        if (
            !window.confirm(
                `Emitir NFS-e de ${money(payment.amount)} para ${receiver?.name || selectedTenant?.name} (CPF/CNPJ ${receiver?.federal_tax_number || '-'}), referência ${period.start} a ${period.end}?`,
            )
        )
            return;
        router.post(
            route('admin.fiscal.saas.emit', payment.id),
            { reference_start: period.start, reference_end: period.end },
            { preserveScroll: true },
        );
    };

    const cancel = (document: SaasDocument) => {
        const reason = window.prompt('Justificativa do cancelamento (15 a 255 caracteres):');
        if (!reason) return;
        const password = window.prompt('Digite sua senha para confirmar o cancelamento:');
        if (!password) return;
        router.post(route('admin.fiscal.saas.cancel', document.id), { reason, password }, { preserveScroll: true });
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal — Notas do SaaS" />
            <FiscalHeader current="admin.fiscal.saas.index" />

            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Emitente da plataforma</CardTitle>
                        <CardDescription>
                            Cadastro fiscal da ABrasil Sistemas, independente dos dados dos clientes.{' '}
                            {issuer.blocker ? (
                                <span className="text-destructive">{issuer.blocker}</span>
                            ) : (
                                <Badge className="bg-emerald-600">Pronto para emitir</Badge>
                            )}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {issuer.problems.length > 0 && (
                            <ul className="list-disc pl-5 text-sm text-amber-700">
                                {issuer.problems.map((problem: string) => (
                                    <li key={problem}>{problem}</li>
                                ))}
                            </ul>
                        )}
                        <form onSubmit={saveIssuer} className="space-y-4" autoComplete="off">
                            <div className="grid gap-3 md:grid-cols-3">
                                {issuerFields.map(([key, label]) => (
                                    <div key={key} className="grid gap-1">
                                        <Label htmlFor={key}>{label}</Label>
                                        <Input
                                            id={key}
                                            value={String((data as Record<string, unknown>)[key] ?? '')}
                                            onChange={(e) => setData(key as keyof typeof data, e.target.value as never)}
                                        />
                                        <InputError message={formErrors[key]} />
                                    </div>
                                ))}
                                <div className="grid gap-1">
                                    <Label htmlFor="tax_regime">Regime tributário</Label>
                                    <select
                                        id="tax_regime"
                                        className={selectClass}
                                        value={String(data.tax_regime ?? '')}
                                        onChange={(e) => setData('tax_regime', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        <option value="1">Simples Nacional</option>
                                        <option value="2">Simples Nacional — excesso de sublimite</option>
                                        <option value="3">Regime Normal</option>
                                        <option value="4">MEI</option>
                                    </select>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="nfse_taxation_type">Tipo de tributação da NFS-e</Label>
                                    <select
                                        id="nfse_taxation_type"
                                        className={selectClass}
                                        value={String(data.nfse_taxation_type ?? '')}
                                        onChange={(e) => setData('nfse_taxation_type', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        {taxationTypes.map(([value, label]) => (
                                            <option key={value} value={value}>
                                                {label}
                                            </option>
                                        ))}
                                    </select>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="emission_environment">Ambiente</Label>
                                    <select
                                        id="emission_environment"
                                        className={selectClass}
                                        value={String(data.emission_environment ?? '')}
                                        onChange={(e) => setData('emission_environment', e.target.value)}
                                    >
                                        <option value="homologation">Homologação / simulação</option>
                                        <option value="production">Produção</option>
                                    </select>
                                </div>
                            </div>
                            <div className="flex flex-wrap items-center gap-6 text-sm">
                                <label className="flex items-center gap-2">
                                    <Switch checked={data.enabled} onCheckedChange={(checked) => setData('enabled', checked)} /> Emissão das notas do
                                    SaaS habilitada
                                </label>
                                <label className="flex items-center gap-2">
                                    <Checkbox
                                        checked={data.tax_settings_confirmed}
                                        onCheckedChange={(checked) => setData('tax_settings_confirmed', checked === true)}
                                    />
                                    Dados tributários validados pela contabilidade
                                    {issuer.tax_settings_confirmed_at ? ` (confirmado em ${formatDate(issuer.tax_settings_confirmed_at)})` : ''}
                                </label>
                                <label className="flex items-center gap-2">
                                    <Switch
                                        checked={data.production_released}
                                        onCheckedChange={(checked) => setData('production_released', checked)}
                                    />{' '}
                                    Produção aprovada
                                </label>
                                {data.production_released && !issuer.production_released_at && (
                                    <div className="grid gap-1">
                                        <Label htmlFor="password">Sua senha (aprovar produção)</Label>
                                        <Input
                                            id="password"
                                            type="password"
                                            value={data.password}
                                            onChange={(e) => setData('password', e.target.value)}
                                        />
                                        <InputError message={formErrors.password} />
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap gap-2">
                                <Button type="submit" disabled={processing}>
                                    Salvar emitente
                                </Button>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => router.post(route('admin.fiscal.saas.issuer.register'), {}, { preserveScroll: true })}
                                >
                                    {issuer.registration_status === 'registered' ? 'Sincronizar na Spedy' : 'Cadastrar na Spedy'}
                                </Button>
                            </div>
                        </form>
                        <div className="flex flex-wrap items-end gap-2 border-t pt-4">
                            <div className="grid gap-1">
                                <Label htmlFor="certificate">Certificado A1 do emitente</Label>
                                <Input
                                    id="certificate"
                                    type="file"
                                    accept=".pfx,.p12"
                                    onChange={(e) => setCertificate(e.target.files?.[0] ?? null)}
                                />
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="certificate_password">Senha do certificado</Label>
                                <Input
                                    id="certificate_password"
                                    type="password"
                                    autoComplete="new-password"
                                    value={certificatePassword}
                                    onChange={(e) => setCertificatePassword(e.target.value)}
                                />
                            </div>
                            <Button
                                variant="outline"
                                disabled={!certificate || !certificatePassword || issuer.registration_status !== 'registered'}
                                onClick={() =>
                                    router.post(
                                        route('admin.fiscal.saas.issuer.certificate'),
                                        { certificate, password: certificatePassword },
                                        { forceFormData: true, preserveScroll: true, onFinish: () => setCertificatePassword('') },
                                    )
                                }
                            >
                                Enviar certificado
                            </Button>
                            <span className="text-muted-foreground text-sm">
                                {issuer.certificate_expires_at
                                    ? `Válido até ${formatDate(issuer.certificate_expires_at)}`
                                    : 'Nenhum certificado enviado'}
                            </span>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Emitir NFS-e para um cliente</CardTitle>
                        <CardDescription>A emissão é sempre manual e usa o valor efetivamente pago, nunca o preço de tabela.</CardDescription>
                        <select
                            className={`${selectClass} max-w-md`}
                            value={selectedTenant?.id ?? ''}
                            onChange={(e) =>
                                router.get(route('admin.fiscal.saas.index'), e.target.value ? { tenant_id: e.target.value } : {}, {
                                    preserveScroll: true,
                                })
                            }
                        >
                            <option value="">Selecione o cliente contratante</option>
                            {tenants.map((tenant) => (
                                <option key={tenant.id} value={tenant.id}>
                                    {tenant.company || tenant.name} {tenant.cnpj ? `— ${tenant.cnpj}` : ''}
                                </option>
                            ))}
                        </select>
                    </CardHeader>
                    {selectedTenant && (
                        <CardContent className="space-y-3 text-sm">
                            <p>
                                Plano atual: {selectedTenant.plan ?? '-'} {selectedTenant.period ? `(${selectedTenant.period})` : ''} · assinatura{' '}
                                {selectedTenant.subscription_status ?? '-'} · vence em {formatDate(selectedTenant.expires_at)}
                            </p>
                            {selectedTenant.receiver_problems.length > 0 && (
                                <p className="text-destructive">{selectedTenant.receiver_problems.join(' ')}</p>
                            )}
                            <ReceiverPreview receiver={selectedTenant.receiver} />
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Pagamento</TableHead>
                                        <TableHead>Plano</TableHead>
                                        <TableHead>Valor cobrado</TableHead>
                                        <TableHead>Período de referência</TableHead>
                                        <TableHead>Notas</TableHead>
                                        <TableHead className="text-right">Ação</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {payments.length === 0 && (
                                        <TableRow>
                                            <TableCell colSpan={6} className="text-muted-foreground text-center">
                                                Nenhum pagamento aprovado.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {payments.map((payment: SaasPayment) => {
                                        const period = periods[payment.id] ?? { start: payment.suggested_start, end: payment.suggested_end ?? '' };
                                        const active = payment.documents.some((doc) =>
                                            ['processing', 'contingency', 'authorized'].includes(doc.status),
                                        );

                                        return (
                                            <TableRow key={payment.id}>
                                                <TableCell>
                                                    #{payment.id} · {formatDate(payment.paid_at)}
                                                </TableCell>
                                                <TableCell>{payment.plan_name ?? '-'}</TableCell>
                                                <TableCell>{money(payment.amount)}</TableCell>
                                                <TableCell>
                                                    <div className="flex gap-1">
                                                        <Input
                                                            type="date"
                                                            value={period.start}
                                                            onChange={(e) =>
                                                                setPeriods({ ...periods, [payment.id]: { ...period, start: e.target.value } })
                                                            }
                                                        />
                                                        <Input
                                                            type="date"
                                                            value={period.end}
                                                            onChange={(e) =>
                                                                setPeriods({ ...periods, [payment.id]: { ...period, end: e.target.value } })
                                                            }
                                                        />
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    {payment.documents.map((doc) => (
                                                        <Badge key={doc.id} variant="secondary" className="mr-1">
                                                            {doc.number ?? `#${doc.id}`} {statusLabels[doc.status] ?? doc.status}
                                                        </Badge>
                                                    ))}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    <Button
                                                        size="sm"
                                                        disabled={active || Boolean(issuer.blocker) || !period.end}
                                                        onClick={() => emit(payment)}
                                                    >
                                                        Emitir NFS-e
                                                    </Button>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })}
                                </TableBody>
                            </Table>
                        </CardContent>
                    )}
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Notas do SaaS emitidas</CardTitle>
                        <CardDescription>
                            Separadas das notas dos clientes.{' '}
                            <Link href={route('admin.fiscal-documents.index')} className="underline">
                                Registros anteriores
                            </Link>
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Nota</TableHead>
                                    <TableHead>Cliente</TableHead>
                                    <TableHead>Valor</TableHead>
                                    <TableHead>Referência</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Envios</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.map((document: SaasDocument) => (
                                    <TableRow key={document.id}>
                                        <TableCell>
                                            {document.number ?? `#${document.id}`}
                                            <div className="text-muted-foreground text-xs">{document.environment}</div>
                                        </TableCell>
                                        <TableCell>{document.tenant}</TableCell>
                                        <TableCell>{money(document.amount)}</TableCell>
                                        <TableCell className="text-xs">
                                            {formatDate(document.reference_start)} a {formatDate(document.reference_end)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant={document.status === 'authorized' ? 'default' : 'secondary'}>
                                                {statusLabels[document.status] ?? document.status}
                                            </Badge>
                                            {document.error_message && <p className="text-destructive max-w-48 text-xs">{document.error_message}</p>}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {document.deliveries.map((delivery, index: number) => (
                                                <p key={index} className={delivery.status === 'sent' ? '' : 'text-destructive'}>
                                                    {delivery.email} · {delivery.status === 'sent' ? 'enviado' : 'falhou'} ·{' '}
                                                    {formatDate(delivery.created_at)}
                                                </p>
                                            ))}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex flex-col items-end gap-1">
                                                {['processing', 'contingency', 'rejected'].includes(document.status) && (
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() =>
                                                            router.post(route('admin.fiscal.saas.refresh', document.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Atualizar situação
                                                    </Button>
                                                )}
                                                {document.has_file && (
                                                    <div className="flex gap-1">
                                                        <Button size="sm" variant="outline" asChild>
                                                            <a
                                                                href={route('admin.fiscal.saas.file', { document: document.id, format: 'pdf' })}
                                                                target="_blank"
                                                                rel="noopener noreferrer"
                                                            >
                                                                PDF
                                                            </a>
                                                        </Button>
                                                        <Button size="sm" variant="outline" asChild>
                                                            <a href={route('admin.fiscal.saas.file', { document: document.id, format: 'xml' })}>
                                                                XML
                                                            </a>
                                                        </Button>
                                                    </div>
                                                )}
                                                {document.status === 'authorized' && (
                                                    <div className="flex gap-1">
                                                        <Input
                                                            className="h-8 w-44"
                                                            type="email"
                                                            placeholder="e-mail do cliente"
                                                            value={emails[document.id] ?? ''}
                                                            onChange={(e) => setEmails({ ...emails, [document.id]: e.target.value })}
                                                        />
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            disabled={!emails[document.id]}
                                                            onClick={() =>
                                                                router.post(
                                                                    route('admin.fiscal.saas.send', document.id),
                                                                    { email: emails[document.id] },
                                                                    { preserveScroll: true },
                                                                )
                                                            }
                                                        >
                                                            Enviar
                                                        </Button>
                                                    </div>
                                                )}
                                                {document.can_cancel && (
                                                    <Button size="sm" variant="ghost" className="text-destructive" onClick={() => cancel(document)}>
                                                        Cancelar nota
                                                    </Button>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
