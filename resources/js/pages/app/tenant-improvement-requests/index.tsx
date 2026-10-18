import AppPagination from '@/components/app-pagination';
import { toastWarning } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import { SlaTooltip } from '@/components/sla-tooltip';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Lightbulb, MessageSquareQuote, Star } from 'lucide-react';
import { type FormEvent } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Ajustes/Avaliações',
        href: route('app.improvement-requests.index'),
    },
];

function statusLabel(status: string) {
    if (status === 'reviewing') return 'Em análise';
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

function feedbackLabel(rating: number) {
    if (rating === 1) return 'Muito ruim';
    if (rating === 2) return 'Ruim';
    if (rating === 3) return 'Regular';
    if (rating === 4) return 'Bom';
    if (rating === 5) return 'Excelente';
    return 'Selecione uma nota';
}

function testimonialStatusLabel(status?: string | null) {
    if (status === 'approved') return 'Aprovado';
    if (status === 'rejected') return 'Rejeitado';
    if (status === 'published') return 'Publicado';
    if (status === 'pending') return 'Pendente';
    return null;
}

const SLA_DAYS: Record<string, number> = {
    new: 1,
    reviewing: 3,
    adjusting: 7,
};

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
            label: `Prazo em revisão. A última previsão venceu há ${Math.abs(diffDays)} dia(s)`,
            className: 'border-rose-200 bg-rose-50 text-rose-700',
        };
    }

    if (diffDays <= 1) {
        return {
            label: diffDays === 0 ? 'Nova atualização prevista para hoje' : 'Nova atualização prevista para amanhã',
            className: 'border-amber-200 bg-amber-50 text-amber-700',
        };
    }

    return {
        label: `Nova atualização prevista em até ${diffDays} dia(s)`,
        className: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    };
}

function formatDateTime(value?: string | null) {
    if (!value) return null;

    return new Date(value).toLocaleString('pt-BR');
}

