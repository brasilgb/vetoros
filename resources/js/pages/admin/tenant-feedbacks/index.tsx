import AlertSuccess from '@/components/app-alert-success';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ChevronRight, MessageSquareMore } from 'lucide-react';
import moment from 'moment';
import { FormEvent, Fragment, useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Ajustes/Avaliações',
        href: route('admin.tenant-feedbacks.index'),
    },
    {
        title: 'Avaliações',
        href: route('admin.tenant-feedbacks.index'),
    },
];

function statusBadgeClass(status?: string | null) {
    if (status === 'submitted') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    if (status === 'opened') return 'border-sky-200 bg-sky-50 text-sky-700';
    return 'border-amber-200 bg-amber-50 text-amber-700';
}

function recoveryBadgeClass(status?: string | null) {
    if (status === 'resolved') return 'border-emerald-200 bg-emerald-50 text-emerald-700';
    if (status === 'in_progress') return 'border-sky-200 bg-sky-50 text-sky-700';
    if (status === 'pending') return 'border-amber-200 bg-amber-50 text-amber-700';
    return 'border-slate-200 bg-slate-50 text-slate-600';
}

function ratingBadgeClass(rating?: number | null) {
    if (!rating) return 'border-slate-200 bg-slate-50 text-slate-600';
    if (rating <= 3) return 'border-rose-200 bg-rose-50 text-rose-700';
    if (rating === 4) return 'border-amber-200 bg-amber-50 text-amber-700';
    return 'border-emerald-200 bg-emerald-50 text-emerald-700';
}

function feedbackStatusLabel(status?: string | null) {
    if (status === 'submitted') return 'Respondido';
    if (status === 'opened') return 'Aberto';
    if (status === 'pending') return 'Pendente';
    return 'Pendente';
}

function recoveryStatusLabel(status?: string | null) {
    if (status === 'resolved') return 'Resolvido';
    if (status === 'in_progress') return 'Em andamento';
    if (status === 'pending') return 'Pendente';
    return 'Sem tratativa';
}

function testimonialStatusLabel(status?: string | null) {
    if (status === 'approved') return 'Aprovado';
    if (status === 'rejected') return 'Rejeitado';
    if (status === 'published') return 'Publicado';
    if (status === 'pending') return 'Pendente';
    return 'Sem depoimento';
}

function RecoveryForm({ feedback, users, recoveryStatuses, testimonialStatuses }: any) {
    const form = useForm({
        feedback_recovery_assigned_to: feedback.feedback_recovery_assigned_to ?? '',
        feedback_recovery_status: feedback.feedback_recovery_status ?? '',
        feedback_recovery_notes: feedback.feedback_recovery_notes ?? '',
        testimonial_status: feedback.testimonial_status ?? '',
        testimonial_public_name: feedback.testimonial_public_name ?? '',
        testimonial_public_role: feedback.testimonial_public_role ?? '',
        testimonial_excerpt: feedback.testimonial_excerpt ?? feedback.feedback_comment ?? '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        form.patch(route('admin.tenant-feedbacks.update', feedback.id), {
            preserveScroll: true,
        });
    };

    return (
        <form onSubmit={submit} className="space-y-3">
            <select
                value={form.data.feedback_recovery_assigned_to}
                onChange={(event) => form.setData('feedback_recovery_assigned_to', event.target.value)}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
            >
                <option value="">Sem responsável</option>
                {users.map((user: any) => (
                    <option key={user.id} value={user.id}>
                        {user.name}
                    </option>
                ))}
            </select>

            <select
                value={form.data.feedback_recovery_status}
                onChange={(event) => form.setData('feedback_recovery_status', event.target.value)}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
            >
                <option value="">Sem status</option>
                {recoveryStatuses.map((status: string) => (
                    <option key={status} value={status}>
                        {recoveryStatusLabel(status)}
                    </option>
                ))}
            </select>

            <Textarea
                value={form.data.feedback_recovery_notes}
                onChange={(event) => form.setData('feedback_recovery_notes', event.target.value)}
                rows={3}
                className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                placeholder="Anotações da tratativa"
            />

            <div className="bg-muted/30 space-y-3 rounded-lg border p-3">
                <p className="text-sm font-medium">Depoimento</p>

                <select
                    value={form.data.testimonial_status}
                    onChange={(event) => form.setData('testimonial_status', event.target.value)}
                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                >
                    <option value="">Sem status</option>
                    {testimonialStatuses.map((status: string) => (
                        <option key={status} value={status}>
                            {testimonialStatusLabel(status)}
                        </option>
                    ))}
                </select>

                <input
                    value={form.data.testimonial_public_name}
                    onChange={(event) => form.setData('testimonial_public_name', event.target.value)}
                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    placeholder="Nome público"
                />

                <input
                    value={form.data.testimonial_public_role}
                    onChange={(event) => form.setData('testimonial_public_role', event.target.value)}
                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    placeholder="Cargo ou contexto"
                />

                <Textarea
                    value={form.data.testimonial_excerpt}
                    onChange={(event) => form.setData('testimonial_excerpt', event.target.value)}
                    rows={4}
                    className="border-input bg-background w-full rounded-md border px-3 py-2 text-sm"
                    placeholder="Trecho aprovado para a landing"
                />
            </div>

            <Button type="submit" size="sm" disabled={form.processing}>
                {form.processing ? 'Salvando...' : 'Salvar'}
            </Button>
        </form>
    );
}

