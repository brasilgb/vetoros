import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import { ArrowLeft, FileText, ReceiptText, RefreshCcw, Send, Undo2, Wallet } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

type Payment = {
    id: number;
    amount: number;
    paid_at: string | null;
    payment_method: string;
    source: string;
    received_by: string | null;
    notes: string | null;
    reversed_at: string | null;
    reversed_by: string | null;
    reversal_reason: string | null;
};

type Delivery = { status: string; email: string | null; origin: string; error: string | null; sent_by: string | null; created_at: string | null };

type Invoice = {
    id: number;
    status: string;
    number: string | null;
    provider_reference: string | null;
    access_key: string | null;
    issued_at: string | null;
    error_message: string | null;
    pdf_url: string | null;
    xml_url: string | null;
    can_refresh: boolean;
    can_send: boolean;
    delivery: Delivery | null;
    deliveries_count: number;
};

type Charge = {
    id: number;
    description: string | null;
    due_date: string | null;
    total_amount: number;
    paid_amount: number;
    balance_amount: number;
    status: 'pending' | 'partial' | 'paid' | 'cancelled';
    last_paid_at: string | null;
    payments: Payment[];
    invoice: Invoice | null;
    can_emit: boolean;
};

type HistoryEntry = { id: number; action: string; data: Record<string, unknown> | null; user: string | null; created_at: string | null };

type Props = {
    contract: {
        id: number;
        contract_number: number | null;
        description: string;
        status: string;
        auto_issue_invoice: boolean;
        auto_send_invoice: boolean;
        customer: { id: number; name: string; email: string | null; cpfcnpj: string | null } | null;
    };
    charges: Charge[];
    history: HistoryEntry[];
    paymentMethods: string[];
    canFiscal: boolean;
    invoiceBlocker: string | null;
};

