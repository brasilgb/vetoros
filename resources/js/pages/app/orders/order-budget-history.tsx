import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { maskMoney } from '@/Utils/mask';
import moment from 'moment';

export type OrderBudgetVersion = {
    id: number;
    version: number;
    status: string;
    effective_status: string;
    is_legacy: boolean;
    description: string | null;
    quoted_amount: string | null;
    total_amount: string | null;
    valid_until: string | null;
    sent_at: string | null;
    responded_at: string | null;
    approved_at: string | null;
    rejected_at: string | null;
    approved_by_type: string | null;
    rejected_by_type: string | null;
    rejection_reason: string | null;
    response_channel: string | null;
    items: { id: number; description: string; quantity: string; unit_price: string; total_price: string }[];
};

const STATUS_LABELS: Record<string, { label: string; className: string }> = {
    draft: { label: 'Rascunho', className: 'bg-slate-100 text-slate-700' },
    sent: { label: 'Enviado, aguardando cliente', className: 'bg-amber-100 text-amber-800' },
    approved: { label: 'Aprovado', className: 'bg-emerald-100 text-emerald-800' },
    rejected: { label: 'Recusado', className: 'bg-red-100 text-red-800' },
    expired: { label: 'Vencido', className: 'bg-orange-100 text-orange-800' },
    superseded: { label: 'Substituído por nova versão', className: 'bg-slate-100 text-slate-500' },
    legacy: { label: 'Anterior ao versionamento', className: 'bg-slate-100 text-slate-500' },
};

const formatDateTime = (value: string | null) => (value ? moment(value).format('DD/MM/YYYY HH:mm') : null);

const who = (type: string | null) => (type === 'customer' ? 'pelo cliente' : type === 'user' ? 'pela equipe' : '');

/**
 * Versões do orçamento em linguagem de negócio: "v2 enviada por R$ X em tal data, aprovada pelo cliente em tal data".
 */
export default function OrderBudgetHistory({ budgets }: { budgets: OrderBudgetVersion[] }) {
    if (!budgets?.length) {
        return (
            <Card>
                <CardTitle className="border-b px-4 py-3">Orçamento</CardTitle>
                <CardContent className="pt-4">
                    <p className="text-muted-foreground text-sm">Esta OS ainda não tem orçamento.</p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardTitle className="border-b px-4 py-3">Versões do orçamento</CardTitle>
            <CardContent className="space-y-3 pt-4">
                {budgets.map((budget, index) => {
                    const status = STATUS_LABELS[budget.effective_status] ?? { label: budget.effective_status, className: '' };
                    const isCurrent = index === 0;

                    return (
                        <div key={budget.id} className={`rounded-lg border p-3 text-sm ${isCurrent ? 'border-primary/40' : 'opacity-80'}`}>
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex items-center gap-2">
                                    <span className="font-semibold">Versão {budget.version}</span>
                                    {isCurrent && <Badge variant="outline">Atual</Badge>}
                                    <Badge className={status.className}>{status.label}</Badge>
                                </div>
                                <span className="font-semibold">R$ {maskMoney(String(budget.quoted_amount ?? 0))}</span>
                            </div>

                            {budget.description && (
                                <p className="text-muted-foreground mt-2 line-clamp-3 whitespace-pre-wrap">{budget.description}</p>
                            )}

                            <ul className="text-muted-foreground mt-2 space-y-0.5 text-xs">
                                {budget.is_legacy && (
                                    <li>Orçamento existente antes do histórico de versões: datas de envio e resposta desconhecidas.</li>
                                )}
                                {budget.sent_at && <li>Enviado ao cliente em {formatDateTime(budget.sent_at)}</li>}
                                {budget.valid_until && <li>Válido até {moment(budget.valid_until).format('DD/MM/YYYY')}</li>}
                                {budget.approved_at && (
                                    <li>
                                        Aprovado {who(budget.approved_by_type)} em {formatDateTime(budget.approved_at)}
                                    </li>
                                )}
                                {budget.rejected_at && (
                                    <li>
                                        Recusado {who(budget.rejected_by_type)} em {formatDateTime(budget.rejected_at)}
                                        {budget.rejection_reason ? ` — "${budget.rejection_reason}"` : ''}
                                    </li>
                                )}
                            </ul>

                            {budget.items?.length > 0 && (
                                <details className="mt-2">
                                    <summary className="cursor-pointer text-xs font-medium">Itens da OS nesta versão</summary>
                                    <ul className="mt-1 space-y-0.5 text-xs">
                                        {budget.items.map((item) => (
                                            <li key={item.id} className="flex justify-between gap-2">
                                                <span>
                                                    {item.description} × {Number(item.quantity)}
                                                </span>
                                                <span>R$ {maskMoney(String(item.total_price))}</span>
                                            </li>
                                        ))}
                                    </ul>
                                </details>
                            )}
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
