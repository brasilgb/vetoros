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
import { Ban, ExternalLink, FileText, ReceiptText, RefreshCw } from 'lucide-react';
import { useState } from 'react';

type FiscalDocument = {
    id: number;
    documentable_type: string;
    documentable_id: number;
    type: string;
    provider: string;
    environment?: string | null;
    number?: string | null;
    series?: string | null;
    status: string;
    error_message?: string | null;
    pdf_url?: string | null;
    xml_url?: string | null;
    issued_at?: string | null;
    created_at?: string | null;
    can_refresh?: boolean;
    can_cancel?: boolean;
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

function sourceLabel(document: FiscalDocument) {
    if (document.provider === 'spedy') {
        return document.environment === 'production' ? 'Emissão automática' : 'Emissão automática (homologação)';
    }

    return document.provider === 'manual' ? 'Emissão assistida' : 'Integração anterior';
}

function documentTargetLabel(document: FiscalDocument) {
    if (document.documentable_type.endsWith('\\Sale')) return `Venda #${document.documentable_id}`;
    if (document.documentable_type.endsWith('\\Order')) return `OS #${document.documentable_id}`;

    return `Registro #${document.documentable_id}`;
}

export default function FiscalDocuments({ documents = [] }: { documents?: FiscalDocument[] }) {
    const [cancelling, setCancelling] = useState<FiscalDocument | null>(null);
    const { data, setData, post, processing, errors, reset } = useForm({ reason: '' });

    const refresh = (document: FiscalDocument) => {
        router.post(route('app.fiscal-documents.refresh', document.id), {}, { preserveScroll: true });
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

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ReceiptText} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Notas fiscais</h2>
                </div>
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
                                                <TableCell>{sourceLabel(document)}</TableCell>
                                                <TableCell>{documentTargetLabel(document)}</TableCell>
                                                <TableCell>{document.number || '-'}</TableCell>
                                                <TableCell>
                                                    <Badge variant={status.variant}>{status.label}</Badge>
                                                    {document.error_message && (
                                                        <p className="text-destructive mt-1 max-w-xs text-xs">{document.error_message}</p>
                                                    )}
                                                </TableCell>
                                                <TableCell>
                                                    {document.issued_at ? new Date(document.issued_at).toLocaleDateString('pt-BR') : '-'}
                                                </TableCell>
                                                <TableCell className="min-w-[180px]">
                                                    <div className="flex flex-wrap justify-end gap-2">
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
                                                        {document.pdf_url && (
                                                            <Button size="icon" variant="outline" asChild title="Abrir PDF">
                                                                <a
                                                                    href={document.pdf_url}
                                                                    target="_blank"
                                                                    rel="noopener noreferrer"
                                                                    aria-label="Abrir PDF"
                                                                >
                                                                    <FileText className="h-4 w-4" />
                                                                </a>
                                                            </Button>
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