export default function TenantImprovementRequestsIndex({ requests, feedbacks, requestTypes }: any) {
    const requestForm = useForm({
        request_type: requestTypes?.[0] ?? 'improvement',
        title: '',
        description: '',
    });

    const feedbackForm = useForm({
        rating: 0,
        comment: '',
        allow_testimonial: false,
        testimonial_public_name: '',
        testimonial_public_role: '',
    });

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        requestForm.post(route('app.improvement-requests.store'), {
            preserveScroll: true,
            onSuccess: () => requestForm.reset('title', 'description'),
        });
    };

    const submitFeedback = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (feedbackForm.data.rating < 1) {
            toastWarning('Avaliação', 'Selecione uma nota para registrar sua avaliação.');
            return;
        }

        feedbackForm.post(route('app.improvement-requests.feedback.store'), {
            preserveScroll: true,
            onSuccess: () => feedbackForm.reset(),
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Ajustes/Avaliações" />

            <div className="flex min-h-16 w-full flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Lightbulb} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Ajustes/Avaliações</h2>
                </div>
            </div>

            <div className="space-y-4 p-4">
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(360px,0.75fr)]">
                    <div className="bg-card rounded-3xl border p-5 shadow-sm sm:p-6">
                        <div className="space-y-2">
                            <h3 className="text-lg font-semibold">Envie uma sugestão para o VetorOS</h3>
                            <p className="text-muted-foreground text-sm">
                                Use este espaço para pedir ajustes na rotina atual ou sugerir melhorias para a evolução do sistema.
                            </p>
                        </div>

                        <form onSubmit={submit} className="mt-6 space-y-4">
                            <div className="grid gap-2 sm:max-w-sm">
                                <Label htmlFor="request_type">Tipo</Label>
                                <select
                                    id="request_type"
                                    value={requestForm.data.request_type}
                                    onChange={(event) => requestForm.setData('request_type', event.target.value)}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                >
                                    <option value="improvement">Melhoria</option>
                                    <option value="adjustment">Ajuste</option>
                                </select>
                                {requestForm.errors.request_type ? <p className="text-sm text-red-500">{requestForm.errors.request_type}</p> : null}
                            </div>

                            <div className="space-y-4">
                                <div className="grid gap-2">
                                    <Label htmlFor="title">Título</Label>
                                    <input
                                        id="title"
                                        value={requestForm.data.title}
                                        onChange={(event) => requestForm.setData('title', event.target.value)}
                                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                        placeholder="Ex.: Melhorar o acompanhamento de ordens"
                                    />
                                    {requestForm.errors.title ? <p className="text-sm text-red-500">{requestForm.errors.title}</p> : null}
                                </div>

                                <div className="grid gap-2">
                                    <Label htmlFor="description">Detalhes</Label>
                                    <Textarea
                                        id="description"
                                        rows={5}
                                        value={requestForm.data.description}
                                        onChange={(event) => requestForm.setData('description', event.target.value)}
                                        className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                        placeholder="Descreva o que precisa ser ajustado ou a melhoria que faria diferença na sua rotina."
                                    />
                                    {requestForm.errors.description ? <p className="text-sm text-red-500">{requestForm.errors.description}</p> : null}
                                </div>

                                <div className="flex justify-end">
                                    <Button type="submit" disabled={requestForm.processing}>
                                        {requestForm.processing ? 'Enviando...' : 'Enviar solicitação'}
                                    </Button>
                                </div>
                            </div>
                        </form>
                    </div>

                    <div className="bg-card rounded-3xl border p-5 shadow-sm sm:p-6">
                        <div className="flex items-start gap-3">
                            <Icon iconNode={MessageSquareQuote} className="mt-0.5 h-5 w-5" />
                            <div className="space-y-2">
                                <h3 className="text-lg font-semibold">Avaliação e testemunho</h3>
                                <p className="text-muted-foreground text-sm">
                                    Registre sua avaliação sobre o VetorOS e autorize o uso como depoimento quando fizer sentido.
                                </p>
                            </div>
                        </div>

                        <form onSubmit={submitFeedback} className="mt-6 space-y-4">
                            <div>
                                <Label>Sua nota</Label>
                                <div className="mt-2 flex flex-wrap gap-2">
                                    {[1, 2, 3, 4, 5].map((rating) => {
                                        const selected = feedbackForm.data.rating === rating;

                                        return (
                                            <button
                                                key={rating}
                                                type="button"
                                                onClick={() => feedbackForm.setData('rating', rating)}
                                                className={`inline-flex h-10 items-center gap-1 rounded-md border px-3 text-sm font-semibold transition ${
                                                    selected
                                                        ? 'border-amber-300 bg-amber-50 text-amber-700'
                                                        : 'border-input bg-background text-muted-foreground hover:bg-muted'
                                                }`}
                                            >
                                                <Star className={`h-4 w-4 ${selected ? 'fill-current' : ''}`} />
                                                {rating}
                                            </button>
                                        );
                                    })}
                                </div>
                                {feedbackForm.data.rating > 0 ? (
                                    <p className="text-muted-foreground mt-2 text-sm">{feedbackLabel(feedbackForm.data.rating)}</p>
                                ) : null}
                                {feedbackForm.errors.rating ? <p className="mt-2 text-sm text-red-500">{feedbackForm.errors.rating}</p> : null}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="feedback_comment">Avaliação</Label>
                                <Textarea
                                    id="feedback_comment"
                                    rows={4}
                                    value={feedbackForm.data.comment}
                                    onChange={(event) => feedbackForm.setData('comment', event.target.value)}
                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                    placeholder="Conte como está sua experiência com a plataforma."
                                />
                                <p className="text-muted-foreground text-right text-xs">{feedbackForm.data.comment.length}/500</p>
                                {feedbackForm.errors.comment ? <p className="text-sm text-red-500">{feedbackForm.errors.comment}</p> : null}
                            </div>

                            {feedbackForm.data.rating >= 4 ? (
                                <div className="bg-muted/25 rounded-2xl border p-4">
                                    <label className="flex items-start gap-3 text-sm leading-relaxed">
                                        <input
                                            type="checkbox"
                                            checked={feedbackForm.data.allow_testimonial}
                                            onChange={(event) => feedbackForm.setData('allow_testimonial', event.target.checked)}
                                            className="border-input mt-1 h-4 w-4 rounded"
                                        />
                                        <span>Autorizo que minha avaliação seja analisada para possível uso como testemunho público do VetorOS.</span>
                                    </label>

                                    {feedbackForm.data.allow_testimonial ? (
                                        <div className="mt-4 grid gap-4 sm:grid-cols-2">
                                            <div className="grid gap-2">
                                                <Label htmlFor="testimonial_public_name">Nome público</Label>
                                                <input
                                                    id="testimonial_public_name"
                                                    value={feedbackForm.data.testimonial_public_name}
                                                    onChange={(event) => feedbackForm.setData('testimonial_public_name', event.target.value)}
                                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                                    placeholder="Ex.: Anderson"
                                                />
                                            </div>

                                            <div className="grid gap-2">
                                                <Label htmlFor="testimonial_public_role">Cargo ou contexto</Label>
                                                <input
                                                    id="testimonial_public_role"
                                                    value={feedbackForm.data.testimonial_public_role}
                                                    onChange={(event) => feedbackForm.setData('testimonial_public_role', event.target.value)}
                                                    className="border-input bg-background rounded-md border px-3 py-2 text-sm"
                                                    placeholder="Ex.: Proprietário"
                                                />
                                            </div>
                                        </div>
                                    ) : null}
                                </div>
                            ) : null}

                            <div className="flex justify-end">
                                <Button type="submit" disabled={feedbackForm.processing}>
                                    {feedbackForm.processing ? 'Enviando...' : 'Enviar avaliação'}
                                </Button>
                            </div>
                        </form>
                    </div>
                </div>

                <div className="bg-card rounded-3xl border p-5 shadow-sm sm:p-6">
                    <div className="space-y-2">
                        <h3 className="text-lg font-semibold">Avaliações recentes</h3>
                        <p className="text-muted-foreground text-sm">Veja os últimos registros enviados pela sua empresa.</p>
                    </div>

                    <div className="mt-6 grid gap-3 lg:grid-cols-2">
                        {feedbacks?.length ? (
                            feedbacks.map((feedback: any) => (
                                <div key={feedback.id} className="bg-background/60 rounded-2xl border p-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <div className="flex items-center gap-1 text-amber-600">
                                            {[1, 2, 3, 4, 5].map((rating) => (
                                                <Star
                                                    key={rating}
                                                    className={`h-4 w-4 ${rating <= feedback.feedback_rating ? 'fill-current' : 'text-muted-foreground/35'}`}
                                                />
                                            ))}
                                            <span className="text-foreground ml-2 text-sm font-medium">
                                                {feedbackLabel(Number(feedback.feedback_rating ?? 0))}
                                            </span>
                                        </div>
                                        <span className="text-muted-foreground text-xs">{formatDateTime(feedback.feedback_submitted_at)}</span>
                                    </div>

                                    {feedback.feedback_comment ? (
                                        <p className="text-muted-foreground mt-3 text-sm leading-relaxed">{feedback.feedback_comment}</p>
                                    ) : (
                                        <p className="text-muted-foreground mt-3 text-sm">Avaliação enviada sem comentário.</p>
                                    )}

                                    {feedback.testimonial_consent_at ? (
                                        <div className="bg-muted/25 text-muted-foreground mt-3 rounded-xl border px-3 py-2 text-xs">
                                            Testemunho autorizado
                                            {testimonialStatusLabel(feedback.testimonial_status)
                                                ? `: ${testimonialStatusLabel(feedback.testimonial_status)}`
                                                : ''}
                                        </div>
                                    ) : null}
                                </div>
                            ))
                        ) : (
                            <p className="text-muted-foreground text-sm">Nenhuma avaliação registrada até o momento.</p>
                        )}
                    </div>
                </div>

                <div className="bg-card rounded-3xl border p-5 shadow-sm sm:p-6">
                    <div className="space-y-2">
                        <h3 className="text-lg font-semibold">Solicitações enviadas</h3>
                        <p className="text-muted-foreground text-sm">Acompanhe as solicitações recentes registradas pela sua empresa.</p>
                    </div>

                    <div className="mt-6 space-y-4">
                        {requests?.data?.length ? (
                            requests.data.map((request: any) => {
                                const sla = slaInfo(request);

                                return (
                                    <div key={request.id} className="bg-background/60 rounded-3xl border p-5">
                                        <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                            <div>
                                                <div className="flex flex-wrap gap-2 pb-2">
                                                    <span
                                                        className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${typeBadgeClass(request.request_type)}`}
                                                    >
                                                        {typeLabel(request.request_type)}
                                                    </span>
                                                    <span
                                                        className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${statusBadgeClass(request.status)}`}
                                                    >
                                                        {statusLabel(request.status)}
                                                    </span>
                                                    {sla ? (
                                                        <span
                                                            className={`inline-flex rounded-full border px-2.5 py-1 text-[11px] font-semibold ${sla.className}`}
                                                        >
                                                            <SlaTooltip>{sla.label}</SlaTooltip>
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <p className="font-medium">{request.title}</p>
                                            </div>
                                            <span className="text-muted-foreground rounded-full border px-2.5 py-1 text-xs">
                                                {new Date(request.created_at).toLocaleDateString('pt-BR')}
                                            </span>
                                        </div>
                                        <p className="mt-4 text-sm leading-relaxed">{request.description}</p>
                                        <div className="bg-muted/25 text-muted-foreground mt-4 grid gap-2 rounded-2xl border p-3 text-xs sm:grid-cols-3">
                                            <span>Enviada em: {formatDateTime(request.created_at)}</span>
                                            <span>Última atualização: {formatDateTime(request.updated_at)}</span>
                                            {request.reviewed_at ? <span>Respondida em: {formatDateTime(request.reviewed_at)}</span> : null}
                                        </div>
                                        {request.admin_notes ? (
                                            <div className="bg-muted/40 mt-4 rounded-2xl border p-4 text-sm">
                                                <strong className="block">Resposta da equipe</strong>
                                                <span className="mt-1 block leading-relaxed">{request.admin_notes}</span>
                                            </div>
                                        ) : null}
                                    </div>
                                );
                            })
                        ) : (
                            <p className="text-muted-foreground text-sm">Nenhuma solicitação registrada até o momento.</p>
                        )}
                    </div>

                    <div className="mt-6">
                        <AppPagination data={requests} />
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
