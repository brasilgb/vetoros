import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { formatBRL } from '@/lib/money';
import moment from 'moment';

interface SaleDetailsModalProps {
    isOpen: boolean;
    onClose: () => void;
    sale: {
        sales_number?: string | number;
        created_at?: string;
        customer?: { name?: string };
        payment_method?: string;
        financial_status?: string;
        paid_amount?: string | number;
        total_amount?: string | number;
        status?: string;
        cancelled_at?: string;
        cancelled_by?: { name?: string };
        cancel_reason?: string;
        fiscal_document_number?: string;
        fiscal_document_key?: string;
        fiscal_issued_at?: string;
        fiscal_registered_by?: { name?: string };
        items?: Array<{
            id?: string | number;
            quantity?: number;
            unit_price?: string | number;
            part?: { name?: string };
        }>;
        logs?: Array<{
            id?: string | number;
            action?: string;
            user?: { name?: string };
            created_at?: string;
        }>;
    } | null;
}

export default function SaleDetailsModal({ isOpen, onClose, sale }: SaleDetailsModalProps) {
    if (!sale) return null;

    const financialStatusLabel: Record<string, string> = {
        paid: 'Pago',
        partial: 'Parcial',
        pending: 'Pendente',
        cancelled: 'Cancelada',
    };

    const actionLabel: Record<string, string> = {
        created: 'Criada',
        cancelled: 'Cancelada',
        fiscal_registered: 'Comprovante fiscal registrado',
    };
    const financialStatusKey = sale.financial_status ?? '';
    const saleStatusKey = sale.status ?? '';

    return (
        <Dialog open={isOpen} onOpenChange={onClose}>
            <DialogContent className="sm:max-w-[625px]">
                <DialogHeader>
                    <DialogTitle>Detalhes da Venda #{sale.sales_number}</DialogTitle>
                    <DialogDescription>Realizada em {moment(sale.created_at).format('DD/MM/YYYY [às] HH:mm')}</DialogDescription>
                </DialogHeader>
                <div className="grid gap-4 py-4">
                    <div className="text-sm">
                        <p>
                            <strong>Cliente:</strong> {sale.customer?.name || 'Não informado'}
                        </p>
                        <p>
                            <strong>Pagamento:</strong> {sale.payment_method || 'Não informado'}
                        </p>
                        <p>
                            <strong>Status financeiro:</strong> {financialStatusLabel[financialStatusKey] || sale.financial_status || 'Não informado'}
                        </p>
                        <p>
                            <strong>Valor pago:</strong> {formatBRL(sale.paid_amount || 0)}
                        </p>
                        <p>
                            <strong>Saldo:</strong> {formatBRL(Number(sale.total_amount || 0) - Number(sale.paid_amount || 0))}
                        </p>
                        {sale.status === 'cancelled' && (
                            <>
                                <p>
                                    <strong>Cancelada em:</strong>{' '}
                                    {sale.cancelled_at ? moment(sale.cancelled_at).format('DD/MM/YYYY [às] HH:mm') : '-'}
                                </p>
                                <p>
                                    <strong>Cancelada por:</strong> {sale.cancelled_by?.name || 'Não informado'}
                                </p>
                                <p>
                                    <strong>Motivo:</strong> {sale.cancel_reason || 'Não informado'}
                                </p>
                            </>
                        )}
                        {sale.fiscal_document_number && (
                            <>
                                <p>
                                    <strong>Documento fiscal:</strong> {sale.fiscal_document_number}
                                </p>
                                <p>
                                    <strong>Chave:</strong> {sale.fiscal_document_key || '-'}
                                </p>
                                <p>
                                    <strong>Emitido em:</strong>{' '}
                                    {sale.fiscal_issued_at ? moment(sale.fiscal_issued_at).format('DD/MM/YYYY [às] HH:mm') : '-'}
                                </p>
                                <p>
                                    <strong>Registrado por:</strong> {sale.fiscal_registered_by?.name || '-'}
                                </p>
                            </>
                        )}
                    </div>

                    <div>
                        <h4 className="mb-2 font-semibold">Itens da Venda</h4>
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Produto</TableHead>
                                        <TableHead className="text-center">Qtd.</TableHead>
                                        <TableHead className="text-right">Valor Unit.</TableHead>
                                        <TableHead className="text-right">Subtotal</TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {sale.items?.map((item) => (
                                        <TableRow key={item.id}>
                                            <TableCell>{item.part?.name}</TableCell>
                                            <TableCell className="text-center">{item.quantity}</TableCell>
                                            <TableCell className="text-right">{formatBRL(item.unit_price ?? 0)}</TableCell>
                                            <TableCell className="text-right">
                                                {formatBRL(Number(item.quantity ?? 0) * Number(item.unit_price ?? 0))}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                    <TableRow className="bg-muted/50 font-bold">
                                        <TableCell colSpan={3} className="text-right">
                                            Total
                                        </TableCell>
                                        <TableCell className="text-right">{formatBRL(sale.total_amount)}</TableCell>
                                    </TableRow>
                                </TableBody>
                            </Table>
                        </div>
                    </div>

                    <div>
                        <h4 className="mb-2 font-semibold">Histórico da Venda</h4>
                        <div className="space-y-2">
                            {sale.logs?.length ? (
                                sale.logs.map((log) => (
                                    <div key={log.id} className="rounded border p-2 text-xs">
                                        <div>
                                            <strong>Ação:</strong> {actionLabel[log.action ?? ''] || log.action || saleStatusKey}
                                        </div>
                                        <div>
                                            <strong>Usuário:</strong> {log.user?.name || 'Sistema'}
                                        </div>
                                        <div>
                                            <strong>Data:</strong> {moment(log.created_at).format('DD/MM/YYYY HH:mm')}
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <div className="text-muted-foreground text-sm">Sem histórico registrado.</div>
                            )}
                        </div>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
