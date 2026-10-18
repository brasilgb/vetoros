import AppPagination from '@/components/app-pagination';
import { toastSuccess, toastWarning } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import { SlaTooltip } from '@/components/sla-tooltip';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronRight, Lightbulb } from 'lucide-react';
import { Fragment, useEffect, useState, type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Ajustes/Avaliações',
        href: route('admin.tenant-improvement-requests.index'),
    },
    {
        title: 'Ajustes e solicitações',
        href: route('admin.tenant-improvement-requests.index'),
    },
];

function statusLabel(status: string) {
    if (status === 'reviewing') return 'Avaliando';
    if (status === 'adjusting') return 'Ajustando';
    if (status === 'done') return 'Concluído';
    return 'Novo';
}

function typeLabel(type: string) {
    return type === 'adjustment' ? 'Ajuste' : 'Melhoria';
}

function statusBadgeClass(status: string) {
    if (status === 'reviewing') return 'border-amber-200 bg-amber-50 text-amber-700';
    if (status === 'adjusting') return 'border-sky-200 bg-sky-50 text-sky-700';
    if (status === 'done') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    return 'border-slate-200 bg-slate-50 text-slate-700';
}

function typeBadgeClass(type: string) {
    return type === 'adjustment' ? 'border-violet-200 bg-violet-50 text-violet-700' : 'border-cyan-200 bg-cyan-50 text-cyan-700';
}

const SLA_DAYS: Record<string, number> = {
    new: 1,
    reviewing: 3,
    adjusting: 7,
};

function formatDateTime(value?: string | null) {
    if (!value) return null;

    return new Date(value).toLocaleString('pt-BR');
}

function slaInfo(request: any) {
    if (!request?.status || request.status === 'done' || !SLA_DAYS[request.status]) {
        return null;
    }

    const reference = request.reviewed_at && request.status !== 'new' ? request.reviewed_at : request.created_at;
    if (!reference) return null;

    const start = new Date(reference);
    const due = new Date(start);
    due.setDate(due.getDate() + SLA_DAYS[request.status]);

    const now = new Date();
    const diffMs = due.getTime() - now.getTime();
    const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

    if (diffDays < 0) {
        return {
            label: `SLA vencido há ${Math.abs(diffDays)} dia(s)`,
            className: 'border-rose-200 bg-rose-50 text-rose-700',
        };
    }

    if (diffDays <= 1) {
        return {
            label: diffDays === 0 ? 'SLA vence hoje' : 'SLA vence amanhã',
            className: 'border-amber-200 bg-amber-50 text-amber-700',
        };
    }

    return {
        label: `SLA em ${diffDays} dia(s)`,
        className: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    };
}

