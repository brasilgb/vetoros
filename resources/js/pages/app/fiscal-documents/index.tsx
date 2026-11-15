import StandaloneInvoiceDialog, { type StandaloneOptions } from '@/components/fiscal/standalone-invoice-dialog';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import { Ban, ExternalLink, Eye, FileText, Plus, ReceiptText, RefreshCw, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';

type FiscalAttempt = {
    id: number;
    action: 'send' | 'sync';
    status: string;
    provider_status?: string | null;
    error_code?: string | null;
    message?: string | null;
    user?: string | null;
    created_at?: string | null;
};

type FiscalDocument = {
    id: number;
    documentable_type: string;
    documentable_id: number;
    type: string;
    provider: string;
    environment?: string | null;
    number?: string | null;
    series?: string | null;
    access_key?: string | null;
    status: string;
    error_message?: string | null;
    dps?: { number?: number | null; series?: string | null } | null;
    pdf_url?: string | null;
    xml_url?: string | null;
    issued_at?: string | null;
    created_at?: string | null;
    can_refresh?: boolean;
    can_cancel?: boolean;
    attempts?: FiscalAttempt[];
    standalone?: {
        id: number;
        customer?: string | null;
        total_amount: number;
        discarded: boolean;
        finance_mode: 'none' | 'receivable' | 'received';
        receivable_status?: string | null;
        can_reprocess: boolean;
        can_discard: boolean;
    } | null;
};

const receivableLabels: Record<string, string> = {
    pending: 'a receber',
    partial: 'parcialmente recebido',
    paid: 'recebido',
    cancelled: 'cancelado',
};

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Notas fiscais',
        href: '#',
    },
];

const statusLabels: Record<string, { label: string; variant: 'default' | 'secondary' | 'destructive' | 'outline' }> = {
    registered: { label: 'Registrada', variant: 'secondary' },
    processing: { label: 'Em processamento', variant: 'outline' },
    contingency: { label: 'Em contingência', variant: 'outline' },
    authorized: { label: 'Autorizada', variant: 'default' },
    rejected: { label: 'Rejeitada', variant: 'destructive' },
    denied: { label: 'Denegada', variant: 'destructive' },
    cancelled: { label: 'Cancelada', variant: 'secondary' },
    failed: { label: 'Falhou', variant: 'destructive' },
};

const attemptActionLabels: Record<string, string> = {
    send: 'Envio à Spedy',
    sync: 'Atualização de status',
};

/** Ambiente da nota: somente produção é emissão real; qualquer outro valor é homologação/simulação. */
function environmentLabel(environment?: string | null) {
    if (!environment) return '-';

    return environment === 'production' ? 'Produção' : 'Homologação (simulação)';
}

function sourceLabel(document: FiscalDocument) {
    if (document.provider === 'spedy') {
        return document.environment === 'production' ? 'Emissão automática' : 'Emissão automática (homologação)';
    }

    return document.provider === 'manual' ? 'Emissão assistida' : 'Integração anterior';
}

function documentTargetLabel(document: FiscalDocument) {
    if (document.standalone)
        return `Nota avulsa #${document.standalone.id}${document.standalone.customer ? ` — ${document.standalone.customer}` : ''}`;
    if (document.documentable_type.endsWith('\\Sale')) return `Venda #${document.documentable_id}`;
    if (document.documentable_type.endsWith('\\Order')) return `OS #${document.documentable_id}`;

    return `Registro #${document.documentable_id}`;
}

