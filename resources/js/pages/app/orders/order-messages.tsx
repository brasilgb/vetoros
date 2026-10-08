import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import moment from 'moment';

export type OrderMessageEntry = {
    id: number;
    order_budget_id: number | null;
    channel: 'whatsapp' | 'email' | string;
    recipient: string | null;
    template: string | null;
    status: 'sent' | 'delivered' | 'read' | 'failed' | string;
    sent_at: string | null;
    delivered_at: string | null;
    read_at: string | null;
    failed_at: string | null;
    error_message: string | null;
    created_at: string;
};

const TEMPLATE_LABELS: Record<string, string> = {
    generatedbudget: 'Orçamento gerado',
    budget_generated: 'Orçamento gerado',
    budget_follow_up: 'Acompanhamento de orçamento',
    servicecompleted: 'Serviço concluído',
    pending_payment: 'Pagamento pendente',
    payment_reminder: 'Lembrete de pagamento',
    feedback: 'Pedido de avaliação',
    feedback_reminder: 'Lembrete de avaliação',
    order_created: 'Abertura da OS',
    status_updated: 'Atualização de status',
    defaultmessage: 'Mensagem',
};

const STATUS: Record<string, { label: string; className: string }> = {
    sent: { label: 'Enviada', className: 'bg-slate-100 text-slate-700' },
    delivered: { label: 'Entregue', className: 'bg-sky-100 text-sky-800' },
    read: { label: 'Lida', className: 'bg-emerald-100 text-emerald-800' },
    failed: { label: 'Falhou', className: 'bg-red-100 text-red-800' },
};

const format = (value: string | null) => (value ? moment(value).format('DD/MM/YYYY HH:mm') : null);

export default function OrderMessages({ messages, budgetVersions }: { messages: OrderMessageEntry[]; budgetVersions: Record<number, number> }) {
    return (
        <Card>
            <CardTitle className="border-b px-4 py-3">Comunicação com o cliente</CardTitle>
            <CardContent className="space-y-2 pt-4">
                {!messages?.length && <p className="text-muted-foreground text-sm">Nenhuma mensagem registrada para esta OS.</p>}
                {messages?.map((message) => {
                    const status = STATUS[message.status] ?? { label: message.status, className: '' };
                    const version = message.order_budget_id ? budgetVersions[message.order_budget_id] : null;

                    return (
                        <div key={message.id} className="rounded-lg border p-3 text-sm">
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <div className="flex flex-wrap items-center gap-2">
                                    <Badge variant="outline">{message.channel === 'whatsapp' ? 'WhatsApp' : 'E-mail'}</Badge>
                                    <span className="font-medium">{TEMPLATE_LABELS[message.template ?? ''] ?? 'Mensagem'}</span>
                                    {version && <Badge variant="secondary">Orçamento v{version}</Badge>}
                                </div>
                                <Badge className={status.className}>{status.label}</Badge>
                            </div>
                            <p className="text-muted-foreground mt-1 text-xs">
                                {format(message.sent_at ?? message.failed_at ?? message.created_at)}
                                {message.recipient ? ` · para ${message.recipient}` : ''}
                                {message.read_at
                                    ? ` · lida em ${format(message.read_at)}`
                                    : message.delivered_at
                                      ? ` · entregue em ${format(message.delivered_at)}`
                                      : ''}
                            </p>
                            {message.status === 'failed' && message.error_message && (
                                <p className="mt-1 text-xs text-red-600">{message.error_message}</p>
                            )}
                        </div>
                    );
                })}
            </CardContent>
        </Card>
    );
}
