import { toastError } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { maskMoney, maskMoneyDot } from '@/Utils/mask';
import { Head, useForm, usePage } from '@inertiajs/react';
import { BanknoteArrowDown, FileText, Loader2, WalletCards } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Caixa',
        href: '#',
    },
];

const money = (value: number | string | null | undefined) =>
    new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));

const activeWithdrawalsTotal = (withdrawals: any[] = []) =>
    withdrawals.reduce((sum: number, withdrawal: any) => (withdrawal.cancelled_at ? sum : sum + Number(withdrawal.amount || 0)), 0);

export default function CashierIndex({ currentSession, sessions, openTotals }: any) {
    const { company } = usePage().props as any;
    const openingBalance = Number(currentSession?.opening_balance || 0);
    const completedSales = Number(openTotals?.completed_sales || 0);
    const cancelledSales = Number(openTotals?.cancelled_sales || 0);
    const orderPayments = Number(openTotals?.order_payments || 0);
    const cashEntries = Number(openTotals?.cash_entries || 0);
    const totalReceived = Number(openTotals?.total_received || 0);
    const withdrawalsTotal = Number(openTotals?.withdrawals || 0);
    const currentExpectedBalance = Number(openTotals?.current_expected_balance ?? openingBalance + totalReceived - withdrawalsTotal);
    const openForm = useForm({
        opening_balance: '',
        notes: '',
    });
    const withdrawalForm = useForm({
        amount: '',
        description: '',
    });
    const cancelWithdrawalForm = useForm({
        cancellation_reason: '',
    });

    const closeForm = useForm({
        closing_balance: '',
        manual_entries: '',
        manual_exits: '',
        closing_notes: '',
    });
    const [loadingPdfId, setLoadingPdfId] = useState<number | null>(null);
    const [withdrawalModalOpen, setWithdrawalModalOpen] = useState(false);
    const [selectedWithdrawal, setSelectedWithdrawal] = useState<any | null>(null);
    const manualEntries = Number(closeForm.data.manual_entries || 0);
    const manualExits = Number(closeForm.data.manual_exits || 0);
    const countedBalance = Number(closeForm.data.closing_balance || 0);
    const expectedClosingBalance = openingBalance + totalReceived + manualEntries - manualExits - withdrawalsTotal;
    const closingDifference = countedBalance - expectedClosingBalance;

    const handleOpen = (e: any) => {
        e.preventDefault();
        openForm.post(route('app.cashier.open'));
    };

    const handleClose = (e: any) => {
        e.preventDefault();

        if (!String(closeForm.data.closing_balance ?? '').trim()) {
            closeForm.setError('closing_balance', 'Informe o saldo contado para fechar o caixa.');
            return;
        }

        closeForm.post(route('app.cashier.close', currentSession.id));
    };

    const handleWithdrawal = (e: any) => {
        e.preventDefault();

        withdrawalForm.post(route('app.cashier.withdrawal', currentSession.id), {
            preserveScroll: true,
            onSuccess: () => {
                withdrawalForm.reset();
            },
        });
    };

    const handleCancelWithdrawal = (e: any) => {
        e.preventDefault();

        if (!currentSession || !selectedWithdrawal) return;

        cancelWithdrawalForm.post(route('app.cashier.withdrawal.cancel', [currentSession.id, selectedWithdrawal.id]), {
            preserveScroll: true,
            onSuccess: () => {
                cancelWithdrawalForm.reset();
                setSelectedWithdrawal(null);
            },
        });
    };

    const handleGeneratePDF = async (session: any) => {
        if (!session || session.status !== 'closed') return;

        setLoadingPdfId(session.id);
        try {
            // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o documento.
            const [{ pdf }, { default: CashierDailyReportPDF }] = await Promise.all([
                import('@react-pdf/renderer'),
                import('@/pages/app/reports/pdf/CashierDailyReportPDF'),
            ]);
            const blob = await pdf(<CashierDailyReportPDF session={session} company={company} />).toBlob();
            const url = URL.createObjectURL(blob);
            window.open(url, '_blank');
        } catch (error) {
            console.error('Erro ao gerar PDF de fechamento diário:', error);
            toastError('Erro ao gerar o PDF de fechamento diário.');
        } finally {
            setLoadingPdfId(null);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Caixa" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={WalletCards} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Caixa</h2>
                </div>
            </div>

            <div className="grid gap-4 p-4 lg:grid-cols-3">
                <Card>
                    <CardHeader>
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <CardDescription>Status atual</CardDescription>
                                <CardTitle>{currentSession ? 'Caixa aberto' : 'Caixa fechado'}</CardTitle>
                            </div>
                            {currentSession && (
                                <Button type="button" size="sm" onClick={() => setWithdrawalModalOpen(true)}>
                                    <BanknoteArrowDown className="size-4" />
                                    Registrar sangria
                                </Button>
                            )}
                        </div>
                    </CardHeader>
                    <CardContent className="text-sm">
                        {currentSession ? (
                            <div className="grid gap-2 sm:grid-cols-2">
                                <div>
                                    <div className="text-muted-foreground">Abertura</div>
                                    <div>{moment(currentSession.opened_at).format('DD/MM/YYYY HH:mm')}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Saldo inicial</div>
                                    <div>{money(openingBalance)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Vendas concluídas</div>
                                    <div>{money(completedSales)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Pagamentos de OS</div>
                                    <div>{money(orderPayments)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Atendimentos</div>
                                    <div>{money(cashEntries)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Vendas canceladas</div>
                                    <div>{money(cancelledSales)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Saldo esperado atual</div>
                                    <div className="font-medium">{money(currentExpectedBalance)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Sangrias registradas</div>
                                    <div>{money(withdrawalsTotal)}</div>
                                </div>
                                <div>
                                    <div className="text-muted-foreground">Total recebido</div>
                                    <div className="font-medium">{money(totalReceived)}</div>
                                </div>
                            </div>
                        ) : (
                            <div>Nenhum caixa aberto no momento.</div>
                        )}
                    </CardContent>
                </Card>

                <Card className="lg:col-span-2">
                    <CardHeader>
                        <CardTitle>{currentSession ? 'Fechar caixa' : 'Abrir caixa'}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {!currentSession ? (
                            <form onSubmit={handleOpen} className="grid gap-4 md:grid-cols-2">
                                <div className="space-y-2">
                                    <Label htmlFor="opening_balance">Saldo inicial</Label>
                                    <Input
                                        id="opening_balance"
                                        type="text"
                                        inputMode="numeric"
                                        placeholder="0,00"
                                        value={maskMoney(openForm.data.opening_balance)}
                                        onChange={(e) => openForm.setData('opening_balance', maskMoneyDot(e.target.value))}
                                    />
                                    <InputError message={openForm.errors.opening_balance} />
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="notes">Observações</Label>
                                    <Textarea id="notes" value={openForm.data.notes} onChange={(e) => openForm.setData('notes', e.target.value)} />
                                    <InputError message={openForm.errors.notes} />
                                </div>
                                <div className="flex justify-end md:col-span-2">
                                    <Button type="submit" disabled={openForm.processing}>
                                        Abrir caixa
                                    </Button>
                                </div>
                            </form>
                        ) : (
                            <form onSubmit={handleClose} className="grid gap-4 md:grid-cols-2">
                                <div className="bg-card rounded-lg border p-4 text-sm md:col-span-2">
                                    <div className="grid gap-3 md:grid-cols-4">
                                        <div>
                                            <div className="text-muted-foreground">Saldo inicial</div>
                                            <div className="font-medium">{money(openingBalance)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Total recebido</div>
                                            <div className="font-medium">{money(totalReceived)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Sangrias registradas</div>
                                            <div className="font-medium">{money(withdrawalsTotal)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Saldo esperado</div>
                                            <div className="font-medium">{money(expectedClosingBalance)}</div>
                                        </div>
                                    </div>
                                    <p className="text-muted-foreground mt-3 text-xs">
                                        O saldo esperado considera saldo inicial, vendas, pagamentos de OS, entradas manuais, saídas manuais e
                                        sangrias registradas.
                                    </p>
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="closing_balance">Saldo contado no fechamento</Label>
                                    <Input
                                        id="closing_balance"
                                        type="text"
                                        inputMode="numeric"
                                        placeholder="0,00"
                                        value={maskMoney(closeForm.data.closing_balance)}
                                        onChange={(e) => {
                                            closeForm.clearErrors('closing_balance');
                                            closeForm.setData('closing_balance', maskMoneyDot(e.target.value));
                                        }}
                                    />
                                    <InputError message={closeForm.errors.closing_balance} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="manual_entries">Entradas manuais</Label>
                                    <Input
                                        id="manual_entries"
                                        type="text"
                                        inputMode="numeric"
                                        placeholder="0,00"
                                        value={maskMoney(closeForm.data.manual_entries)}
                                        onChange={(e) => closeForm.setData('manual_entries', maskMoneyDot(e.target.value))}
                                    />
                                    <InputError message={closeForm.errors.manual_entries} />
                                </div>
                                <div className="space-y-2">
                                    <Label htmlFor="manual_exits">Saídas manuais</Label>
                                    <Input
                                        id="manual_exits"
                                        type="text"
                                        inputMode="numeric"
                                        placeholder="0,00"
                                        value={maskMoney(closeForm.data.manual_exits)}
                                        onChange={(e) => closeForm.setData('manual_exits', maskMoneyDot(e.target.value))}
                                    />
                                    <InputError message={closeForm.errors.manual_exits} />
                                </div>
                                <div className="bg-card rounded-lg border p-4 text-sm md:col-span-2">
                                    <div className="grid gap-3 md:grid-cols-5">
                                        <div>
                                            <div className="text-muted-foreground">Entradas manuais</div>
                                            <div>{money(manualEntries)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Saídas manuais</div>
                                            <div>{money(manualExits)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Sangrias</div>
                                            <div>{money(withdrawalsTotal)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Saldo contado</div>
                                            <div>{money(countedBalance)}</div>
                                        </div>
                                        <div>
                                            <div className="text-muted-foreground">Diferença</div>
                                            <div className="font-medium">{money(closingDifference)}</div>
                                        </div>
                                    </div>
                                </div>
                                <div className="space-y-2 md:col-span-2">
                                    <Label htmlFor="closing_notes">Observações do fechamento</Label>
                                    <Textarea
                                        id="closing_notes"
                                        value={closeForm.data.closing_notes}
                                        onChange={(e) => closeForm.setData('closing_notes', e.target.value)}
                                    />
                                    <InputError message={closeForm.errors.closing_notes} />
                                </div>
                                <div className="flex justify-end md:col-span-2">
                                    <Button type="submit" disabled={closeForm.processing}>
                                        Fechar caixa
                                    </Button>
                                </div>
                            </form>
                        )}
                    </CardContent>
                </Card>
            </div>

            {currentSession && (
                <Dialog open={withdrawalModalOpen} onOpenChange={setWithdrawalModalOpen}>
                    <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-3xl">
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <BanknoteArrowDown className="size-5" />
                                Registrar sangria
                            </DialogTitle>
                            <DialogDescription>Use quando retirar dinheiro do caixa durante o expediente.</DialogDescription>
                        </DialogHeader>

                        <form onSubmit={handleWithdrawal} className="grid gap-4 md:grid-cols-2">
                            <div className="space-y-2">
                                <Label htmlFor="withdrawal_amount">Valor da sangria</Label>
                                <Input
                                    id="withdrawal_amount"
                                    type="text"
                                    inputMode="numeric"
                                    placeholder="0,00"
                                    value={maskMoney(withdrawalForm.data.amount)}
                                    onChange={(e) => withdrawalForm.setData('amount', maskMoneyDot(e.target.value))}
                                />
                                <InputError message={withdrawalForm.errors.amount} />
                            </div>
                            <div className="space-y-2 md:col-span-2">
                                <Label htmlFor="withdrawal_description">Motivo</Label>
                                <Textarea
                                    id="withdrawal_description"
                                    value={withdrawalForm.data.description}
                                    onChange={(e) => withdrawalForm.setData('description', e.target.value)}
                                    placeholder="Ex.: retirada para cofre, depósito bancário ou redução de numerário."
                                />
                                <InputError message={withdrawalForm.errors.description} />
                            </div>
                            <DialogFooter className="gap-2 md:col-span-2">
                                <Button type="button" variant="outline" onClick={() => setWithdrawalModalOpen(false)}>
                                    Fechar
                                </Button>
                                <Button type="submit" disabled={withdrawalForm.processing}>
                                    Registrar sangria
                                </Button>
                            </DialogFooter>
                        </form>

                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Data</TableHead>
                                        <TableHead>Motivo</TableHead>
                                        <TableHead className="text-right">Valor</TableHead>
                                        <TableHead className="text-right">Ações</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {currentSession.withdrawals?.length ? (
                                        currentSession.withdrawals.map((withdrawal: any) => (
                                            <TableRow
                                                key={withdrawal.id}
                                                className={withdrawal.cancelled_at ? 'bg-muted/40 text-muted-foreground' : undefined}
                                            >
                                                <TableCell>{moment(withdrawal.created_at).format('DD/MM/YYYY HH:mm')}</TableCell>
                                                <TableCell>
                                                    <div className="flex flex-wrap items-center gap-2">
                                                        <span className="font-medium">{withdrawal.description}</span>
                                                        {withdrawal.cancelled_at && <Badge variant="secondary">Cancelada</Badge>}
                                                    </div>
                                                    <div className="text-muted-foreground text-xs">{withdrawal.user?.name || '-'}</div>
                                                    {withdrawal.cancelled_at && (
                                                        <div className="text-muted-foreground mt-1 text-xs">
                                                            Cancelada por {withdrawal.cancelled_by?.name || '-'} em{' '}
                                                            {moment(withdrawal.cancelled_at).format('DD/MM/YYYY HH:mm')}
                                                            {withdrawal.cancellation_reason ? ` - ${withdrawal.cancellation_reason}` : ''}
                                                        </div>
                                                    )}
                                                </TableCell>
                                                <TableCell className={withdrawal.cancelled_at ? 'text-right line-through' : 'text-right font-medium'}>
                                                    {money(withdrawal.amount)}
                                                </TableCell>
                                                <TableCell className="text-right">
                                                    {!withdrawal.cancelled_at && (
                                                        <Button
                                                            type="button"
                                                            variant="outline"
                                                            size="sm"
                                                            onClick={() => setSelectedWithdrawal(withdrawal)}
                                                        >
                                                            Cancelar
                                                        </Button>
                                                    )}
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell colSpan={4} className="text-center">
                                                Nenhuma sangria registrada neste caixa.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                            </Table>
                        </div>
                    </DialogContent>
                </Dialog>
            )}

            {currentSession && (
                <Dialog
                    open={!!selectedWithdrawal}
                    onOpenChange={(open) => {
                        if (!open) {
                            cancelWithdrawalForm.reset();
                            setSelectedWithdrawal(null);
                        }
                    }}
                >
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>Cancelar sangria</DialogTitle>
                            <DialogDescription>
                                A sangria continuará no histórico, mas deixará de contar no saldo esperado do caixa.
                            </DialogDescription>
                        </DialogHeader>

                        <form onSubmit={handleCancelWithdrawal} className="grid gap-4">
                            <div className="rounded-lg border p-3 text-sm">
                                <div className="text-muted-foreground">Valor registrado</div>
                                <div className="font-medium">{money(selectedWithdrawal?.amount)}</div>
                            </div>
                            <div className="space-y-2">
                                <Label htmlFor="cancellation_reason">Motivo do cancelamento</Label>
                                <Textarea
                                    id="cancellation_reason"
                                    value={cancelWithdrawalForm.data.cancellation_reason}
                                    onChange={(e) => cancelWithdrawalForm.setData('cancellation_reason', e.target.value)}
                                    placeholder="Ex.: valor digitado incorretamente."
                                />
                                <InputError message={cancelWithdrawalForm.errors.cancellation_reason} />
                            </div>
                            <DialogFooter>
                                <Button type="button" variant="outline" onClick={() => setSelectedWithdrawal(null)}>
                                    Voltar
                                </Button>
                                <Button type="submit" variant="destructive" disabled={cancelWithdrawalForm.processing}>
                                    Cancelar sangria
                                </Button>
                            </DialogFooter>
                        </form>
                    </DialogContent>
                </Dialog>
            )}

            <div className="p-4">
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>ID</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Abertura</TableHead>
                                <TableHead>Fechamento</TableHead>
                                <TableHead>Saldo inicial</TableHead>
                                <TableHead>Sangrias</TableHead>
                                <TableHead>Saldo esperado</TableHead>
                                <TableHead>Saldo contado</TableHead>
                                <TableHead>Diferença</TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sessions?.data?.length ? (
                                sessions.data.map((session: any) => (
                                    <TableRow key={session.id}>
                                        <TableCell>{session.id}</TableCell>
                                        <TableCell>
                                            {session.status === 'open' ? <Badge>Aberto</Badge> : <Badge variant="secondary">Fechado</Badge>}
                                        </TableCell>
                                        <TableCell>{moment(session.opened_at).format('DD/MM/YYYY HH:mm')}</TableCell>
                                        <TableCell>{session.closed_at ? moment(session.closed_at).format('DD/MM/YYYY HH:mm') : '-'}</TableCell>
                                        <TableCell>{money(session.opening_balance)}</TableCell>
                                        <TableCell>{money(activeWithdrawalsTotal(session.withdrawals || []))}</TableCell>
                                        <TableCell>{money(session.expected_balance)}</TableCell>
                                        <TableCell>{money(session.closing_balance)}</TableCell>
                                        <TableCell>{money(session.difference)}</TableCell>
                                        <TableCell className="flex justify-end">
                                            {session.status === 'closed' && (
                                                <Button
                                                    type="button"
                                                    onClick={() => handleGeneratePDF(session)}
                                                    disabled={loadingPdfId === session.id}
                                                    className="bg-blue-600 text-white hover:bg-blue-700"
                                                >
                                                    {loadingPdfId === session.id ? (
                                                        <Loader2 className="size-4 animate-spin" />
                                                    ) : (
                                                        <FileText className="size-4" />
                                                    )}
                                                    Relatório PDF
                                                </Button>
                                            )}
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={10} className="text-center">
                                        Nenhum fechamento registrado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
