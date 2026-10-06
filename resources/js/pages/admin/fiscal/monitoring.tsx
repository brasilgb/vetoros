import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import { FiscalHeader, formatDate, MonitoringProps, statusLabels } from './fiscal-tabs';

type Filters = { from: string; to: string; tenant_id: number | null; model: string | null };

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Fiscal', href: route('admin.fiscal.integration') },
    { title: 'Monitoramento', href: '#' },
];

const selectClass = 'border-input bg-background h-9 rounded-md border px-2 text-sm';

export default function FiscalMonitoring(props: MonitoringProps) {
    const { filters, tenants, summary, byTenant, usage, failures, pending, history, webhookEvents } = props;
    const [form, setForm] = useState<Filters>(filters);

    const apply = (e: React.FormEvent) => {
        e.preventDefault();
        router.get(route('admin.fiscal.monitoring'), Object.fromEntries(Object.entries(form).filter(([, value]) => value)), { preserveState: true });
    };

    const cards = [
        ['Total', summary.total],
        ['Autorizadas', summary.authorized],
        ['Rejeitadas/denegadas', summary.rejected],
        ['Falhas', summary.failed],
        ['Canceladas', summary.cancelled],
        ['Pendentes', summary.pending],
    ];

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Fiscal — Monitoramento" />
            <FiscalHeader current="admin.fiscal.monitoring" />

            <div className="space-y-4 p-4">
                <form onSubmit={apply} className="flex flex-wrap items-end gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="from">De</Label>
                        <Input id="from" type="date" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="to">Até</Label>
                        <Input id="to" type="date" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} />
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="tenant">Empresa</Label>
                        <select
                            id="tenant"
                            className={selectClass}
                            value={form.tenant_id ?? ''}
                            onChange={(e) => setForm({ ...form, tenant_id: e.target.value ? Number(e.target.value) : null })}
                        >
                            <option value="">Todas</option>
                            {tenants.map((tenant) => (
                                <option key={tenant.id} value={tenant.id}>
                                    {tenant.company}
                                </option>
                            ))}
                        </select>
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="model">Modelo</Label>
                        <select
                            id="model"
                            className={selectClass}
                            value={form.model ?? ''}
                            onChange={(e) => setForm({ ...form, model: e.target.value || null })}
                        >
                            <option value="">Todos</option>
                            <option value="nfe">NF-e</option>
                            <option value="nfce">NFC-e</option>
                            <option value="nfse">NFS-e</option>
                        </select>
                    </div>
                    <Button type="submit">Filtrar</Button>
                </form>

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    {cards.map(([label, value]) => (
                        <Card key={label as string}>
                            <CardContent className="pt-4">
                                <p className="text-muted-foreground text-xs">{label}</p>
                                <p className="text-2xl font-semibold">{value as number}</p>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Documentos por empresa</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Empresa</TableHead>
                                        <TableHead>Modelo</TableHead>
                                        <TableHead className="text-right">Total</TableHead>
                                        <TableHead className="text-right">Autorizadas</TableHead>
                                        <TableHead className="text-right">Falhas</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {byTenant.map((row) => (
                                        <TableRow key={`${row.tenant_id}-${row.type}`}>
                                            <TableCell>{row.tenant}</TableCell>
                                            <TableCell>{row.type.toUpperCase()}</TableCell>
                                            <TableCell className="text-right">{row.total}</TableCell>
                                            <TableCell className="text-right">{row.authorized}</TableCell>
                                            <TableCell className="text-right">{row.failures}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Utilização mensal</CardTitle>
                            <CardDescription>
                                Notas autorizadas por mês e modelo. Indicador para política comercial futura, sem cobrança.
                            </CardDescription>
                        </CardHeader>
                        <CardContent>
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Mês</TableHead>
                                        <TableHead>Modelo</TableHead>
                                        <TableHead className="text-right">Autorizadas</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {usage.map((row) => (
                                        <TableRow key={`${row.month}-${row.type}`}>
                                            <TableCell>{row.month}</TableCell>
                                            <TableCell>{row.type.toUpperCase()}</TableCell>
                                            <TableCell className="text-right">{row.total}</TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Falhas de integração</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {failures.length === 0 && <p className="text-muted-foreground">Nenhuma falha no período.</p>}
                            {failures.map((row) => (
                                <div key={row.id} className="border-b pb-1">
                                    <div className="flex justify-between">
                                        <span>
                                            #{row.id} {row.type.toUpperCase()} · {row.tenant}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {statusLabels[row.status] ?? row.status} · {formatDate(row.created_at)}
                                        </span>
                                    </div>
                                    {row.error_message && <p className="text-destructive text-xs">{row.error_message}</p>}
                                </div>
                            ))}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Pendentes de reconciliação</CardTitle>
                            <CardDescription>
                                Webhooks no período: {webhookEvents?.total ?? 0} ({webhookEvents?.unprocessed ?? 0} sem processamento).
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-2 text-sm">
                            {pending.length === 0 && <p className="text-muted-foreground">Nada pendente.</p>}
                            {pending.map((row) => (
                                <div key={row.id} className="flex justify-between border-b pb-1">
                                    <span>
                                        #{row.id} {row.type.toUpperCase()} · {row.tenant}
                                    </span>
                                    <span className="text-muted-foreground">
                                        {statusLabels[row.status] ?? row.status} · enviada {formatDate(row.submitted_at)}
                                        {row.confirmed_by_provider ? '' : ' · sem confirmação da Spedy'}
                                    </span>
                                </div>
                            ))}
                        </CardContent>
                    </Card>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Histórico de operações</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-1 text-sm">
                        {history.length === 0 && <p className="text-muted-foreground">Sem registros.</p>}
                        {history.map((row) => (
                            <div key={row.id} className="flex justify-between border-b pb-1">
                                <span>
                                    {row.action}
                                    {row.tenant ? ` · ${row.tenant.company}` : ''}
                                </span>
                                <span className="text-muted-foreground">
                                    {row.user?.name ?? 'Sistema'} · {formatDate(row.created_at)}
                                </span>
                            </div>
                        ))}
                    </CardContent>
                </Card>
            </div>
        </AdminLayout>
    );
}
