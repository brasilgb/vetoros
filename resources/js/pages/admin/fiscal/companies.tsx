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
    registration_error?: string | null;
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

export default function FiscalCompanies({
    tenants,
    search,
    platformConfigured,
}: {
    tenants: Paginated<CompanyRow>;
    search: string;
    platformConfigured: boolean;
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
                            dados do cliente.
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
                                    <TableHead>Emissão</TableHead>
                                    <TableHead>Modelos liberados</TableHead>
                                    <TableHead>Cadastro Spedy</TableHead>
                                    <TableHead>Certificado</TableHead>
                                    <TableHead>Ambiente</TableHead>
                                    <TableHead>Pendências</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {tenants.data.map((row: CompanyRow) => {
                                    const pending = row.blockers ? Array.from(new Set(models.map((m) => row.blockers?.[m]).filter(Boolean))) : [];

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
                                                <Badge variant={row.registration_status === 'registered' ? 'default' : 'secondary'}>
                                                    {statusLabels[row.registration_status] ?? row.registration_status}
                                                </Badge>
                                                {row.registration_error && (
                                                    <p className="text-destructive mt-1 max-w-48 text-xs">{row.registration_error}</p>
                                                )}
                                            </TableCell>
                                            <TableCell className="text-xs">{formatDate(row.certificate_expires_at)}</TableCell>
                                            <TableCell className="text-xs">
                                                {row.emission_environment === 'production' ? 'Produção' : 'Homologação'}
                                                <div className="text-muted-foreground">
                                                    {row.production_released_at
                                                        ? `Produção aprovada em ${formatDate(row.production_released_at)}`
                                                        : `Homologação: ${row.homologation_authorized} nota(s) autorizada(s)`}
                                                </div>
                                            </TableCell>
                                            <TableCell className="max-w-64 text-xs">
                                                {pending.length === 0 ? (
                                                    <Badge className="bg-emerald-600">Pronta</Badge>
                                                ) : (
                                                    pending.map((message) => <p key={message as string}>{message}</p>)
                                                )}
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
                                                        {row.registration_status === 'registered' ? 'Sincronizar cadastro' : 'Cadastrar na Spedy'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        disabled={row.registration_status !== 'registered'}
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
