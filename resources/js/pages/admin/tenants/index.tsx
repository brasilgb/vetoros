import { SupportAccessDialog } from '@/components/admin/support-access-dialog';
import type { PaginationData } from '@/components/app-pagination';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { maskCnpj, normalizeWhatsappPhone } from '@/Utils/mask';
import { Head, Link, router } from '@inertiajs/react';
import { Building, Eye, Loader2, LogIn, MessageCircle, MoreHorizontal, Plus, Settings2, Trash2 } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Empresas', href: '#' },
];

type Paginated<T> = PaginationData & { data: T[]; total?: number };

type TenantRow = {
    id: number;
    name: string;
    company: string | null;
    cnpj: string | null;
    email: string | null;
    whatsapp: string | null;
    billing_mode: 'plan' | 'custom' | null;
    plan?: { name: string } | null;
    expires_at: string | null;
    status_label: string;
    computed_subscription_bucket: string;
    computed_subscription_label: string;
};

const filterLinks = [
    { label: 'Todas', value: '' },
    { label: 'Ativas', value: 'active' },
    { label: 'Carência', value: 'grace' },
    { label: 'Bloqueadas', value: 'blocked' },
    { label: 'Vence hoje', value: 'expires_today' },
    { label: '7 dias', value: 'expires_next_7_days' },
    { label: 'Sem plano', value: 'without_plan' },
    { label: 'Sem vencimento', value: 'without_expiration' },
];

function situationTone(bucket: string) {
    if (bucket === 'active')
        return 'border-emerald-300 bg-emerald-50 text-emerald-800 dark:border-emerald-800 dark:bg-emerald-950 dark:text-emerald-200';
    if (bucket === 'blocked') return 'border-rose-300 bg-rose-50 text-rose-800 dark:border-rose-800 dark:bg-rose-950 dark:text-rose-200';
    return 'border-amber-300 bg-amber-50 text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-200';
}

function Modality({ tenant }: { tenant: TenantRow }) {
    return tenant.billing_mode === 'custom' ? (
        <Badge variant="outline" className="border-sky-300 bg-sky-50 text-sky-800 dark:border-sky-700 dark:bg-sky-950 dark:text-sky-200">
            Contrato personalizado
        </Badge>
    ) : (
        <Badge variant="outline">{tenant.plan?.name ? `Plano ${tenant.plan.name}` : 'Sem plano'}</Badge>
    );
}

function Situation({ tenant }: { tenant: TenantRow }) {
    return (
        <div className="space-y-0.5">
            <Badge variant="outline" className={situationTone(tenant.computed_subscription_bucket)}>
                {tenant.computed_subscription_label}
            </Badge>
            <p className="text-muted-foreground text-xs">
                {tenant.expires_at ? `Vence ${moment(tenant.expires_at).format('DD/MM/YYYY')} · ${tenant.status_label}` : tenant.status_label}
            </p>
        </div>
    );
}

/** Ações de cada empresa: Gerenciar em destaque; o restante no menu "…". */
function Actions({
    tenant,
    onDelete,
    onSupport,
}: {
    tenant: TenantRow;
    onDelete: (tenant: TenantRow) => void;
    onSupport: (tenant: TenantRow) => void;
}) {
    const name = tenant.company || tenant.name;

    return (
        <div className="flex items-center justify-end gap-1">
            <Button size="sm" variant="ghost" onClick={() => onSupport(tenant)} aria-label={`Acessar painel de ${name}`}>
                <LogIn className="size-4" />
                Acessar painel
            </Button>
            <Button asChild size="sm" variant="outline">
                <Link href={route('admin.tenants.edit', tenant.id)} aria-label={`Gerenciar ${name}`}>
                    <Settings2 className="size-4" />
                    Gerenciar
                </Link>
            </Button>
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size="icon" variant="ghost" aria-label={`Mais ações de ${name}`}>
                        <MoreHorizontal className="size-4" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="end">
                    <DropdownMenuItem asChild disabled={!tenant.whatsapp}>
                        <a
                            target="_blank"
                            rel="noreferrer"
                            href={`https://wa.me/${normalizeWhatsappPhone(tenant.whatsapp ?? '')}?text=Olá, ${tenant.name}`}
                        >
                            <MessageCircle className="size-4" />
                            Enviar WhatsApp
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuItem asChild>
                        <a href={route('admin.tenants.subscription-invoice-email-preview', tenant.id)} target="_blank" rel="noopener noreferrer">
                            <Eye className="size-4" />
                            Prévia do e-mail da fatura
                        </a>
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem variant="destructive" onSelect={() => onDelete(tenant)}>
                        <Trash2 className="size-4" />
                        Excluir empresa
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </div>
    );
}

