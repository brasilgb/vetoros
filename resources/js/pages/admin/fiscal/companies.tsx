import AppPagination from '@/components/app-pagination';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { FiscalHeader, formatDate, Paginated, statusLabels } from './fiscal-tabs';

type Model = 'nfe' | 'nfce' | 'nfse';

type CompanyRow = {
    id: number;
    name: string;
    cnpj: string;
    emission_enabled: boolean;
    allowed: Record<Model, boolean>;
    tenant_enabled: Record<Model, boolean>;
    registration_status: string;
    registered_on_spedy: boolean;
    spedy_company_id?: string | null;
    spedy_environment?: string | null;
    environment_mismatch: boolean;
    last_synced_at?: string | null;
    registration_error?: string | null;
    certificate_subject?: string | null;
    certificate_expires_at?: string | null;
    emission_environment: string;
    production_released_at?: string | null;
    tax_settings_confirmed_at?: string | null;
    blockers: Record<Model, string | null> | null;
    homologation_authorized: number;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Fiscal', href: route('admin.fiscal.integration') },
    { title: 'Empresas emissoras', href: '#' },
];

const models: Model[] = ['nfe', 'nfce', 'nfse'];

const environmentLabels: Record<string, string> = { sandbox: 'Sandbox', production: 'Produção' };

/** Estado real de cada modelo: liberação do RootAdmin não significa empresa pronta para emitir. */
function modelState(row: CompanyRow, model: Model): { label: string; ready: boolean } {
    if (!row.emission_enabled) return { label: 'Fiscal não habilitado', ready: false };
    if (!row.allowed[model]) return { label: 'Não liberada', ready: false };
    const blocker = row.blockers?.[model];
    if (blocker) return { label: blocker, ready: false };
    return row.emission_environment === 'production' && row.production_released_at
        ? { label: 'Apta à emissão em produção', ready: true }
        : { label: 'Pronta para homologação', ready: true };
}

function certificateLabel(row: CompanyRow) {
    if (!row.certificate_expires_at) return 'Sem certificado confirmado';
    const expired = new Date(row.certificate_expires_at).getTime() <= Date.now();
    return `${expired ? 'Vencido em' : 'Vigente até'} ${formatDate(row.certificate_expires_at)}`;
}

