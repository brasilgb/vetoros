import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import moment from 'moment';
import { useState } from 'react';

type Charge = {
    id: number;
    cycle_start: string;
    cycle_end: string;
    due_date: string;
    amount: string;
    status: string;
    status_label: string;
    paid_at: string | null;
    payment_method: string | null;
    refund_reason: string | null;
    invoice_sent_at: string | null;
    last_invoice_error: string | null;
    fiscal_document: { id: number; status: string; number: string | null; error_message: string | null } | null;
};

type Commercial = {
    billing_mode: 'plan' | 'custom';
    periodicities: { value: number; label: string }[];
    contract: {
        contract_number: string;
        amount: string;
        billing_months: number;
        periodicity_label: string;
        start_date: string;
        next_due_date: string;
        sales_rep: string | null;
        notes: string | null;
        active: boolean;
    } | null;
    saas_invoice_enabled: boolean;
    saas_invoice_blockers: string[];
    subscription_label: string;
    expires_at: string | null;
    next_charge: { due_date: string; amount: string; generated_from: string } | null;
    charges: Charge[];
};

type Action = { kind: 'pay' | 'refund' | 'adjust'; charge: Charge } | { kind: 'revert' } | null;

const money = (value: string | number) => Number(value).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const date = (value: string | null) => (value ? moment(value).format('DD/MM/YYYY') : '-');
const fiscalLabels: Record<string, string> = {
    processing: 'Em processamento',
    contingency: 'Contingência',
    authorized: 'Autorizada',
    rejected: 'Rejeitada',
    failed: 'Falha',
    cancelled: 'Cancelada',
};