/** RootAdmin → Empresas (VETOR-UX-ADMIN-03): listagem enxuta; os detalhes ficam na edição. */
export default function TenantsIndex({
    tenants,
    filters,
    environment,
}: {
    tenants: Paginated<TenantRow>;
    filters: { search?: string; subscription_filter?: string; result_count?: number };
    environment: string;
}) {
    const [deleting, setDeleting] = useState<TenantRow | null>(null);
    const [supporting, setSupporting] = useState<TenantRow | null>(null);
    const [processing, setProcessing] = useState(false);
    const rows: TenantRow[] = tenants?.data ?? [];

    const destroy = () => {
        if (!deleting) return;
        router.delete(route('admin.tenants.destroy', deleting.id), {
            preserveScroll: true,
            onStart: () => setProcessing(true),
            onFinish: () => {
                setProcessing(false);
                setDeleting(null);
            },
        });
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Empresas" />
            <SupportAccessDialog tenant={supporting} environment={environment} onClose={() => setSupporting(null)} />
            <div className="flex flex-col gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Building} className="h-7 w-7" />
                    <h2 className="text-xl font-semibold tracking-tight">Empresas</h2>
                </div>
                <Button asChild className="w-full sm:w-auto">
                    <Link href={route('admin.tenants.create')}>
                        <Plus className="h-4 w-4" />
                        Nova empresa
                    </Link>
                </Button>
            </div>

            <div className="space-y-3 px-4">
                <div className="max-w-xl">
                    <InputSearch placeholder="Buscar por empresa, contato, e-mail ou CNPJ" url="admin.tenants.index" />
                </div>
                <nav aria-label="Filtrar por situação" className="flex flex-wrap items-center gap-2">
                    {filterLinks.map((filter) => {
                        const isActive = (filters?.subscription_filter ?? '') === filter.value;
                        return (
                            <Link
                                key={filter.label}
                                aria-current={isActive ? 'page' : undefined}
                                href={route('admin.tenants.index', {
                                    ...(filters?.search ? { search: filters.search } : {}),
                                    ...(filter.value ? { subscription_filter: filter.value } : {}),
                                })}
                            >
                                <Badge
                                    variant="outline"
                                    className={
                                        isActive ? 'border-primary bg-primary/10 text-primary' : 'border-border bg-background text-muted-foreground'
                                    }
                                >
                                    {filter.label}
                                </Badge>
                            </Link>
                        );
                    })}
                    <span className="text-muted-foreground ml-auto text-sm">{filters?.result_count ?? tenants?.total ?? 0} empresa(s)</span>
                </nav>
            </div>

            <div className="p-4">
                {rows.length === 0 ? (
                    <p className="text-muted-foreground rounded-lg border border-dashed p-8 text-center text-sm">
                        {filters?.search || filters?.subscription_filter
                            ? 'Nenhuma empresa encontrada com esses filtros.'
                            : 'Nenhuma empresa cadastrada.'}
                    </p>
                ) : (
                    <>
                        {/* Desktop e tablet */}
                        <div className="hidden rounded-lg border md:block">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Empresa</TableHead>
                                        <TableHead>CNPJ</TableHead>
                                        <TableHead>Modalidade</TableHead>
                                        <TableHead>Situação</TableHead>
                                        <TableHead className="text-right">Ação</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {rows.map((tenant) => (
                                        <TableRow key={tenant.id}>
                                            <TableCell className="max-w-72">
                                                <div className="truncate font-medium">{tenant.company || tenant.name}</div>
                                                <div className="text-muted-foreground truncate text-xs">{tenant.name}</div>
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">{maskCnpj(tenant.cnpj ?? '') || '-'}</TableCell>
                                            <TableCell>
                                                <Modality tenant={tenant} />
                                            </TableCell>
                                            <TableCell>
                                                <Situation tenant={tenant} />
                                            </TableCell>
                                            <TableCell>
                                                <Actions tenant={tenant} onDelete={setDeleting} onSupport={setSupporting} />
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        {/* Celular */}
                        <ul className="space-y-3 md:hidden">
                            {rows.map((tenant) => (
                                <li key={tenant.id} className="bg-card space-y-3 rounded-lg border p-3">
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">{tenant.company || tenant.name}</p>
                                        <p className="text-muted-foreground text-xs">{maskCnpj(tenant.cnpj ?? '') || 'sem CNPJ'}</p>
                                    </div>
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <Situation tenant={tenant} />
                                        <Modality tenant={tenant} />
                                    </div>
                                    <Actions tenant={tenant} onDelete={setDeleting} onSupport={setSupporting} />
                                </li>
                            ))}
                        </ul>
                    </>
                )}
                <div className="mt-4">
                    <AppPagination data={tenants} />
                </div>
            </div>

            <AlertDialog open={deleting !== null} onOpenChange={(value) => !value && !processing && setDeleting(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Excluir {deleting?.company || deleting?.name}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Esta ação não pode ser desfeita. Empresas com contrato comercial e cobranças não são excluídas: nesse caso, desative a
                            empresa.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={processing}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={processing}
                            className="bg-red-600 text-white hover:bg-red-700"
                            onClick={(e) => {
                                e.preventDefault();
                                destroy();
                            }}
                        >
                            {processing && <Loader2 className="size-4 animate-spin" />}
                            Excluir
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AdminLayout>
    );
}