export default function FiscalCompanies({
    tenants,
    search,
    platformConfigured,
    platformEnvironment,
}: {
    tenants: Paginated<CompanyRow>;
    search: string;
    platformConfigured: boolean;
    platformEnvironment: string;
}) {
    const [term, setTerm] = useState(search ?? '');

    const save = (row: CompanyRow, changes: Partial<Record<string, boolean>>, password?: string) => {
        router.put(
            route('admin.fiscal.companies.update', row.id),
            {
                emission_enabled: row.emission_enabled,
                nfe_allowed: row.allowed.nfe,
                nfce_allowed: row.allowed.nfce,
                nfse_allowed: row.allowed.nfse,
                production_released: Boolean(row.production_released_at),
                ...changes,
                password,
            },
            { preserveScroll: true },
        );
    };

    const releaseProduction = (row: CompanyRow) => {
        const password = window.prompt(
            `Aprovar a emissão em PRODUÇÃO para ${row.name}?\nNotas autorizadas em homologação: ${row.homologation_authorized}.\nDigite sua senha para confirmar:`,
        );
        if (password) save(row, { production_released: true }, password);
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal — Empresas emissoras" />
            <FiscalHeader current="admin.fiscal.companies.index" />

            <div className="space-y-4 p-4">
                {!platformConfigured && (
                    <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">
                        A integração Spedy não está configurada: nenhuma empresa pode emitir.
                    </p>
                )}
                <Card>
                    <CardHeader>
                        <CardTitle>Empresas emissoras</CardTitle>
                        <CardDescription>
                            A emissão fica desabilitada até a liberação aqui. O cadastro na Spedy só é feito por esta tela, depois de validados os
                            dados do cliente. "Fiscal habilitado" apenas libera a conta; a empresa só fica pronta para emitir depois do cadastro
                            confirmado na Spedy, do certificado vigente e das configurações de cada modelo. Ambiente da plataforma:{' '}
                            <strong>{environmentLabels[platformEnvironment] ?? platformEnvironment}</strong>.
                        </CardDescription>
                        <form
                            className="flex max-w-md gap-2 pt-2"
                            onSubmit={(e) => {
                                e.preventDefault();
                                router.get(route('admin.fiscal.companies.index'), { search: term }, { preserveState: true });
                            }}
                        >
                            <Input placeholder="Buscar por nome ou CNPJ" value={term} onChange={(e) => setTerm(e.target.value)} />
                            <Button type="submit" variant="outline">
                                Buscar
                            </Button>
                        </form>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Empresa</TableHead>
                                    <TableHead>Fiscal habilitado</TableHead>
                                    <TableHead>Modelos liberados</TableHead>
                                    <TableHead>Cadastro Spedy</TableHead>
                                    <TableHead>Certificado</TableHead>
                                    <TableHead>Ambiente</TableHead>
                                    <TableHead>Pronta para emitir</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenants.data.map((row: CompanyRow) => {
                                    return (
                                        <TableRow key={row.id}>
                                            <TableCell>
                                                <div className="font-medium">{row.name}</div>
                                                <div className="text-muted-foreground text-xs">{row.cnpj || 'sem CNPJ'}</div>
                                            </TableCell>
                                            <TableCell>
                                                <Switch
                                                    checked={row.emission_enabled}
                                                    onCheckedChange={(checked) => save(row, { emission_enabled: checked })}
                                                />
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col gap-1">
                                                    {models.map((model) => (
                                                        <label key={model} className="flex items-center gap-2 text-xs">
                                                            <Switch
                                                                checked={row.allowed[model]}
                                                                onCheckedChange={(checked) => save(row, { [`${model}_allowed`]: checked })}
                                                            />
                                                            {model.toUpperCase()}
                                                            {row.tenant_enabled[model] ? '' : ' (desativado pelo cliente)'}
                                                        </label>
                                                    ))}
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                {row.registered_on_spedy ? (
                                                    <Badge className="bg-emerald-600">Cadastrada</Badge>
                                                ) : row.environment_mismatch ? (
                                                    <Badge variant="destructive">Vínculo de outro ambiente</Badge>
                                                ) : (
                                                    <Badge variant={row.registration_status === 'error' ? 'destructive' : 'secondary'}>
                                                        {row.registration_status === 'error'
                                                            ? 'Erro no cadastro'
                                                            : (statusLabels[row.registration_status] ?? row.registration_status)}
                                                    </Badge>
                                                )}
                                                {row.spedy_company_id && (
                                                    <p className="text-muted-foreground mt-1 max-w-48 truncate text-xs" title={row.spedy_company_id}>
                                                        ID {row.spedy_company_id}
                                                        {row.spedy_environment
                                                            ? ` (${environmentLabels[row.spedy_environment] ?? row.spedy_environment})`
                                                            : ''}
                                                    </p>
                                                )}
                                                <p className="text-muted-foreground text-xs">
                                                    Última sincronização: {formatDate(row.last_synced_at)}
                                                </p>
                                                {row.registration_error && (
                                                    <p className="text-destructive mt-1 max-w-48 text-xs">{row.registration_error}</p>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {certificateLabel(row)}
                                                {row.certificate_subject && (
                                                    <div className="text-muted-foreground max-w-48 truncate" title={row.certificate_subject}>
                                                        {row.certificate_subject}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs">
                                                {row.emission_environment === 'production' ? 'Produção' : 'Homologação'}
                                                <div className="text-muted-foreground">
                                                    {row.production_released_at
                                                        ? `Produção aprovada em ${formatDate(row.production_released_at)}`
                                                        : `Homologação: ${row.homologation_authorized} nota(s) autorizada(s)`}
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-64 text-xs">
                                                {models.map((model) => {
                                                    const state = modelState(row, model);

                                                    return (
                                                        <p key={model} className={state.ready ? 'text-emerald-700 dark:text-emerald-400' : ''}>
                                                            <strong>{model.toUpperCase()}:</strong> {state.label}
                                                        </p>
                                                    );
                                                })}
                                            </TableCell>
                                            <TableCell>
                                                <div className="flex flex-col items-end gap-1">
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={!row.emission_enabled || !platformConfigured}
                                                        onClick={() =>
                                                            router.post(
                                                                route('admin.fiscal.companies.register', row.id),
                                                                {},
                                                                { preserveScroll: true },
                                                            )
                                                        }
                                                    >
                                                        {row.registered_on_spedy ? 'Sincronizar' : 'Cadastrar na Spedy'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={!row.registered_on_spedy}
                                                        onClick={() =>
                                                            router.post(route('admin.fiscal.companies.check', row.id), {}, { preserveScroll: true })
                                                        }
                                                    >
                                                        Consultar cadastro e certificado
                                                    </Button>
                                                    {row.production_released_at ? (
                                                        <Button size="sm" variant="ghost" onClick={() => save(row, { production_released: false })}>
                                                            Revogar produção
                                                        </Button>
                                                    ) : (
                                                        <Button size="sm" variant="ghost" onClick={() => releaseProduction(row)}>
                                                            Aprovar produção
                                                        </Button>
                                                    )}
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })}
                            </TableBody>
                        </Table>
                        <AppPagination data={tenants} />
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
