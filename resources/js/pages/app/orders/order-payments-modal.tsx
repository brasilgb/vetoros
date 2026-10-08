import InputError from '@/components/input-error';
import InvoiceModal from '@/components/Modals/InvoiceModal';
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
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { maskMoney, maskMoneyDot } from '@/Utils/mask';
import { Link, router, useForm, usePage } from '@inertiajs/react';
import { CheckCircle2, FileTextIcon, HandCoins, Mail } from 'lucide-react';
import moment from 'moment';
import { useEffect, useState } from 'react';

export default function OrderPaymentsModal({
    order,
    orderPayments = [],
    paymentSummary = null,
    defaultOpen = false,
    compactTrigger = false,
    compactTriggerClassName = 'bg-emerald-600 text-white hover:bg-emerald-700',
    compactTriggerTitle = 'Pagamentos da ordem',
}: any) {
    const { cashier } = usePage<{ cashier?: { isOpen?: boolean } }>().props;
    const [open, setOpen] = useState(defaultOpen);
    const [openInvoiceSummary, setOpenInvoiceSummary] = useState(false);
    const [loading, setLoading] = useState(false);
    const [localPayments, setLocalPayments] = useState<any[]>(orderPayments || []);
    const [pendingMobilePayment, setPendingMobilePayment] = useState<any | null>(null);
    const [paymentToRemove, setPaymentToRemove] = useState<number | null>(null);
    const [localSummary, setLocalSummary] = useState<any>(
        paymentSummary || { parts_value: 0, service_value: 0, total_order: 0, total_paid: 0, remaining: 0 },
    );

    const paymentForm = useForm({
        amount: '',
        fee_amount: '',
        payment_method: 'pix',
        paid_at: moment().format('YYYY-MM-DDTHH:mm'),
        notes: '',
    });
    const reminderForm = useForm({});
    const confirmMobilePaymentForm = useForm({});

    const remaining = Number(localSummary?.remaining || 0);
    const totalOrder = Number(localSummary?.total_order || 0);
    const totalPaid = Number(localSummary?.total_paid || 0);
    const canIssueInvoice = totalOrder > 0;
    const isDelivered = Boolean(order?.delivery_date);
    const hasMailerAvailable = Boolean(order?.can_send_payment_reminder);
    const canSendReminder = remaining > 0 && isDelivered && hasMailerAvailable;
    const isCashierOpen = Boolean(cashier?.isOpen);
    const syncPaymentAmountWithRemaining = (nextRemaining: number) => {
        const normalizedRemaining = Math.max(0, Number(nextRemaining || 0));
        const currentAmount = Number(paymentForm.data.amount || 0);

        if (normalizedRemaining <= 0) {
            paymentForm.setData('amount', '');
            return;
        }

        if (!paymentForm.data.amount || currentAmount > normalizedRemaining) {
            paymentForm.setData('amount', normalizedRemaining.toFixed(2));
        }
    };

    const financialStatus = (() => {
        if (totalOrder <= 0) return { label: 'Sem valor', variant: 'outline' as const, className: '' };
        if (remaining <= 0 && totalPaid > 0) return { label: 'Pago', variant: 'default' as const, className: '' };
        if (totalPaid > 0 && remaining > 0) {
            return {
                label: 'Parcial',
                variant: 'secondary' as const,
                className: 'bg-amber-100 text-amber-800 hover:bg-amber-100 dark:bg-amber-900/40 dark:text-amber-300',
            };
        }
        return { label: 'Em aberto', variant: 'destructive' as const, className: '' };
    })();

    const loadPaymentsData = async () => {
        setLoading(true);
        try {
            const response = await fetch(route('app.orders.payments.data', order.id), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error('Erro ao carregar pagamentos');
            const payload = await response.json();
            if (payload?.order?.can_send_payment_reminder !== undefined) {
                order.can_send_payment_reminder = payload.order.can_send_payment_reminder;
            }
            setLocalPayments(payload?.orderPayments || []);
            setPendingMobilePayment(payload?.pendingMobilePayment || null);
            setLocalSummary(
                payload?.paymentSummary || {
                    parts_value: 0,
                    service_value: 0,
                    total_order: 0,
                    total_paid: 0,
                    remaining: 0,
                },
            );
            paymentForm.clearErrors();
            syncPaymentAmountWithRemaining(Number(payload?.paymentSummary?.remaining || 0));
        } catch (error) {
            console.error(error);
        } finally {
            setLoading(false);
        }
    };

    useEffect(() => {
        if (open) {
            syncPaymentAmountWithRemaining(remaining);
        }
    }, [open, remaining]);

    useEffect(() => {
        if (open) {
            loadPaymentsData();
        }
    }, [open]);

    const handlePaymentSubmit = (e: any) => {
        e.preventDefault();
        paymentForm.post(route('app.orders.payments.store', order.id), {
            preserveScroll: true,
            onSuccess: () => {
                paymentForm.clearErrors();
                paymentForm.reset('amount', 'notes', 'fee_amount');
                paymentForm.setData('payment_method', 'pix');
                paymentForm.setData('paid_at', moment().format('YYYY-MM-DDTHH:mm'));
                loadPaymentsData();
            },
            onError: () => {
                loadPaymentsData();
            },
        });
    };

    const handleRemovePayment = (paymentId: number) => {
        router.delete(route('app.orders.payments.destroy', { order: order.id, payment: paymentId }), {
            preserveScroll: true,
            onSuccess: () => {
                paymentForm.clearErrors();
                loadPaymentsData();
            },
            onFinish: () => setPaymentToRemove(null),
        });
    };

    const handleSendReminder = () => {
        reminderForm.post(route('app.orders.payments.reminder', order.id), {
            preserveScroll: true,
            onSuccess: () => {
                loadPaymentsData();
            },
        });
    };

    const handleConfirmMobilePayment = () => {
        confirmMobilePaymentForm.post(route('app.orders.payments.mobile-confirm', order.id), {
            preserveScroll: true,
            onSuccess: () => {
                loadPaymentsData();
            },
            onError: () => {
                loadPaymentsData();
            },
        });
    };

    return (
        <>
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {compactTrigger ? (
                    <Button size="icon" className={compactTriggerClassName} title={compactTriggerTitle}>
                        <HandCoins className="h-4 w-4" />
                    </Button>
                ) : (
                    <Button className="gap-2">
                        <HandCoins className="h-4 w-4" />
                        Pagamentos
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[85vh] overflow-y-auto sm:max-w-3xl">
                <DialogHeader>
                    <DialogTitle>Pagamentos da ordem #{order?.order_number}</DialogTitle>
                    <div className="flex flex-wrap items-center gap-2">
                        <Badge variant={financialStatus.variant} className={financialStatus.className}>
                            {financialStatus.label}
                        </Badge>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            title={canIssueInvoice ? 'Resumo para emissão da NFS-e' : 'Informe um valor maior que zero na ordem para emitir NFS-e'}
                            onClick={() => setOpenInvoiceSummary(true)}
                            disabled={!canIssueInvoice}
                        >
                            <FileTextIcon className="mr-1 h-4 w-4" />
                            Emitir NFS-e
                        </Button>
                        <InvoiceModal
                            open={openInvoiceSummary}
                            onClose={() => setOpenInvoiceSummary(false)}
                            order={{
                                ...order,
                                service_cost: localSummary?.total_order ?? order?.service_cost,
                            }}
                            summary={localSummary}
                        />
                        {canSendReminder && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={handleSendReminder}
                                disabled={reminderForm.processing}
                                title="Enviar lembrete de cobrança por e-mail"
                            >
                                <Mail className="mr-1 h-4 w-4" />
                                {reminderForm.processing ? 'Enviando...' : 'Enviar lembrete'}
                            </Button>
                        )}
                    </div>
                </DialogHeader>

                {!isCashierOpen && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        O caixa diario precisa estar aberto para registrar pagamentos desta ordem.{' '}
                        <Link href={route('app.cashier.index')} className="font-medium underline">
                            Abrir caixa
                        </Link>
                    </div>
                )}

                <div className="grid gap-4 md:grid-cols-5">
                    <Card>
                        <CardContent className="p-3">
                            <div className="text-muted-foreground text-xs">Peças</div>
                            <div className="text-lg font-semibold">{maskMoney(String(localSummary?.parts_value ?? 0))}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-3">
                            <div className="text-muted-foreground text-xs">Serviço</div>
                            <div className="text-lg font-semibold">{maskMoney(String(localSummary?.service_value ?? 0))}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-3">
                            <div className="text-muted-foreground text-xs">Total a pagar</div>
                            <div className="text-lg font-semibold">{maskMoney(String(localSummary?.total_order ?? 0))}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-3">
                            <div className="text-muted-foreground text-xs">Valor pago</div>
                            <div className="text-lg font-semibold">{maskMoney(String(localSummary?.total_paid ?? 0))}</div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-3">
                            <div className="text-muted-foreground text-xs">Saldo restante</div>
                            <div
                                className={`text-lg font-semibold ${remaining > 0 ? 'text-rose-600 dark:text-rose-400' : 'text-emerald-600 dark:text-emerald-400'}`}
                            >
                                {maskMoney(String(localSummary?.remaining ?? 0))}
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {pendingMobilePayment && (
                    <div className="rounded-lg border border-sky-200 bg-sky-50 p-3 text-sm text-sky-950">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div className="font-medium">Pagamento enviado pelo app aguardando conferência</div>
                                <div className="mt-1 text-xs">
                                    {maskMoney(String(pendingMobilePayment.amount ?? 0))} • {pendingMobilePayment.payment_method || 'Não informado'} •{' '}
                                    {pendingMobilePayment.received_at ? moment(pendingMobilePayment.received_at).format('DD/MM/YYYY HH:mm') : '-'}
                                </div>
                                {pendingMobilePayment.notes ? <div className="mt-1 text-xs">{pendingMobilePayment.notes}</div> : null}
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                className="w-full bg-sky-700 text-white hover:bg-sky-800 sm:w-auto"
                                disabled={!isCashierOpen || confirmMobilePaymentForm.processing}
                                onClick={handleConfirmMobilePayment}
                            >
                                <CheckCircle2 className="mr-1 h-4 w-4" />
                                {confirmMobilePaymentForm.processing ? 'Inserindo...' : 'Inserir ao caixa'}
                            </Button>
                        </div>
                        {!isCashierOpen && <div className="mt-2 text-xs">Abra o caixa antes de confirmar este pagamento.</div>}
                    </div>
                )}

                <form onSubmit={handlePaymentSubmit} className="grid gap-4 md:grid-cols-2">
                    <div className="grid gap-2">
                        <Label htmlFor="payment_amount">Valor a pagar</Label>
                        <Input
                            id="payment_amount"
                            type="text"
                            value={maskMoney(String(paymentForm.data.amount || ''))}
                            onChange={(e) => paymentForm.setData('amount', maskMoneyDot(e.target.value))}
                            placeholder="0,00"
                        />
                        <InputError message={paymentForm.errors.amount} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_method">Forma de pagamento</Label>
                        <select
                            id="payment_method"
                            value={paymentForm.data.payment_method}
                            onChange={(e) => paymentForm.setData('payment_method', e.target.value)}
                            className="h-9 rounded-md border border-gray-300 bg-transparent px-3 text-sm"
                        >
                            <option value="pix">Pix</option>
                            <option value="cartao">Cartão</option>
                            <option value="dinheiro">Dinheiro</option>
                            <option value="transferencia">Transferência</option>
                            <option value="boleto">Boleto</option>
                        </select>
                        <InputError message={paymentForm.errors.payment_method} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_paid_at">Data/hora do pagamento</Label>
                        <Input
                            id="payment_paid_at"
                            type="datetime-local"
                            value={paymentForm.data.paid_at}
                            onChange={(e) => paymentForm.setData('paid_at', e.target.value)}
                        />
                        <InputError message={paymentForm.errors.paid_at} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_notes">Observações</Label>
                        <Input
                            id="payment_notes"
                            type="text"
                            value={paymentForm.data.notes}
                            onChange={(e) => paymentForm.setData('notes', e.target.value)}
                            placeholder="Opcional"
                        />
                        <InputError message={paymentForm.errors.notes} />
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="payment_fee_amount">Taxa cobrada (cartão, gateway etc.)</Label>
                        <Input
                            id="payment_fee_amount"
                            type="text"
                            value={paymentForm.data.fee_amount ? maskMoney(String(paymentForm.data.fee_amount)) : ''}
                            onChange={(e) => paymentForm.setData('fee_amount', maskMoneyDot(e.target.value))}
                            placeholder="Vazio usa a taxa configurada do meio"
                        />
                        <InputError message={paymentForm.errors.fee_amount} />
                    </div>

                    <div className="flex justify-end md:col-span-2">
                        <Button type="submit" disabled={paymentForm.processing || remaining <= 0 || !isCashierOpen}>
                            Registrar pagamento
                        </Button>
                    </div>
                </form>
                {remaining <= 0 && <div className="text-muted-foreground text-sm">Esta ordem já está com pagamento completo.</div>}

                <div className="space-y-2">
                    {loading ? (
                        <div className="text-muted-foreground text-sm">Carregando pagamentos...</div>
                    ) : localPayments?.length ? (
                        localPayments.map((payment: any) => (
                            <div key={payment.id} className="flex flex-col gap-3 rounded-md border p-3 sm:flex-row sm:items-center sm:justify-between">
                                <div className="flex flex-col">
                                    <span className="text-sm font-medium">{maskMoney(String(payment.amount))}</span>
                                    <span className="text-muted-foreground text-xs">
                                        {payment.payment_method} • {payment.paid_at ? moment(payment.paid_at).format('DD/MM/YYYY HH:mm') : '-'}
                                    </span>
                                    {payment.fee_amount !== null && payment.fee_amount !== undefined ? (
                                        <span className="text-muted-foreground text-xs">
                                            Taxa {maskMoney(String(payment.fee_amount))} • líquido {maskMoney(String(payment.net_amount))}
                                        </span>
                                    ) : (
                                        <span className="text-muted-foreground text-xs">Taxa não informada</span>
                                    )}
                                    {payment.notes ? <span className="text-muted-foreground text-xs">{payment.notes}</span> : null}
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="w-full sm:w-auto"
                                    onClick={() => setPaymentToRemove(payment.id)}
                                    disabled={!isCashierOpen}
                                >
                                    Remover
                                </Button>
                            </div>
                        ))
                    ) : (
                        <div className="text-muted-foreground text-sm">Nenhum pagamento registrado para esta ordem.</div>
                    )}
                </div>

                <DialogFooter>
                    <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                        Fechar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <AlertDialog open={paymentToRemove !== null} onOpenChange={(open) => !open && setPaymentToRemove(null)}>
            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Remover pagamento?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Esta ação não pode ser desfeita e o valor pago deixará de ser considerado no saldo da ordem.
                    </AlertDialogDescription>
                </AlertDialogHeader>
                <AlertDialogFooter>
                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                    <AlertDialogAction
                        className="bg-red-600 text-white hover:bg-red-700"
                        onClick={() => {
                            if (paymentToRemove !== null) handleRemovePayment(paymentToRemove);
                        }}
                    >
                        Remover
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
        </>
    );
}