const financialStatus: Record<Charge['status'], { label: string; className: string }> = {
    pending: { label: 'Em aberto', className: 'bg-amber-100 text-amber-800 dark:bg-amber-950/40 dark:text-amber-300' },
    partial: { label: 'Parcial', className: 'bg-sky-100 text-sky-800 dark:bg-sky-950/40 dark:text-sky-300' },
    paid: { label: 'Quitada', className: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-950/40 dark:text-emerald-300' },
    cancelled: { label: 'Cancelada', className: 'bg-muted text-muted-foreground' },
};

const invoiceStatus: Record<string, string> = {
    processing: 'Em processamento',
    contingency: 'Em contingência',
    authorized: 'Autorizada',
    rejected: 'Rejeitada',
    denied: 'Denegada',
    cancelled: 'Cancelada',
    failed: 'Falhou',
};

const methodLabels: Record<string, string> = { pix: 'Pix', cartao: 'Cartão', dinheiro: 'Dinheiro', transferencia: 'Transferência', boleto: 'Boleto' };

const historyLabels: Record<string, string> = {
    created: 'Contrato criado',
    updated: 'Contrato alterado',
    billed: 'Cobrança gerada',
    payment_registered: 'Recebimento registrado',
    payment_reversed: 'Recebimento estornado',
    invoice_queued: 'NFS-e enviada para a fila de emissão',
    invoice_skipped: 'Emissão automática não executada',
    invoice_failed: 'Falha na emissão da NFS-e',
    invoice_processing: 'NFS-e em processamento',
    invoice_authorized: 'NFS-e autorizada',
    invoice_rejected: 'NFS-e rejeitada',
    invoice_denied: 'NFS-e denegada',
    invoice_cancelled: 'NFS-e cancelada',
    invoice_contingency: 'NFS-e em contingência',
};

function money(value: number) {
    return Number(value || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
}

function date(value: string | null, withTime = false) {
    return value ? moment(value).format(withTime ? 'DD/MM/YYYY HH:mm' : 'DD/MM/YYYY') : '-';
}

export default function MaintenanceContractCharges({ contract, charges, history, paymentMethods, canFiscal, invoiceBlocker }: Props) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Painel', href: route('app.dashboard') },
        { title: 'Contratos de manutenção', href: route('app.maintenance-contracts.index') },
        { title: `Cobranças do contrato ${contract.contract_number ?? contract.id}`, href: '#' },
    ];

    const [paying, setPaying] = useState<Charge | null>(null);
    const paymentForm = useForm({ amount: '', paid_at: '', payment_method: paymentMethods[0] ?? 'pix', notes: '' });

    const openPayment = (charge: Charge) => {
        paymentForm.setData({
            amount: charge.balance_amount.toFixed(2),
            paid_at: moment().format('YYYY-MM-DDTHH:mm'),
            payment_method: paymentMethods[0] ?? 'pix',
            notes: '',
        });
        paymentForm.clearErrors();
        setPaying(charge);
    };

    const submitPayment = (event: React.FormEvent) => {
        event.preventDefault();
        if (!paying) return;
        paymentForm.post(route('app.maintenance-contracts.charges.payments.store', [contract.id, paying.id]), {
            preserveScroll: true,
            onSuccess: () => setPaying(null),
        });
    };

    const reverse = (payment: Payment) => {
        const reason = window.prompt(`Motivo do estorno de ${money(payment.amount)}:`);
        if (!reason) return;
        router.post(route('app.maintenance-contracts.payments.reverse', [contract.id, payment.id]), { reason }, { preserveScroll: true });
    };

    const emit = (charge: Charge) => {
        if (!window.confirm(`Emitir a NFS-e de ${money(charge.total_amount)} para ${contract.customer?.name ?? 'o cliente'}?`)) return;
        router.post(route('app.maintenance-contracts.charges.invoice', [contract.id, charge.id]), {}, { preserveScroll: true });
    };

    const refresh = (invoice: Invoice) => router.post(route('app.fiscal-documents.refresh', invoice.id), {}, { preserveScroll: true });

    const send = (invoice: Invoice) => {
        if (!window.confirm(`Enviar a nota para ${contract.customer?.email ?? 'o e-mail do cliente'}? Nenhuma nova nota será emitida.`)) return;
        router.post(route('app.maintenance-contracts.invoices.send', [contract.id, invoice.id]), {}, { preserveScroll: true });
    };

    const needsReview = charges.filter((charge) => charge.invoice?.status === 'authorized' && charge.status !== 'paid');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Cobranças do contrato" />

            <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ReceiptText} className="h-8 w-8" />
                    <div>
                        <h2 className="text-xl font-semibold tracking-tight">
                            Contrato nº {contract.contract_number ?? contract.id} — {contract.description}
                        </h2>
                        <p className="text-muted-foreground text-sm">
                            Cliente: {contract.customer?.name ?? '-'} · e-mail {contract.customer?.email || 'não informado'} · CPF/CNPJ{' '}
                            {contract.customer?.cpfcnpj || 'não informado'}
                        </p>
                    </div>
                </div>
                <Button variant="outline" asChild>
                    <Link href={route('app.maintenance-contracts.index')}>
                        <ArrowLeft className="h-4 w-4" />
                        Voltar
                    </Link>
                </Button>
            </div>

            <div className="space-y-4 p-4">
                <div className="flex flex-wrap gap-2 text-sm">
                    <Badge variant="outline">Emissão automática: {contract.auto_issue_invoice ? 'ligada' : 'desligada'}</Badge>
                    <Badge variant="outline">Envio automático ao cliente: {contract.auto_send_invoice ? 'ligado' : 'desligado'}</Badge>
                    {invoiceBlocker && <span className="text-amber-700 dark:text-amber-400">Emissão fiscal indisponível: {invoiceBlocker}</span>}
                </div>

                {needsReview.length > 0 && (
                    <div className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950/40 dark:text-amber-200">
                        {needsReview.length === 1 ? 'Uma cobrança tem' : `${needsReview.length} cobranças têm`} NFS-e autorizada sem estar quitada
                        (recebimento estornado). Avalie com a contabilidade se a nota deve ser cancelada em Notas fiscais.
                    </div>
                )}

                <Card>
                    <CardTitle className="border-b px-6 pb-4">Cobranças</CardTitle>
                    <CardContent className="overflow-x-auto pt-4">
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>Vencimento</TableHead>
                                    <TableHead>Valor</TableHead>
                                    <TableHead>Recebido</TableHead>
                                    <TableHead>Saldo</TableHead>
                                    <TableHead>Situação</TableHead>
                                    <TableHead>NFS-e</TableHead>
                                    <TableHead>Envio ao cliente</TableHead>
                                    <TableHead className="text-right">Ações</TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {charges.length === 0 && (
                                    <TableRow>
                                        <TableCell colSpan={8} className="h-16 text-center">
                                            Nenhuma cobrança gerada ainda.
                                        </TableCell>
                                    </TableRow>
                                )}
                                {charges.map((charge) => (
                                    <ChargeRows
                                        key={charge.id}
                                        charge={charge}
                                        canFiscal={canFiscal}
                                        onPay={openPayment}
                                        onReverse={reverse}
                                        onEmit={emit}
                                        onRefresh={refresh}
                                        onSend={send}
                                    />
                                ))}
                            </TableBody>
                        </Table>
                    </CardContent>
                </Card>

                <Card>
                    <CardTitle className="border-b px-6 pb-4">Histórico financeiro e fiscal</CardTitle>
                    <CardContent className="pt-4">
                        {history.length === 0 ? (
                            <p className="text-muted-foreground text-sm">Sem registros.</p>
                        ) : (
                            <ul className="space-y-2 text-sm">
                                {history.map((entry) => (
                                    <li key={entry.id} className="flex flex-wrap justify-between gap-2 border-b pb-2 last:border-b-0">
                                        <span>
                                            <strong>{historyLabels[entry.action] ?? entry.action}</strong>
                                            {typeof entry.data?.amount === 'number' && ` · ${money(entry.data.amount as number)}`}
                                            {typeof entry.data?.number === 'string' && ` · nº ${entry.data.number}`}
                                            {typeof entry.data?.reason === 'string' && ` · ${entry.data.reason}`}
                                            {typeof entry.data?.error === 'string' && (
                                                <span className="text-destructive"> · {entry.data.error as string}</span>
                                            )}
                                        </span>
                                        <span className="text-muted-foreground">
                                            {date(entry.created_at, true)} {entry.user ? `· ${entry.user}` : '· sistema'}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog open={!!paying} onOpenChange={(open) => (!open ? setPaying(null) : null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Registrar recebimento</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitPayment} className="space-y-4">
                        <p className="text-muted-foreground text-sm">
                            {paying?.description} · saldo {money(paying?.balance_amount ?? 0)}. O valor entra no caixa aberto.
                        </p>
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Valor recebido</Label>
                            <Input
                                id="amount"
                                type="number"
                                step="0.01"
                                min="0.01"
                                value={paymentForm.data.amount}
                                onChange={(e) => paymentForm.setData('amount', e.target.value)}
                            />
                            <InputError message={paymentForm.errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="paid_at">Data do recebimento</Label>
                            <Input
                                id="paid_at"
                                type="datetime-local"
                                value={paymentForm.data.paid_at}
                                onChange={(e) => paymentForm.setData('paid_at', e.target.value)}
                            />
                            <InputError message={paymentForm.errors.paid_at} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="payment_method">Forma de pagamento</Label>
                            <select
                                id="payment_method"
                                className="border-input bg-background h-9 rounded-md border px-3 text-sm"
                                value={paymentForm.data.payment_method}
                                onChange={(e) => paymentForm.setData('payment_method', e.target.value)}
                            >
                                {paymentMethods.map((method) => (
                                    <option key={method} value={method}>
                                        {methodLabels[method] ?? method}
                                    </option>
                                ))}
                            </select>
                            <InputError message={paymentForm.errors.payment_method} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="notes">Observações</Label>
                            <Textarea
                                id="notes"
                                rows={2}
                                value={paymentForm.data.notes}
                                onChange={(e) => paymentForm.setData('notes', e.target.value)}
                            />
                        </div>
                        <DialogFooter>
                            <Button type="button" variant="outline" onClick={() => setPaying(null)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={paymentForm.processing}>
                                Registrar recebimento
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}

type RowProps = {
    charge: Charge;
    canFiscal: boolean;
    onPay: (charge: Charge) => void;
    onReverse: (payment: Payment) => void;
    onEmit: (charge: Charge) => void;
    onRefresh: (invoice: Invoice) => void;
    onSend: (invoice: Invoice) => void;
};

function ChargeRows({ charge, canFiscal, onPay, onReverse, onEmit, onRefresh, onSend }: RowProps) {
    const invoice = charge.invoice;
    const delivery = invoice?.delivery;

    return (
        <>
            <TableRow>
                <TableCell>{date(charge.due_date)}</TableCell>
                <TableCell>{money(charge.total_amount)}</TableCell>
                <TableCell>{money(charge.paid_amount)}</TableCell>
                <TableCell>{money(charge.balance_amount)}</TableCell>
                <TableCell>
                    <Badge variant="outline" className={financialStatus[charge.status]?.className}>
                        {financialStatus[charge.status]?.label ?? charge.status}
                    </Badge>
                </TableCell>
                <TableCell className="text-sm">
                    {invoice ? (
                        <div className="grid gap-0.5">
                            <span className="font-medium">{invoiceStatus[invoice.status] ?? invoice.status}</span>
                            {invoice.number && <span>nº {invoice.number}</span>}
                            {invoice.provider_reference && <span className="text-muted-foreground text-xs">id {invoice.provider_reference}</span>}
                            {invoice.issued_at && <span className="text-muted-foreground text-xs">autorizada em {date(invoice.issued_at)}</span>}
                            {invoice.error_message && <span className="text-destructive text-xs">{invoice.error_message}</span>}
                        </div>
                    ) : (
                        <span className="text-muted-foreground">Não emitida</span>
                    )}
                </TableCell>
                <TableCell className="text-sm">
                    {delivery ? (
                        <div className="grid gap-0.5">
                            <span className={delivery.status === 'sent' ? '' : 'text-destructive'}>
                                {delivery.status === 'sent' ? 'Enviada' : 'Falhou'} ({delivery.origin === 'automatic' ? 'automático' : 'reenvio'})
                            </span>
                            <span className="text-muted-foreground text-xs">
                                {delivery.email ?? 'sem e-mail'} · {date(delivery.created_at, true)}
                            </span>
                            {delivery.error && <span className="text-destructive text-xs">{delivery.error}</span>}
                        </div>
                    ) : (
                        <span className="text-muted-foreground">-</span>
                    )}
                </TableCell>
                <TableCell className="text-right">
                    <div className="flex flex-wrap justify-end gap-2">
                        {charge.balance_amount > 0 && charge.status !== 'cancelled' && (
                            <Button size="sm" onClick={() => onPay(charge)}>
                                <Wallet className="h-4 w-4" />
                                Receber
                            </Button>
                        )}
                        {canFiscal && charge.can_emit && (
                            <Button size="sm" variant="outline" onClick={() => onEmit(charge)}>
                                <FileText className="h-4 w-4" />
                                {invoice ? 'Reprocessar NFS-e' : 'Emitir NFS-e'}
                            </Button>
                        )}
                        {invoice?.can_refresh && (
                            <Button size="icon" variant="outline" title="Consultar status da nota" onClick={() => onRefresh(invoice)}>
                                <RefreshCcw className="h-4 w-4" />
                            </Button>
                        )}
                        {invoice?.pdf_url && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={invoice.pdf_url} target="_blank" rel="noopener noreferrer">
                                    PDF
                                </a>
                            </Button>
                        )}
                        {invoice?.xml_url && (
                            <Button size="sm" variant="outline" asChild>
                                <a href={invoice.xml_url}>XML</a>
                            </Button>
                        )}
                        {invoice?.can_send && (
                            <Button size="sm" variant="outline" title="Reenviar a mesma nota ao cliente" onClick={() => onSend(invoice)}>
                                <Send className="h-4 w-4" />
                                {invoice.deliveries_count > 0 ? 'Reenviar' : 'Enviar'}
                            </Button>
                        )}
                    </div>
                </TableCell>
            </TableRow>
            {charge.payments.map((payment) => (
                <TableRow key={`payment-${payment.id}`} className="bg-muted/30 text-sm">
                    <TableCell colSpan={7} className="pl-8">
                        <span className={payment.reversed_at ? 'line-through' : ''}>
                            {money(payment.amount)} em {date(payment.paid_at, true)} ·{' '}
                            {methodLabels[payment.payment_method] ?? payment.payment_method} ·{' '}
                            {payment.source === 'integration' ? 'pagamento integrado' : `baixa manual por ${payment.received_by ?? '-'}`}
                        </span>
                        {payment.reversed_at && (
                            <span className="text-destructive">
                                {' '}
                                · estornado em {date(payment.reversed_at, true)} por {payment.reversed_by ?? '-'}: {payment.reversal_reason}
                            </span>
                        )}
                    </TableCell>
                    <TableCell className="text-right">
                        {!payment.reversed_at && (
                            <Button size="sm" variant="ghost" onClick={() => onReverse(payment)}>
                                <Undo2 className="h-4 w-4" />
                                Estornar
                            </Button>
                        )}
                    </TableCell>
                </TableRow>
            ))}
        </>
    );
}