export default function TenantFeedbacksIndex({ feedbacks, filters, summary, users, recoveryStatuses, testimonialStatuses, flash }: any) {
    const [selectedFeedbackId, setSelectedFeedbackId] = useState<number | null>(feedbacks?.data?.[0]?.id ?? null);

    const queryBase = {
        ...(filters?.search ? { search: filters.search } : {}),
        ...(filters?.status ? { status: filters.status } : {}),
        ...(filters?.recovery_status ? { recovery_status: filters.recovery_status } : {}),
        ...(filters?.source ? { source: filters.source } : {}),
        ...(filters?.rating ? { rating: filters.rating } : {}),
        ...(filters?.testimonial_status ? { testimonial_status: filters.testimonial_status } : {}),
    };

    const quickFilters = [
        { label: 'Todos', params: {} },
        { label: 'Pendentes', params: { status: 'pending' } },
        { label: 'Respondidos', params: { status: 'submitted' } },
        { label: 'Notas baixas', params: { rating: 'low' } },
        { label: 'Recuperação pendente', params: { recovery_status: 'pending' } },
        { label: 'Depoimentos pendentes', params: { testimonial_status: 'pending' } },
    ];

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Avaliações SaaS" />
            {flash?.success && <AlertSuccess message={flash.success} />}

            <div className="flex min-h-16 w-full flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={MessageSquareMore} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Avaliações SaaS</h2>
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                    <Button size="sm" asChild>
                        <Link href={route('admin.tenant-feedbacks.index')}>Avaliações</Link>
                    </Button>
                    <Button size="sm" variant="outline" asChild>
                        <Link href={route('admin.tenant-improvement-requests.index')}>Ajustes e solicitações</Link>
                    </Button>
                </div>
            </div>

            <div className="space-y-4 p-4">
                <div className="grid gap-4 md:grid-cols-5">
                    <div className="bg-card rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Total</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.total ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Pendentes</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.pending ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Respondidos</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.submitted ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Notas baixas</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.low_rating ?? 0}</p>
                    </div>
                    <div className="bg-card rounded-lg border p-4">
                        <p className="text-muted-foreground text-sm">Depoimentos pendentes</p>
                        <p className="mt-2 text-2xl font-semibold">{summary?.testimonial_pending ?? 0}</p>
                    </div>
                </div>

                <div className="bg-card rounded-lg border p-4">
                    <form method="get" action={route('admin.tenant-feedbacks.index')} className="grid gap-3 lg:grid-cols-6">
                        <input
                            type="text"
                            name="search"
                            defaultValue={filters?.search ?? ''}
                            placeholder="Buscar empresa ou e-mail"
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        />
                        <select
                            name="status"
                            defaultValue={filters?.status ?? ''}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Todos os status</option>
                            <option value="pending">Pendente</option>
                            <option value="opened">Aberto</option>
                            <option value="submitted">Respondido</option>
                        </select>
                        <select
                            name="recovery_status"
                            defaultValue={filters?.recovery_status ?? ''}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Toda recuperação</option>
                            <option value="without_recovery">Sem recuperação</option>
                            <option value="pending">Pendente</option>
                            <option value="in_progress">Em andamento</option>
                            <option value="resolved">Resolvido</option>
                        </select>
                        <select
                            name="rating"
                            defaultValue={filters?.rating ?? ''}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Todas as notas</option>
                            <option value="low">Até 3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                        </select>
                        <select
                            name="testimonial_status"
                            defaultValue={filters?.testimonial_status ?? ''}
                            className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                        >
                            <option value="">Todo depoimento</option>
                            <option value="without_testimonial">Sem consentimento</option>
                            <option value="pending">Pendente</option>
                            <option value="approved">Aprovado</option>
                            <option value="rejected">Rejeitado</option>
                            <option value="published">Publicado</option>
                        </select>
                        <div className="flex gap-2">
                            <Button type="submit" className="flex-1">
                                Filtrar
                            </Button>
                            <Button type="button" variant="outline" asChild>
                                <Link href={route('admin.tenant-feedbacks.index')}>Limpar</Link>
                            </Button>
                        </div>
                    </form>

                    <div className="mt-3 flex flex-wrap gap-2">
                        {quickFilters.map((filter) => (
                            <Link key={filter.label} href={route('admin.tenant-feedbacks.index', { ...queryBase, ...filter.params })}>
                                <Badge variant="outline">{filter.label}</Badge>
                            </Link>
                        ))}
                    </div>
                </div>

                <div className="bg-card overflow-hidden rounded-lg border">
                    {feedbacks?.data?.length ? (
                        <div className="overflow-x-auto">
                            <table className="min-w-full text-sm">
                                <thead className="bg-muted/40 text-muted-foreground text-left text-xs tracking-wide uppercase">
                                    <tr>
                                        <th className="w-12 px-4 py-3 font-medium"></th>
                                        <th className="px-4 py-3 font-medium">Empresa</th>
                                        <th className="px-4 py-3 font-medium">Status</th>
                                        <th className="px-4 py-3 font-medium">Nota</th>
                                        <th className="px-4 py-3 font-medium">Recuperação</th>
                                        <th className="px-4 py-3 font-medium">Depoimento</th>
                                        <th className="px-4 py-3 font-medium">Respondido em</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {feedbacks.data.map((feedback: any) => {
                                        const isSelected = selectedFeedbackId === feedback.id;

                                        return (
                                            <Fragment key={feedback.id}>
                                                <tr
                                                    className={`hover:bg-muted/30 cursor-pointer border-t transition-colors ${isSelected ? 'bg-muted/20' : ''}`}
                                                    onClick={() => setSelectedFeedbackId((current) => (current === feedback.id ? null : feedback.id))}
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
                                                            <p className="font-medium">{feedback.tenant?.company || feedback.tenant?.name}</p>
                                                            <p className="text-muted-foreground text-xs">{feedback.tenant?.email || '-'}</p>
                                                        </div>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={statusBadgeClass(feedback.feedback_status)}>
                                                            {feedbackStatusLabel(feedback.feedback_status)}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={ratingBadgeClass(feedback.feedback_rating)}>
                                                            {feedback.feedback_rating ? `${feedback.feedback_rating}/5` : 'Sem nota'}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={recoveryBadgeClass(feedback.feedback_recovery_status)}>
                                                            {recoveryStatusLabel(feedback.feedback_recovery_status)}
                                                        </Badge>
                                                    </td>
                                                    <td className="px-4 py-3">
                                                        <Badge variant="outline" className={recoveryBadgeClass(feedback.testimonial_status)}>
                                                            {testimonialStatusLabel(feedback.testimonial_status)}
                                                        </Badge>
                                                    </td>
                                                    <td className="text-muted-foreground px-4 py-3">
                                                        {feedback.feedback_submitted_at
                                                            ? moment(feedback.feedback_submitted_at).format('DD/MM/YYYY HH:mm')
                                                            : '-'}
                                                    </td>
                                                </tr>

                                                {isSelected ? (
                                                    <tr className="bg-background/70 border-t">
                                                        <td colSpan={7} className="px-4 py-4">
                                                            <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_340px]">
                                                                <div className="space-y-4">
                                                                    <div className="bg-muted/20 text-muted-foreground grid gap-3 rounded-2xl border p-4 text-sm md:grid-cols-2">
                                                                        <p>E-mail: {feedback.tenant?.email || '-'}</p>
                                                                        <p>Origem: {feedback.feedback_source}</p>
                                                                        <p>
                                                                            Enviado em:{' '}
                                                                            {feedback.feedback_sent_at
                                                                                ? moment(feedback.feedback_sent_at).format('DD/MM/YYYY HH:mm')
                                                                                : '-'}
                                                                        </p>
                                                                        <p>
                                                                            Respondido em:{' '}
                                                                            {feedback.feedback_submitted_at
                                                                                ? moment(feedback.feedback_submitted_at).format('DD/MM/YYYY HH:mm')
                                                                                : '-'}
                                                                        </p>
                                                                    </div>

                                                                    {feedback.feedback_comment ? (
                                                                        <div className="bg-muted/20 text-foreground rounded-2xl border p-4 text-sm leading-relaxed">
                                                                            {feedback.feedback_comment}
                                                                        </div>
                                                                    ) : (
                                                                        <div className="bg-muted/20 text-muted-foreground rounded-2xl border p-4 text-sm">
                                                                            Sem comentário enviado.
                                                                        </div>
                                                                    )}

                                                                    {feedback.testimonial_consent_at ? (
                                                                        <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">
                                                                            Cliente autorizou avaliação para depoimento público.
                                                                        </div>
                                                                    ) : null}

                                                                    <div className="flex flex-wrap gap-2">
                                                                        <a
                                                                            href={route('tenant.feedback.show', feedback.feedback_token)}
                                                                            target="_blank"
                                                                            rel="noopener noreferrer"
                                                                            className="text-primary text-sm font-medium underline underline-offset-4"
                                                                        >
                                                                            Abrir formulário público
                                                                        </a>
                                                                    </div>
                                                                </div>

                                                                <div className="w-full xl:max-w-sm">
                                                                    <RecoveryForm
                                                                        feedback={feedback}
                                                                        users={users}
                                                                        recoveryStatuses={recoveryStatuses}
                                                                        testimonialStatuses={testimonialStatuses}
                                                                    />
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
                        <div className="text-muted-foreground p-10 text-center">Nenhum feedback encontrado com os filtros atuais.</div>
                    )}
                </div>

                <AppPagination data={feedbacks} />
            </div>
        </AdminLayout>
    );
}