export default function TenantCommercialSection({ tenantId, commercial, plans }: { tenantId: number; commercial: Commercial; plans: any[] }) {
    const contract = commercial.contract;
    const isCustom = commercial.billing_mode === 'custom';
    const form = useForm({
        custom_contract: isCustom,
        saas_invoice_enabled: commercial.saas_invoice_enabled,
        amount: contract?.amount ?? '',
        billing_months: contract?.billing_months ?? 1,
        start_date: contract?.start_date ?? moment().format('YYYY-MM-DD'),
        next_due_date: contract?.next_due_date ?? commercial.expires_at ?? moment().format('YYYY-MM-DD'),
        sales_rep: contract?.sales_rep ?? '',
        notes: contract?.notes ?? '',
    });
    const action = useForm({ amount: '', note: '', reason: '', plan_id: '', confirm: false, password: '' });
    const [current, setCurrent] = useState<Action>(null);

    const submit = (e: React.FormEvent) => {
        e.preventDefault();
        form.put(route('admin.tenants.commercial.update', tenantId), { preserveScroll: true });
    };

    const open = (next: Action) => {
        action.reset();
        action.clearErrors();
        if (next && 'charge' in next) {
            action.setData('amount', next.charge.amount);
        }
        setCurrent(next);
    };

    const confirmAction = () => {
        if (!current) return;
        const options = { preserveScroll: true, onSuccess: () => setCurrent(null) };
        if (current.kind === 'revert') {
            action.post(route('admin.tenants.commercial.revert', tenantId), options);
            return;
        }
        action.post(route(`admin.tenants.charges.${current.kind}`, [tenantId, current.charge.id]), options);
    };

    const post = (name: string, charge: Charge) => router.post(route(name, [tenantId, charge.id]), {}, { preserveScroll: true });

    return (
        <div className="bg-card mb-4 rounded-lg border p-4">
            <div className="mb-4 flex flex-col gap-2 md:flex-row md:items-center md:justify-between">
                <div>
                    <h3 className="text-lg font-semibold">Condições comerciais e faturamento</h3>
                    <p className="text-muted-foreground text-sm">
                        Somente o RootAdmin altera preço, tipo de contratação, vencimento e emissão fiscal.
                    </p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                    <Badge variant="secondary">{isCustom ? `Contrato personalizado ${contract?.contract_number ?? ''}` : 'Plano tradicional'}</Badge>
                    <Badge variant="outline">Assinatura: {commercial.subscription_label}</Badge>
                    <Badge variant="outline">NFS-e SaaS: {commercial.saas_invoice_enabled ? 'habilitada' : 'desabilitada'}</Badge>
                </div>
            </div>

            <form onSubmit={submit} className="space-y-4">
                <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                    <div>
                        <Label htmlFor="custom_contract">Contrato personalizado — não utilizar planos tradicionais</Label>
                        <p className="text-muted-foreground text-sm">
                            O valor informado é o total de cada ciclo. O plano continua definindo apenas as funcionalidades liberadas.
                        </p>
                    </div>
                    <Switch
                        id="custom_contract"
                        checked={form.data.custom_contract}
                        disabled={isCustom}
                        onCheckedChange={(checked) => form.setData('custom_contract', checked)}
                    />
                </div>

                {form.data.custom_contract && (
                    <div className="grid gap-4 md:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="amount">Valor por ciclo (R$)</Label>
                            <Input
                                id="amount"
                                inputMode="decimal"
                                value={form.data.amount}
                                onChange={(e) => form.setData('amount', e.target.value)}
                            />
                            <InputError message={form.errors.amount} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="billing_months">Periodicidade</Label>
                            <select
                                id="billing_months"
                                className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                value={form.data.billing_months}
                                onChange={(e) => form.setData('billing_months', Number(e.target.value))}
                            >
                                {commercial.periodicities.map((option) => (
                                    <option key={option.value} value={option.value}>
                                        {option.label}
                                    </option>
                                ))}
                            </select>
                            <InputError message={form.errors.billing_months} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="start_date">Início do contrato</Label>
                            <Input
                                id="start_date"
                                type="date"
                                value={form.data.start_date}
                                onChange={(e) => form.setData('start_date', e.target.value)}
                            />
                            <InputError message={form.errors.start_date} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="next_due_date">Próximo vencimento</Label>
                            <Input
                                id="next_due_date"
                                type="date"
                                value={form.data.next_due_date}
                                onChange={(e) => form.setData('next_due_date', e.target.value)}
                            />
                            <InputError message={form.errors.next_due_date} />
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="sales_rep">Vendedor / responsável pela negociação</Label>
                            <Input id="sales_rep" value={form.data.sales_rep} onChange={(e) => form.setData('sales_rep', e.target.value)} />
                            <InputError message={form.errors.sales_rep} />
                        </div>
                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="notes">Observações comerciais internas</Label>
                            <Textarea id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} />
                            <InputError message={form.errors.notes} />
                        </div>
                    </div>
                )}

                <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                    <div>
                        <Label htmlFor="saas_invoice_enabled">Emitir NFS-e para este cliente SaaS</Label>
                        <p className="text-muted-foreground text-sm">
                            Nota da ABrasil Sistemas para esta empresa, pelo emitente da plataforma. Vale para plano tradicional ou contrato.
                        </p>
                        {!commercial.saas_invoice_enabled && commercial.saas_invoice_blockers.length > 0 && (
                            <ul className="mt-2 list-disc pl-5 text-sm text-amber-700">
                                {commercial.saas_invoice_blockers.map((item) => (
                                    <li key={item}>{item}</li>
                                ))}
                            </ul>
                        )}
                        <InputError message={form.errors.saas_invoice_enabled} />
                    </div>
                    <Switch
                        id="saas_invoice_enabled"
                        checked={form.data.saas_invoice_enabled}
                        onCheckedChange={(checked) => form.setData('saas_invoice_enabled', checked)}
                    />
                </div>

                <div className="flex flex-wrap justify-end gap-2">
                    {isCustom && (
                        <Button type="button" variant="outline" onClick={() => open({ kind: 'revert' })}>
                            Voltar ao plano tradicional
                        </Button>
                    )}
                    <Button type="submit" disabled={form.processing}>
                        Salvar condições comerciais
                    </Button>
                </div>
            </form>

            {commercial.next_charge && (
                <p className="text-muted-foreground mt-4 text-sm">
                    Próxima cobrança: {money(commercial.next_charge.amount)} com vencimento em {date(commercial.next_charge.due_date)} (gerada e
                    enviada a partir de {date(commercial.next_charge.generated_from)}). Acesso válido até {date(commercial.expires_at)}.
                </p>
            )}

            {commercial.charges.length > 0 && (
                <div className="mt-4 overflow-x-auto">
                    <table className="w-full text-sm">
                        <thead className="text-muted-foreground text-left">
                            <tr>
                                <th className="p-2">Competência</th>
                                <th className="p-2">Vencimento</th>
                                <th className="p-2">Valor</th>
                                <th className="p-2">Situação</th>
                                <th className="p-2">Fatura</th>
                                <th className="p-2">NFS-e</th>
                                <th className="p-2 text-right">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            {commercial.charges.map((charge) => {
                                const doc = charge.fiscal_document;
                                return (
                                    <tr key={charge.id} className="border-t align-top">
                                        <td className="p-2">
                                            {date(charge.cycle_start)} a {date(charge.cycle_end)}
                                        </td>
                                        <td className="p-2">{date(charge.due_date)}</td>
                                        <td className="p-2">{money(charge.amount)}</td>
                                        <td className="p-2">
                                            {charge.status_label}
                                            {charge.paid_at && (
                                                <div className="text-muted-foreground text-xs">
                                                    {date(charge.paid_at)} · {charge.payment_method}
                                                </div>
                                            )}
                                            {charge.refund_reason && <div className="text-muted-foreground text-xs">{charge.refund_reason}</div>}
                                        </td>
                                        <td className="p-2">
                                            {charge.invoice_sent_at ? `Enviada ${date(charge.invoice_sent_at)}` : 'Não enviada'}
                                            {charge.last_invoice_error && <div className="text-xs text-rose-600">{charge.last_invoice_error}</div>}
                                        </td>
                                        <td className="p-2">
                                            {doc ? (
                                                <>
                                                    {fiscalLabels[doc.status] ?? doc.status} {doc.number && `nº ${doc.number}`}
                                                    {doc.error_message && <div className="text-xs text-rose-600">{doc.error_message}</div>}
                                                    {['authorized', 'cancelled'].includes(doc.status) && (
                                                        <div className="text-xs">
                                                            <a
                                                                className="underline"
                                                                href={route('admin.fiscal.saas.file', [doc.id, 'pdf'])}
                                                                target="_blank"
                                                                rel="noreferrer"
                                                            >
                                                                PDF
                                                            </a>{' '}
                                                            ·{' '}
                                                            <a className="underline" href={route('admin.fiscal.saas.file', [doc.id, 'xml'])}>
                                                                XML
                                                            </a>
                                                        </div>
                                                    )}
                                                </>
                                            ) : (
                                                '-'
                                            )}
                                        </td>
                                        <td className="p-2">
                                            <div className="flex flex-wrap justify-end gap-1">
                                                {charge.status === 'pending' && (
                                                    <>
                                                        <Button size="sm" variant="secondary" onClick={() => open({ kind: 'pay', charge })}>
                                                            Registrar pagamento
                                                        </Button>
                                                        <Button size="sm" variant="outline" onClick={() => open({ kind: 'adjust', charge })}>
                                                            Ajustar valor
                                                        </Button>
                                                    </>
                                                )}
                                                {charge.status === 'paid' && (
                                                    <Button size="sm" variant="outline" onClick={() => open({ kind: 'refund', charge })}>
                                                        Estornar
                                                    </Button>
                                                )}
                                                {['pending', 'paid'].includes(charge.status) && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => post('admin.tenants.charges.resend-invoice', charge)}
                                                    >
                                                        Reenviar fatura
                                                    </Button>
                                                )}
                                                {commercial.saas_invoice_enabled &&
                                                    ['pending', 'paid'].includes(charge.status) &&
                                                    (!doc || ['rejected', 'failed'].includes(doc.status)) && (
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => post('admin.tenants.charges.emit', charge)}
                                                        >
                                                            Emitir NFS-e
                                                        </Button>
                                                    )}
                                                {doc?.status === 'authorized' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => post('admin.tenants.charges.resend-fiscal', charge)}
                                                    >
                                                        Reenviar nota
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                );
                            })}
                        </tbody>
                    </table>
                    <p className="text-muted-foreground mt-2 text-xs">
                        Notas do SaaS completas em{' '}
                        <a className="underline" href={route('admin.fiscal.saas.index', { tenant_id: tenantId })}>
                            Fiscal → Notas do SaaS
                        </a>
                        .
                    </p>
                </div>
            )}

            <Dialog open={current !== null} onOpenChange={(value) => !value && setCurrent(null)}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {current?.kind === 'pay' && 'Registrar pagamento manual'}
                            {current?.kind === 'refund' && 'Estornar pagamento'}
                            {current?.kind === 'adjust' && 'Ajustar valor da cobrança em aberto'}
                            {current?.kind === 'revert' && 'Voltar ao plano tradicional'}
                        </DialogTitle>
                        <DialogDescription>
                            {current?.kind === 'refund' &&
                                'O acesso volta ao vencimento desta cobrança. A NFS-e, se houver, não é cancelada automaticamente.'}
                            {current?.kind === 'adjust' && 'Operação registrada na auditoria. Só cobranças sem NFS-e ativa.'}
                            {current?.kind === 'revert' &&
                                'Cobranças em aberto sem nota são canceladas; histórico, pagamentos e notas ficam preservados. O período já pago continua valendo.'}
                        </DialogDescription>
                    </DialogHeader>

                    <div className="space-y-3">
                        {current && current.kind !== 'refund' && current.kind !== 'revert' && (
                            <div className="grid gap-2">
                                <Label htmlFor="action_amount">Valor (R$)</Label>
                                <Input
                                    id="action_amount"
                                    inputMode="decimal"
                                    value={action.data.amount}
                                    onChange={(e) => action.setData('amount', e.target.value)}
                                />
                                <InputError message={action.errors.amount} />
                            </div>
                        )}
                        {current?.kind === 'pay' && (
                            <div className="grid gap-2">
                                <Label htmlFor="action_note">Observação (meio de pagamento, comprovante)</Label>
                                <Input id="action_note" value={action.data.note} onChange={(e) => action.setData('note', e.target.value)} />
                            </div>
                        )}
                        {(current?.kind === 'refund' || current?.kind === 'adjust') && (
                            <div className="grid gap-2">
                                <Label htmlFor="action_reason">Motivo</Label>
                                <Input id="action_reason" value={action.data.reason} onChange={(e) => action.setData('reason', e.target.value)} />
                                <InputError message={action.errors.reason} />
                            </div>
                        )}
                        {current?.kind === 'revert' && (
                            <>
                                <div className="grid gap-2">
                                    <Label htmlFor="action_plan">Plano tradicional</Label>
                                    <select
                                        id="action_plan"
                                        className="h-9 rounded-md border bg-transparent px-3 text-sm"
                                        value={action.data.plan_id}
                                        onChange={(e) => action.setData('plan_id', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        {plans.map((plan: any) => (
                                            <option key={plan.id} value={plan.id}>
                                                {plan.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={action.errors.plan_id} />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        checked={action.data.confirm}
                                        onChange={(e) => action.setData('confirm', e.target.checked)}
                                    />
                                    Confirmo a volta ao plano tradicional
                                </label>
                                <InputError message={action.errors.confirm} />
                            </>
                        )}
                        {(current?.kind === 'refund' || current?.kind === 'revert') && (
                            <div className="grid gap-2">
                                <Label htmlFor="action_password">Sua senha</Label>
                                <Input
                                    id="action_password"
                                    type="password"
                                    value={action.data.password}
                                    onChange={(e) => action.setData('password', e.target.value)}
                                />
                                <InputError message={action.errors.password} />
                            </div>
                        )}
                        <InputError message={(action.errors as Record<string, string>).charge} />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => setCurrent(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={confirmAction} disabled={action.processing}>
                            Confirmar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
