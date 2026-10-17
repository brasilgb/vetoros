import InputError from '@/components/input-error';
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
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { Loader2 } from 'lucide-react';
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

export type Commercial = {
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
        scheduled_revert: { effective_date: string; plan_name: string | null } | null;
    } | null;
    suggested_revert_date: string;
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

/** Campos do formulário comercial e a aba em que cada um aparece (para levar o usuário ao erro). */
export const commercialFieldTabs: Record<string, 'subscription' | 'fiscal'> = {
    custom_contract: 'subscription',
    amount: 'subscription',
    billing_months: 'subscription',
    start_date: 'subscription',
    next_due_date: 'subscription',
    sales_rep: 'subscription',
    notes: 'subscription',
    saas_invoice_enabled: 'fiscal',
};

// Tema escuro: borda clara no trilho desligado (contraste acima de 3:1 com o fundo).
const switchClass = 'dark:data-[state=unchecked]:bg-slate-600 dark:data-[state=unchecked]:border-slate-300';

/**
 * Estado comercial da empresa (contrato, NFS-e do SaaS, cobranças), compartilhado pelas abas da edição.
 * Um único formulário comercial: o que for alterado numa aba continua valendo ao trocar de aba.
 */
export function useTenantCommercial({
    tenantId,
    commercial,
    plans,
    onErrors,
}: {
    tenantId: number;
    commercial: Commercial;
    plans: { id: number; name: string }[];
    onErrors?: (errors: Record<string, string>) => void;
}) {
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
    const action = useForm({
        amount: '',
        note: '',
        reason: '',
        plan_id: '',
        effective_date: commercial.suggested_revert_date,
        confirm: false,
        password: '',
    });
    const [current, setCurrent] = useState<Action>(null);
    const [confirmActivation, setConfirmActivation] = useState(false);
    const [confirmUnschedule, setConfirmUnschedule] = useState(false);
    const [unscheduling, setUnscheduling] = useState(false);

    const save = () => {
        form.put(route('admin.tenants.commercial.update', tenantId), {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setConfirmActivation(false);
                form.setDefaults();
            },
            onError: (errors) => {
                setConfirmActivation(false);
                onErrors?.(errors);
            },
        });
    };

    /** Ativar o contrato personalizado trava os planos tradicionais: pede confirmação antes de salvar. */
    const submit = (e?: React.FormEvent) => {
        e?.preventDefault();
        if (form.data.custom_contract && !isCustom) {
            setConfirmActivation(true);
            return;
        }
        save();
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
        const options = { preserveScroll: true, preserveState: true, onSuccess: () => setCurrent(null) };
        if (current.kind === 'revert') {
            action.post(route('admin.tenants.commercial.revert', tenantId), options);
            return;
        }
        action.post(route(`admin.tenants.charges.${current.kind}`, [tenantId, current.charge.id]), options);
    };

    const unschedule = () =>
        router.delete(route('admin.tenants.commercial.revert.cancel', tenantId), {
            preserveScroll: true,
            preserveState: true,
            onStart: () => setUnscheduling(true),
            onFinish: () => {
                setUnscheduling(false);
                setConfirmUnschedule(false);
            },
        });

    const post = (name: string, charge: Charge) => router.post(route(name, [tenantId, charge.id]), {}, { preserveScroll: true, preserveState: true });

    return {
        tenantId,
        commercial,
        plans,
        contract,
        isCustom,
        form,
        action,
        current,
        setCurrent,
        open,
        confirmAction,
        submit,
        save,
        post,
        confirmActivation,
        setConfirmActivation,
        confirmUnschedule,
        setConfirmUnschedule,
        unschedule,
        unscheduling,
    };
}

export type TenantCommercialController = ReturnType<typeof useTenantCommercial>;

function DirtyNotice({ show }: { show: boolean }) {
    if (!show) return null;
    return (
        <span role="status" className="text-xs font-medium text-amber-700 dark:text-amber-400">
            Alterações comerciais não salvas
        </span>
    );
}