export default function FiscalDocuments({ documents = [], standalone }: { documents?: FiscalDocument[]; standalone: StandaloneOptions }) {
    const [cancelling, setCancelling] = useState<FiscalDocument | null>(null);
    const [emitting, setEmitting] = useState(false);
    const [discarding, setDiscarding] = useState<FiscalDocument | null>(null);
    // Guarda só o id: assim o diálogo mostra o histórico atualizado após refresh/reenvio.
    const [detailingId, setDetailingId] = useState<number | null>(null);
    const detailing = documents.find((document) => document.id === detailingId) ?? null;
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const refresh = (document: FiscalDocument) => {
        router.post(route('app.fiscal-documents.refresh', document.id), {}, { preserveScroll: true });
    };

    const reprocess = (document: FiscalDocument) => {
        if (!document.standalone) return;
        router.post(route('app.fiscal-documents.standalone.reprocess', document.standalone.id), {}, { preserveScroll: true });
    };

    const confirmDiscard = () => {
        if (!discarding?.standalone) return;
        router.post(
            route('app.fiscal-documents.standalone.discard', discarding.standalone.id),
            {},
            { preserveScroll: true, onFinish: () => setDiscarding(null) },
        );
    };

    const submitCancel = () => {
        if (!cancelling) return;

        post(route('app.fiscal-documents.cancel', cancelling.id), {
            preserveScroll: true,
            onSuccess: () => {
                setCancelling(null);
                reset();
            },
        });
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Notas fiscais" />

            <div className="flex min-h-16 flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ReceiptText} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Notas fiscais</h2>
                </div>
                <Button onClick={() => setEmitting(true)}>
                    <Plus className="mr-1 h-4 w-4" /> Emitir nota avulsa
                </Button>
            </div>

            <div className="space-y-4 p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Documentos fiscais</CardTitle>
                        <CardDescription>Consulte NF-e, NFC-e e NFS-e emitidas ou registradas no sistema.</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Tipo</TableHead>
                                    <TableHead>Origem</TableHead>
                                    <TableHead>Vínculo</TableHead>
                                    <TableHead>Número</TableHead>
                                    <TableHead>Status</TableHead>
                                    <TableHead>Emissão</TableHead>
                                    <TableHead className="min-w-[180px] text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {documents.length > 0 ? (
                                    documents.map((document) => {
                                        const status = statusLabels[document.status] ?? { label: document.status, variant: 'secondary' as const };

                                        return (
                                            <TableRow key={document.id}>
                                                <TableCell>{document.type.toUpperCase()}</TableCell>
                                                <TableCell className="min-w-[120px] whitespace-normal">{sourceLabel(document)}</TableCell>
                                                <TableCell className="min-w-[160px] whitespace-normal">
                                                    {documentTargetLabel(document)}
                                                    {document.standalone?.discarded && (
                                                        <p className="text-muted-foreground text-xs">Solicitação descartada</p>
                                                    )}
                                                    {document.standalone &&
                                                        document.standalone.finance_mode !== 'none' &&
                                                        document.standalone.receivable_status && (
                                                            <p className="text-muted-foreground text-xs">
                                                                Financeiro:{' '}
                                                                {receivableLabels[document.standalone.receivable_status] ??
                                                                    document.standalone.receivable_status}
                                                            </p>
                                                        )}
                                                </TableCell>
                                                <TableCell>
                                                    {document.number || '-'}
                                                    {!document.number && document.dps?.number && (
                                                        <p className="text-muted-foreground text-xs">
                                                            DPS {document.dps.number}
                                                            {document.dps.series ? ` · série ${document.dps.series}` : ''}
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge variant={status.variant}>{status.label}</Badge>
                                                    {document.error_message && (
                                                        <p className="text-destructive mt-1 max-w-xs text-xs whitespace-normal">
                                                            {document.error_message}
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {document.issued_at ? new Date(document.issued_at).toLocaleDateString('pt-BR') : '-'}
                                                </TableCell>
                                                <TableCell className="min-w-[180px]">
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        <Button
                                                            size="icon"
                                                            variant="outline"
                                                            title="Ver detalhes"
                                                            onClick={() => setDetailingId(document.id)}
                                                        >
                                                            <Eye className="h-4 w-4" />
                                                        </Button>
                                                        {document.can_refresh && (
                                                            <Button
                                                                size="icon"
                                                                variant="outline"
                                                                title="Atualizar status"
                                                                onClick={() => refresh(document)}
                                                            >
                                                                <RefreshCw className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                        {document.pdf_url ? (
                                                            <Button size="icon" variant="outline" asChild title="Abrir nota (PDF)">
                                                                <a
                                                                    href={document.pdf_url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    aria-label="Abrir nota (PDF)"
                                                                >
                                                                    <FileText className="h-4 w-4" />
                                                                </a>
                                                            </Button>
                                                        ) : (
                                                            // Sempre visível: sem PDF, explica por quê em vez de sumir.
                                                            <span title="A nota só pode ser aberta depois de autorizada">
                                                                <Button size="icon" variant="outline" disabled aria-label="Abrir nota indisponível">
                                                                    <FileText className="h-4 w-4" />
                                                                </Button>
                                                            </span>
                                                        )}
                                                        {document.xml_url && (
                                                            <Button size="icon" variant="outline" asChild title="Baixar XML">
                                                                <a
                                                                    href={document.xml_url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    aria-label="Baixar XML"
                                                                >
                                                                    <ExternalLink className="h-4 w-4" />
                                                                </a>
                                                            </Button>
                                                        )}
                                                        {document.standalone?.can_reprocess && (
                                                            <Button
                                                                size="icon"
                                                                variant="outline"
                                                                title="Reprocessar nota avulsa"
                                                                onClick={() => reprocess(document)}
                                                            >
                                                                <RotateCcw className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                        {document.standalone?.can_discard && (
                                                            <Button
                                                                size="icon"
                                                                variant="outline"
                                                                title="Descartar solicitação"
                                                                onClick={() => setDiscarding(document)}
                                                            >
                                                                <Trash2 className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                        {document.can_cancel && (
                                                            <Button
                                                                size="icon"
                                                                variant="outline"
                                                                title="Cancelar nota"
                                                                onClick={() => setCancelling(document)}
                                                            >
                                                                <Ban className="h-4 w-4" />
                                                            </Button>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        );
                                    })
                                ) : (
                                    <TableRow>
                                        <TableCell colSpan={7} className="h-16 text-center">
                                            Nenhuma nota fiscal registrada.
                                        </TableCell>
                                    </TableRow>
                                )}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>
            </div>

            <StandaloneInvoiceDialog open={emitting} onOpenChange={setEmitting} options={standalone} />

            <Dialog open={detailing !== null} onOpenChange={(open) => !open && setDetailingId(null)}>
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>
                            Nota {detailing?.type.toUpperCase()} {detailing?.number ? `nº ${detailing.number}` : '(sem número)'}
                        </DialogTitle>
                        <DialogDescription>{detailing ? sourceLabel(detailing) : ''}</DialogDescription>
                    </DialogHeader>

                    {detailing && (
                        <div className="space-y-4 text-sm">
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2">
                                <dt className="text-muted-foreground">Situação</dt>
                                <dd>
                                    <Badge variant={(statusLabels[detailing.status] ?? { variant: 'secondary' as const }).variant}>
                                        {(statusLabels[detailing.status] ?? { label: detailing.status }).label}
                                    </Badge>
                                </dd>
                                <dt className="text-muted-foreground">Ambiente</dt>
                                <dd>{environmentLabel(detailing.environment)}</dd>
                                <dt className="text-muted-foreground">Série</dt>
                                <dd>{detailing.series || '-'}</dd>
                                <dt className="text-muted-foreground">Número</dt>
                                <dd>{detailing.number || '-'}</dd>
                                <dt className="text-muted-foreground">Chave de acesso</dt>
                                <dd className="font-mono text-xs break-all">{detailing.access_key || '-'}</dd>
                                <dt className="text-muted-foreground">Emissão</dt>
                                <dd>{detailing.issued_at ? new Date(detailing.issued_at).toLocaleString('pt-BR') : '-'}</dd>
                            </dl>

                            {detailing.error_message && (
                                <p className="border-destructive/40 bg-destructive/5 text-destructive rounded-md border p-3">
                                    {detailing.error_message}
                                </p>
                            )}

                            <div>
                                <h3 className="mb-2 font-medium">Histórico de tentativas</h3>
                                {(detailing.attempts ?? []).length > 0 ? (
                                    <ul className="divide-y rounded-md border">
                                        {(detailing.attempts ?? []).map((attempt) => (
                                            <li key={attempt.id} className="space-y-1 p-3">
                                                <div className="flex flex-wrap items-center justify-between gap-2">
                                                    <span className="font-medium">{attemptActionLabels[attempt.action] ?? attempt.action}</span>
                                                    <span className="text-muted-foreground text-xs">
                                                        {attempt.created_at ? new Date(attempt.created_at).toLocaleString('pt-BR') : ''}
                                                        {attempt.user ? ` · ${attempt.user}` : ''}
                                                    </span>
                                                </div>
                                                <p className="text-muted-foreground text-xs">
                                                    {(statusLabels[attempt.status] ?? { label: attempt.status }).label}
                                                    {attempt.provider_status ? ` (Spedy: ${attempt.provider_status})` : ''}
                                                </p>
                                                {attempt.message && <p className="text-destructive text-xs break-words">{attempt.message}</p>}
                                            </li>
                                        ))}
                                    </ul>
                                ) : (
                                    <p className="text-muted-foreground">Nenhuma tentativa registrada.</p>
                                )}
                            </div>
                        </div>
                    )}

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setDetailingId(null)}>
                            Fechar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={discarding !== null} onOpenChange={(open) => !open && setDiscarding(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Descartar nota avulsa #{discarding?.standalone?.id}</DialogTitle>
                        <DialogDescription>
                            A nota não foi autorizada e não será mais reprocessada.
                            {discarding?.standalone?.finance_mode !== 'none' &&
                                ' A conta a receber em aberto será cancelada; se houver recebimento, estorne-o antes.'}
                        </DialogDescription>
                    </DialogHeader>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setDiscarding(null)}>
                            Voltar
                        </Button>
                        <Button variant="destructive" onClick={confirmDiscard}>
                            Descartar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <Dialog open={cancelling !== null} onOpenChange={(open) => !open && setCancelling(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            Cancelar {cancelling?.type.toUpperCase()} {cancelling?.number}
                        </DialogTitle>
                        <DialogDescription>
                            O cancelamento só é aceito dentro do prazo legal (em geral 24 horas para NF-e/NFC-e). Descreva o motivo real.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="grid gap-2">
                        <Label htmlFor="reason">Justificativa (15 a 255 caracteres)</Label>
                        <Textarea id="reason" maxLength={255} value={data.reason} onChange={(e) => setData('reason', e.target.value)} />
                        <InputError message={errors.reason} />
                    </div>
                    <DialogFooter>
                        <Button variant="ghost" onClick={() => setCancelling(null)}>
                            Voltar
                        </Button>
                        <Button variant="destructive" disabled={processing || data.reason.trim().length < 15} onClick={submitCancel}>
                            Cancelar nota
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