function RequestForm({ request, statuses }: any) {
    const form = useForm({
        status: request.status ?? 'new',
        admin_notes: request.admin_notes ?? '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        form.patch(route('admin.tenant-improvement-requests.update', request.id), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="bg-muted/20 space-y-3 rounded-2xl border p-4">
            <select
                value={form.data.status}
                onChange={(event) => form.setData('status', event.target.value)}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
            >
                {statuses.map((status: string) => (
                    <option key={status} value={status}>
                        {statusLabel(status)}
                    </option>
                ))}
            </select>

            <Textarea
                rows={4}
                value={form.data.admin_notes}
                onChange={(event) => form.setData('admin_notes', event.target.value)}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                placeholder="Resposta enviada ao cliente"
            />

            <Button type="submit" size="sm" disabled={form.processing} className="w-full">
                {form.processing ? 'Salvando...' : 'Salvar'}
            </Button>
        </form>
    );
}

export default function TenantImprovementRequestsIndex({ requests, filters, summary, statuses, flash }: any) {
    const [selectedRequestId, setSelectedRequestId] = useState<number | null>(requests?.data?.[0]?.id ?? null);
    const filterForm = useForm({
        search: filters?.search ?? '',
        status: filters?.status ?? '',
        type: filters?.type ?? '',
    });

    useEffect(() => {
        if (flash?.success) {
            toastSuccess('Sucesso', flash.success);
        }

        if (flash?.error) {
            toastWarning('Erro', flash.error);
        }
    }, [flash?.error, flash?.success]);

    const submitFilters = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const hasSearch = filterForm.data.search.trim() !== '';
        const hasStatus = filterForm.data.status !== '';
        const hasType = filterForm.data.type !== '';

        if (!hasSearch && !hasStatus && !hasType) {
            toastWarning('Filtro', 'Preencha pelo menos um campo para filtrar.');
            return;
        }

        filterForm.get(route('admin.tenant-improvement-requests.index'), {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Ajustes e solicitações SaaS" />

            <div className="flex min-h-16 w-full flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Lightbulb} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Ajustes e solicitações SaaS</h2>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button size="sm" variant="outline" asChild>
                        <Link href={route('admin.tenant-feedbacks.index')}>Avaliações</Link>
                    </Button>
                    <Button size="sm" asChild>
                        <Link href={route('admin.tenant-improvement-requests.index')}>Ajustes e solicitações</Link>
                    </Button>
                </div>
            </div>

            <div className="space-y-4 p-4">
                <div className="grid gap-4 md:grid-cols-4">
                    <div className="bg-card rounded-2xl border p-4">
                        <p className="text-muted-foreground text-sm">Total</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.total ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-2xl border p-4">
                        <p className="text-muted-foreground text-sm">Novas</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.new ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-2xl border p-4">
                        <p className="text-muted-foreground text-sm">Em análise</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.reviewing ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-2xl border p-4">
                        <p className="text-muted-foreground text-sm">Ajustando</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.adjusting ?? 0}</p>
                    </div>
                </div>

                <div className="bg-card rounded-2xl border p-4">
                    <form onSubmit={submitFilters} className="grid gap-3 lg:grid-cols-4">
                        <input
                            type="text"
                            value={filterForm.data.search}
                            onChange={(event) => filterForm.setData('search', event.target.value)}
                            placeholder="Buscar empresa ou título"
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <select
                            value={filterForm.data.status}
                            onChange={(event) => filterForm.setData('status', event.target.value)}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Todos os status</option>
                            <option value="new">Novo</option>
                            <option value="reviewing">Avaliando</option>
                            <option value="adjusting">Ajustando</option>
                            <option value="done">Concluído</option>
                        </select>
                        <select
                            value={filterForm.data.type}
                            onChange={(event) => filterForm.setData('type', event.target.value)}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Todos os tipos</option>
                            <option value="improvement">Melhoria</option>
                            <option value="adjustment">Ajuste</option>
                        </select>
                        <Button type="submit" disabled={filterForm.processing}>
                            {filterForm.processing ? 'Filtrando...' : 'Filtrar'}
                        </Button>
                    </form>
                </div>

                <div className="bg-card overflow-hidden rounded-2xl border">
                    {requests?.data?.length ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted/40 text-muted-foreground text-left text-xs tracking-wide uppercase">
                                    <tr>
                                        <th className="w-12 px-4 py-3 font-medium"></th>
                                        <th className="px-4 py-3 font-medium">Solicitação</th>
                                        <th className="px-4 py-3 font-medium">Empresa</th>
                                        <th className="px-4 py-3 font-medium">Tipo</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        <th className="px-4 py-3 font-medium">
                                            <SlaTooltip>SLA</SlaTooltip>
                                        </th>
                                        <th className="px-4 py-3 font-medium">Criada em</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {requests.data.map((request: any) => {
                                        const sla = slaInfo(request);
                                        const isSelected = selectedRequestId === request.id;

                                        return (
                                            <Fragment key={request.id}>
                                                <tr
                                                    className={`hover:bg-muted/30 cursor-pointer border-t transition-colors ${isSelected ? 'bg-muted/20' : ''}`}
                                                    onClick={() => setSelectedRequestId((current) => (current === request.id ? null : request.id))}
                                                >
                                                    <td className="px-4 py-3">
                                                        <span className="bg-background text-muted-foreground inline-flex h-8 w-8 items-center justify-center rounded-full border">
                                                            <ChevronRight
                                                                className={`h-4 w-4 transition-transform ${isSelected ? 'rotate-90' : ''}`}
                                                            />
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <div className="space-y-1">
                                                            <p className="font-medium">{request.title}</p>
                                                            <p className="text-muted-foreground line-clamp-1 text-xs">{request.description}</p>
                                                        </div>
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-3">
                                                        {request.tenant?.company || request.tenant?.name}
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${typeBadgeClass(request.request_type)}`}
                                                        >
                                                            {typeLabel(request.request_type)}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <span
                                                            className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${statusBadgeClass(request.status)}`}
                                                        >
                                                            {statusLabel(request.status)}
                                                        </span>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        {sla ? (
                                                            <span
                                                                className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${sla.className}`}
                                                            >
                                                                <SlaTooltip>{sla.label}</SlaTooltip>
                                                            </span>
                                                        ) : (
                                                            <span className="text-muted-foreground text-xs">
                                                                <SlaTooltip>Sem SLA</SlaTooltip>
                                                            </span>
                                                        )}
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-3">
                                                        {new Date(request.created_at).toLocaleDateString('pt-BR')}
                                                    </td>
                                                </tr>

                                                {isSelected ? (
                                                    <tr className="bg-background/70 border-t">
                                                        <td colSpan={7} className="px-4 py-4">
                                                            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_360px]">
                                                                <div className="space-y-4">
                                                                    <div className="bg-muted/20 rounded-2xl border p-4">
                                                                        <p className="text-sm leading-relaxed">{request.description}</p>
                                                                    </div>

                                                                    <div className="bg-muted/20 text-muted-foreground grid gap-3 rounded-2xl border p-4 text-xs md:grid-cols-3">
                                                                        <span>Enviada em: {formatDateTime(request.created_at)}</span>
                                                                        <span>Última atualização: {formatDateTime(request.updated_at)}</span>
                                                                        <span>
                                                                            Respondida em:{' '}
                                                                            {request.reviewed_at ? formatDateTime(request.reviewed_at) : '-'}
                                                                        </span>
                                                                    </div>

                                                                    <div className="text-muted-foreground flex flex-wrap items-center gap-3 text-xs">
                                                                        <span>Solicitante: {request.user?.name || 'Usuário não identificado'}</span>
                                                                        <a
                                                                            href={route(
                                                                                'admin.tenant-improvement-requests.preview-admin-email',
                                                                                request.id,
                                                                            )}
                                                                            target="_blank"
                                                                            rel="noreferrer"
                                                                            className="font-medium text-sky-700 underline underline-offset-4"
                                                                        >
                                                                            Preview e-mail admin
                                                                        </a>
                                                                        <a
                                                                            href={route(
                                                                                'admin.tenant-improvement-requests.preview-customer-email',
                                                                                request.id,
                                                                            )}
                                                                            target="_blank"
                                                                            rel="noreferrer"
                                                                            className="font-medium text-sky-700 underline underline-offset-4"
                                                                        >
                                                                            Preview e-mail cliente
                                                                        </a>
                                                                    </div>
                                                                </div>

                                                                <div className="w-full xl:max-w-sm">
                                                                    <RequestForm request={request} statuses={statuses} />
                                                                </div>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ) : null}
                                            </Fragment>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    ) : (
                        <div className="text-muted-foreground p-5 text-sm">Nenhuma solicitação de melhoria ou ajuste encontrada.</div>
                    )}
                </div>

                <AppPagination data={requests} />
            </div>
        </AdminLayout>
    );
}