/** Aba "Assinatura e planos": contrato personalizado (trava dos planos tradicionais) e volta ao plano. */
export function CommercialContractPanel({ ctl }: { ctl: TenantCommercialController }) {
    const { form, contract, isCustom } = ctl;

    return (
        <form onSubmit={ctl.submit} className="space-y-4">
            <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                <div>
                    <Label htmlFor="custom_contract">Contrato personalizado</Label>
                    <p className="text-muted-foreground text-sm">Substitui os planos tradicionais; o plano passa a definir só as funcionalidades.</p>
                </div>
                <Switch
                    id="custom_contract"
                    className={switchClass}
                    checked={form.data.custom_contract}
                    disabled={isCustom}
                    onCheckedChange={(checked) => form.setData('custom_contract', checked)}
                />
            </div>

            {form.data.custom_contract && (
                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                    <div className="grid gap-2">
                        <Label htmlFor="amount">Valor por ciclo (R$)</Label>
                        <Input id="amount" inputMode="decimal" value={form.data.amount} onChange={(e) => form.setData('amount', e.target.value)} />
                        <InputError message={form.errors.amount} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="billing_months">Periodicidade</Label>
                        <select
                            id="billing_months"
                            className="border-input dark:bg-input/30 h-9 rounded-md border bg-transparent px-3 text-sm"
                            value={form.data.billing_months}
                            onChange={(e) => form.setData('billing_months', Number(e.target.value))}
                        >
                            {ctl.commercial.periodicities.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.billing_months} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="start_date">Data inicial (data efetiva)</Label>
                        <Input
                            id="start_date"
                            type="date"
                            value={form.data.start_date}
                            onChange={(e) => form.setData('start_date', e.target.value)}
                        />
                        <InputError message={form.errors.start_date} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor="next_due_date">{contract?.active ? 'Próximo vencimento' : 'Primeiro vencimento'}</Label>
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

            {contract?.scheduled_revert && (
                <div className="flex flex-col gap-2 rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900 md:flex-row md:items-center md:justify-between dark:border-amber-700 dark:bg-amber-950 dark:text-amber-100">
                    <span>
                        Volta ao plano {contract.scheduled_revert.plan_name ?? 'tradicional'} agendada para{' '}
                        {date(contract.scheduled_revert.effective_date)}. Até lá o contrato segue valendo; nenhuma cobrança do contrato vence a partir
                        dessa data.
                    </span>
                    <Button type="button" size="sm" variant="outline" onClick={() => ctl.setConfirmUnschedule(true)}>
                        Desagendar
                    </Button>
                </div>
            )}

            <div className="flex flex-wrap items-center justify-end gap-2">
                <DirtyNotice show={form.isDirty} />
                {isCustom && !contract?.scheduled_revert && (
                    <Button type="button" variant="outline" onClick={() => ctl.open({ kind: 'revert' })}>
                        Voltar ao plano tradicional
                    </Button>
                )}
                <Button type="submit" disabled={form.processing}>
                    {form.processing && <Loader2 className="size-4 animate-spin" />}
                    Salvar condições comerciais
                </Button>
            </div>
        </form>
    );
}

/** Aba "Fiscal": NFS-e da ABrasil para este cliente SaaS (mesmo formulário comercial). */
export function SaasInvoicePanel({ ctl }: { ctl: TenantCommercialController }) {
    const { form, commercial } = ctl;

    return (
        <form onSubmit={ctl.submit} className="space-y-3">
            <div className="flex items-center justify-between gap-4 rounded-md border p-4">
                <div>
                    <Label htmlFor="saas_invoice_enabled">Emitir NFS-e para este cliente SaaS</Label>
                    <p className="text-muted-foreground text-sm">Nota da ABrasil Sistemas para esta empresa, pelo emitente da plataforma.</p>
                    {!commercial.saas_invoice_enabled && commercial.saas_invoice_blockers.length > 0 && (
                        <ul className="mt-2 list-disc pl-5 text-sm text-amber-700 dark:text-amber-400">
                            {commercial.saas_invoice_blockers.map((item) => (
                                <li key={item}>{item}</li>
                            ))}
                        </ul>
                    )}
                    <InputError message={form.errors.saas_invoice_enabled} />
                </div>
                <Switch
                    id="saas_invoice_enabled"
                    className={switchClass}
                    checked={form.data.saas_invoice_enabled}
                    onCheckedChange={(checked) => form.setData('saas_invoice_enabled', checked)}
                />
            </div>
            <div className="flex flex-wrap items-center justify-end gap-2">
                <DirtyNotice show={form.isDirty} />
                <Button type="submit" variant="outline" disabled={form.processing}>
                    {form.processing && <Loader2 className="size-4 animate-spin" />}
                    Salvar NFS-e do SaaS
                </Button>
            </div>
        </form>
    );
}

/** Aba "Financeiro e cobranças": próxima cobrança e histórico com as ações existentes. */
export function CommercialChargesPanel({ ctl }: { ctl: TenantCommercialController }) {
    const { commercial, tenantId } = ctl;

    return (
        <div className="space-y-3">
            {commercial.next_charge ? (
                <p className="text-sm">
                    Próxima cobrança: <strong>{money(commercial.next_charge.amount)}</strong> com vencimento em{' '}
                    <strong>{date(commercial.next_charge.due_date)}</strong> (gerada e enviada a partir de{' '}
                    {date(commercial.next_charge.generated_from)}). Acesso válido até {date(commercial.expires_at)}.
                </p>
            ) : (
                <p className="text-muted-foreground text-sm">Nenhuma cobrança programada.</p>
            )}

            {commercial.charges.length === 0 ? (
                <p className="text-muted-foreground rounded-md border border-dashed p-4 text-center text-sm">Nenhuma cobrança registrada.</p>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full min-w-[760px] text-sm">
                        <thead className="text-muted-foreground bg-muted/40 text-left">
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
                                            {charge.last_invoice_error && (
                                                <div className="text-xs text-rose-600 dark:text-rose-400">{charge.last_invoice_error}</div>
                                            )}
                                        </td>
                                        <td className="p-2">
                                            {doc ? (
                                                <>
                                                    {fiscalLabels[doc.status] ?? doc.status} {doc.number && `nº ${doc.number}`}
                                                    {doc.error_message && (
                                                        <div className="text-xs text-rose-600 dark:text-rose-400">{doc.error_message}</div>
                                                    )}
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
                                                        <Button size="sm" variant="secondary" onClick={() => ctl.open({ kind: 'pay', charge })}>
                                                            Registrar pagamento
                                                        </Button>
                                                        <Button size="sm" variant="outline" onClick={() => ctl.open({ kind: 'adjust', charge })}>
                                                            Ajustar valor
                                                        </Button>
                                                    </>
                                                )}
                                                {charge.status === 'paid' && (
                                                    <Button size="sm" variant="outline" onClick={() => ctl.open({ kind: 'refund', charge })}>
                                                        Estornar
                                                    </Button>
                                                )}
                                                {['pending', 'paid'].includes(charge.status) && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => ctl.post('admin.tenants.charges.resend-invoice', charge)}
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
                                                            onClick={() => ctl.post('admin.tenants.charges.emit', charge)}
                                                        >
                                                            Emitir NFS-e
                                                        </Button>
                                                    )}
                                                {doc?.status === 'authorized' && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        onClick={() => ctl.post('admin.tenants.charges.resend-fiscal', charge)}
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
                </div>
            )}
            <p className="text-muted-foreground text-xs">
                Notas do SaaS completas em{' '}
                <a className="underline" href={route('admin.fiscal.saas.index', { tenant_id: tenantId })}>
                    Fiscal → Notas do SaaS
                </a>
                .
            </p>
        </div>
    );
}

/** Diálogos das ações comerciais: pagamento, estorno, ajuste, volta ao plano, ativação do contrato e desagendamento. */
export function CommercialDialogs({ ctl }: { ctl: TenantCommercialController }) {
    const { current, action, commercial } = ctl;

    return (
        <>
            <Dialog open={current !== null} onOpenChange={(value) => !value && ctl.setCurrent(null)}>
                <DialogContent className="max-h-[90vh] overflow-y-auto">
                    <DialogHeader>
                        <DialogTitle>
                            {current?.kind === 'pay' && 'Registrar pagamento manual'}
                            {current?.kind === 'refund' && 'Estornar pagamento'}
                            {current?.kind === 'adjust' && 'Ajustar valor da cobrança em aberto'}
                            {current?.kind === 'revert' && 'Voltar ao plano tradicional'}
                        </DialogTitle>
                        <DialogDescription>
                            {current?.kind === 'pay' && 'Registra a quitação manual desta cobrança e atualiza a cobertura da assinatura.'}
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
                                        className="border-input dark:bg-input/30 h-9 rounded-md border bg-transparent px-3 text-sm"
                                        value={action.data.plan_id}
                                        onChange={(e) => action.setData('plan_id', e.target.value)}
                                    >
                                        <option value="">Selecione</option>
                                        {ctl.plans.map((plan) => (
                                            <option key={plan.id} value={plan.id}>
                                                {plan.name}
                                            </option>
                                        ))}
                                    </select>
                                    <InputError message={action.errors.plan_id} />
                                </div>
                                <div className="grid gap-2">
                                    <Label htmlFor="action_effective_date">Data efetiva da mudança</Label>
                                    <Input
                                        id="action_effective_date"
                                        type="date"
                                        min={moment().format('YYYY-MM-DD')}
                                        value={action.data.effective_date}
                                        onChange={(e) => action.setData('effective_date', e.target.value)}
                                    />
                                    <p className="text-muted-foreground text-xs">
                                        Sugerida: {date(commercial.suggested_revert_date)}, fim do período já coberto. Hoje aplica na hora; data
                                        futura fica agendada.
                                    </p>
                                    <InputError message={action.errors.effective_date} />
                                </div>
                                <label className="flex items-center gap-2 text-sm">
                                    <input
                                        type="checkbox"
                                        className="size-4 accent-[var(--primary)] dark:border-slate-300"
                                        checked={action.data.confirm}
                                        onChange={(e) => action.setData('confirm', e.target.checked)}
                                    />
                                    Confirmo a volta ao plano tradicional a partir de {date(action.data.effective_date)}
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
                                    autoComplete="current-password"
                                    value={action.data.password}
                                    onChange={(e) => action.setData('password', e.target.value)}
                                />
                                <InputError message={action.errors.password} />
                            </div>
                        )}
                        <InputError message={(action.errors as Record<string, string>).charge} />
                    </div>

                    <DialogFooter>
                        <Button variant="outline" onClick={() => ctl.setCurrent(null)}>
                            Cancelar
                        </Button>
                        <Button onClick={ctl.confirmAction} disabled={action.processing}>
                            {action.processing && <Loader2 className="size-4 animate-spin" />}
                            Confirmar
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            <AlertDialog open={ctl.confirmActivation} onOpenChange={(value) => !value && !ctl.form.processing && ctl.setConfirmActivation(false)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Ativar contrato personalizado?</AlertDialogTitle>
                        <AlertDialogDescription>
                            A empresa deixa de usar os planos tradicionais: a seleção de plano fica bloqueada e a cobrança passa a ser de{' '}
                            {ctl.form.data.amount ? money(String(ctl.form.data.amount).replace(',', '.')) : 'valor a definir'} por ciclo, com primeiro
                            vencimento em {date(ctl.form.data.next_due_date)}. Para voltar, use "Voltar ao plano tradicional".
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={ctl.form.processing}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={ctl.form.processing}
                            onClick={(e) => {
                                e.preventDefault();
                                ctl.save();
                            }}
                        >
                            {ctl.form.processing && <Loader2 className="size-4 animate-spin" />}
                            Ativar contrato
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>

            <AlertDialog open={ctl.confirmUnschedule} onOpenChange={(value) => !value && !ctl.unscheduling && ctl.setConfirmUnschedule(false)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Desagendar a volta ao plano tradicional?</AlertDialogTitle>
                        <AlertDialogDescription>
                            O contrato personalizado continua valendo e volta a gerar cobranças normalmente.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel disabled={ctl.unscheduling}>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            disabled={ctl.unscheduling}
                            onClick={(e) => {
                                e.preventDefault();
                                ctl.unschedule();
                            }}
                        >
                            {ctl.unscheduling && <Loader2 className="size-4 animate-spin" />}
                            Desagendar
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </>
    );
}
