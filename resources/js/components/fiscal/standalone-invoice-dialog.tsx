import AsyncResourceSelect from '@/components/async-resource-select';
import InputError from '@/components/input-error';
import { Alert, AlertDescription } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { useForm } from '@inertiajs/react';
import { AlertTriangle, Plus, Trash2 } from 'lucide-react';
import { useMemo, useState } from 'react';

type Model = 'nfse' | 'nfe' | 'nfce';

export type StandaloneOptions = {
    blockers: Record<Model, string | null>;
    environment?: string | null;
    nfse: { service_list_item?: string | null; iss_rate?: number | null; taxation_type?: string | null; city_code?: string | null };
    products: { tax_regime?: string | null; icms_situation?: string | null; commercial_unit?: string | null };
    finance_enabled: boolean;
    payment_methods: string[];
};

type CustomerOption = { value: number; label: string; cpfcnpj?: string | null; email?: string | null; has_address: boolean };
type PartOption = { value: number; label: string; sale_price: number; code?: string | null; ncm?: string | null; cfop?: string | null };
type ItemRow = { part: PartOption | null; quantity: string; unit_price: string };

const modelLabels: Record<Model, { title: string; hint: string }> = {
    nfse: { title: 'NFS-e', hint: 'Serviço prestado' },
    nfe: { title: 'NF-e', hint: 'Produtos para cliente identificado' },
    nfce: { title: 'NFC-e', hint: 'Venda ao consumidor final' },
};

const methodLabels: Record<string, string> = { pix: 'Pix', cartao: 'Cartão', dinheiro: 'Dinheiro', transferencia: 'Transferência', boleto: 'Boleto' };

// Mesmos rótulos da tela de configurações fiscais.
const taxationLabels: Record<string, string> = {
    taxationInMunicipality: 'Tributado no município',
    taxationOutsideMunicipality: 'Tributado fora do município',
    exemption: 'Isento',
    immune: 'Imune',
    suspendedByCourt: 'Suspenso por decisão judicial',
    suspendedByAdministrativeProcedure: 'Suspenso por procedimento administrativo',
    exportation: 'Exportação de serviço',
    nonIncidence: 'Não incidência',
};

const money = (value: number) => value.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
const toNumber = (value: string) => Number(String(value).replace(',', '.')) || 0;
const today = () => new Date().toISOString().slice(0, 10);

function emptyForm() {
    return {
        request_key: '',
        type: '' as Model | '',
        customer_id: null as number | null,
        items: [] as { part_id: number; quantity: number; unit_price: number }[],
        description: '',
        service_amount: '',
        service_list_item: '',
        iss_rate: '',
        payment_method: '',
        finance_mode: 'none' as 'none' | 'receivable' | 'received',
        due_date: '',
        paid_at: '',
        notes: '',
    };
}

/**
 * Emissão de nota avulsa (VETOR-FISCAL-AVULSA-01): tipo, cliente, serviço ou produtos, tributação
 * aplicada, resumo e confirmação. "Registrar no financeiro" começa desmarcado. A chave da
 * solicitação é gerada ao abrir o resumo, então repetir "Emitir" não duplica nota nem lançamento.
 */
export default function StandaloneInvoiceDialog({
    open,
    onOpenChange,
    options,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    options: StandaloneOptions;
}) {
    const form = useForm(emptyForm());
    const [step, setStep] = useState<'form' | 'summary'>('form');
    const [customer, setCustomer] = useState<CustomerOption | null>(null);
    const [rows, setRows] = useState<ItemRow[]>([{ part: null, quantity: '1', unit_price: '' }]);
    const [registerFinance, setRegisterFinance] = useState(false);
    const [localError, setLocalError] = useState<string | null>(null);

    const type = form.data.type;
    const isService = type === 'nfse';
    const blocker = type ? options.blockers[type] : null;
    const productRows = rows.filter((row) => row.part);
    const total = isService
        ? toNumber(form.data.service_amount)
        : productRows.reduce((sum, row) => sum + Math.round(toNumber(row.quantity) * toNumber(row.unit_price) * 100) / 100, 0);
    const issRate = form.data.iss_rate !== '' ? toNumber(form.data.iss_rate) : options.nfse.iss_rate;
    const financeMode = registerFinance ? form.data.finance_mode : 'none';

    const missingPartTaxes = useMemo(
        () => productRows.filter((row) => !row.part?.ncm || !row.part?.cfop).map((row) => row.part!.label),
        [productRows],
    );

    const reset = () => {
        form.setData(emptyForm());
        form.clearErrors();
        setStep('form');
        setCustomer(null);
        setRows([{ part: null, quantity: '1', unit_price: '' }]);
        setRegisterFinance(false);
        setLocalError(null);
    };

    const close = (value: boolean) => {
        if (!value) reset();
        onOpenChange(value);
    };

    const updateRow = (index: number, patch: Partial<ItemRow>) =>
        setRows((current) => current.map((row, i) => (i === index ? { ...row, ...patch } : row)));

    const goToSummary = () => {
        const problems: string[] = [];

        if (!type) problems.push('Selecione o tipo de nota.');
        if (type && type !== 'nfce' && !customer) problems.push('Selecione o cliente.');
        if (isService && form.data.description.trim() === '') problems.push('Descreva o serviço prestado.');
        if (!isService && type && productRows.length === 0) problems.push('Adicione ao menos um produto.');
        if (!isService && productRows.some((row) => toNumber(row.quantity) <= 0 || toNumber(row.unit_price) <= 0)) {
            problems.push('Informe quantidade e valor unitário maiores que zero em todos os produtos.');
        }
        if (total <= 0) problems.push('O valor da nota deve ser maior que zero.');
        if ((type === 'nfe' || type === 'nfce' || financeMode === 'received') && !form.data.payment_method)
            problems.push('Informe a forma de pagamento.');
        if (financeMode === 'receivable' && !form.data.due_date) problems.push('Informe o vencimento da conta a receber.');
        if (financeMode === 'received' && !form.data.paid_at) problems.push('Informe a data do recebimento.');

        if (problems.length > 0) {
            setLocalError(problems.join('\n'));

            return;
        }

        setLocalError(null);
        form.clearErrors();
        // Nova chave a cada resumo: a mesma confirmação reenviada é reconhecida pelo servidor.
        form.setData('request_key', crypto.randomUUID());
        setStep('summary');
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            customer_id: customer?.value ?? null,
            items: isService
                ? []
                : productRows.map((row) => ({ part_id: row.part!.value, quantity: toNumber(row.quantity), unit_price: toNumber(row.unit_price) })),
            payment_method: data.payment_method || null,
            finance_mode: financeMode,
            due_date: financeMode === 'receivable' ? data.due_date : null,
            paid_at: financeMode === 'received' ? data.paid_at : null,
        }));

        form.post(route('app.fiscal-documents.standalone.store'), {
            preserveScroll: true,
            onSuccess: () => close(false),
            onError: () => setStep('form'),
        });
    };

    const serverErrors = Object.entries(form.errors).filter(([key]) => key !== 'fiscal');

    return (
        <Dialog open={open} onOpenChange={close}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{step === 'form' ? 'Emitir nota avulsa' : 'Confirmar emissão'}</DialogTitle>
                    <DialogDescription>
                        {step === 'form'
                            ? 'Nota sem vínculo com OS, venda ou contrato. Não movimenta estoque.'
                            : 'Confira os dados. A nota será enviada à autorização ao confirmar.'}
                    </DialogDescription>
                </DialogHeader>

                {(form.errors as Record<string, string>).fiscal && (
                    <Alert variant="destructive">
                        <AlertTriangle className="h-4 w-4" />
                        <AlertDescription className="whitespace-pre-line">{(form.errors as Record<string, string>).fiscal}</AlertDescription>
                    </Alert>
                )}
                {serverErrors.length > 0 && (
                    <Alert variant="destructive">
                        <AlertDescription>
                            <ul className="list-disc pl-4">
                                {serverErrors.map(([key, message]) => (
                                    <li key={key}>{message}</li>
                                ))}
                            </ul>
                        </AlertDescription>
                    </Alert>
                )}
                {localError && (
                    <Alert variant="destructive">
                        <AlertDescription className="whitespace-pre-line">{localError}</AlertDescription>
                    </Alert>
                )}

                {step === 'form' ? (
                    <div className="grid gap-5">
                        <section className="grid gap-2">
                            <Label>1. Tipo de nota</Label>
                            <div className="grid gap-2 sm:grid-cols-3">
                                {(Object.keys(modelLabels) as Model[]).map((model) => (
                                    <button
                                        key={model}
                                        type="button"
                                        onClick={() => form.setData('type', model)}
                                        className={cn(
                                            'rounded-md border p-3 text-left text-sm transition-colors',
                                            type === model ? 'border-primary bg-primary/5 ring-primary ring-1' : 'hover:bg-muted',
                                        )}
                                    >
                                        <span className="block font-medium">{modelLabels[model].title}</span>
                                        <span className="text-muted-foreground block text-xs">{modelLabels[model].hint}</span>
                                        {options.blockers[model] && (
                                            <Badge variant="outline" className="mt-1">
                                                Indisponível
                                            </Badge>
                                        )}
                                    </button>
                                ))}
                            </div>
                            {blocker && (
                                <Alert variant="destructive">
                                    <AlertDescription>{blocker}</AlertDescription>
                                </Alert>
                            )}
                        </section>

                        {type && !blocker && (
                            <>
                                <section className="grid gap-2">
                                    <Label htmlFor="standalone-customer">
                                        2. {isService ? 'Tomador' : type === 'nfe' ? 'Destinatário' : 'Consumidor (opcional)'}
                                    </Label>
                                    <AsyncResourceSelect<CustomerOption>
                                        inputId="standalone-customer"
                                        searchUrl={route('app.fiscal-documents.standalone.customers')}
                                        value={customer}
                                        onChange={(option) => setCustomer(option ?? null)}
                                        placeholder="Busque por nome ou CPF/CNPJ"
                                        mapOption={(item) => ({
                                            value: item.id,
                                            label: item.cpfcnpj ? `${item.name} — ${item.cpfcnpj}` : item.name,
                                            cpfcnpj: item.cpfcnpj,
                                            email: item.email,
                                            has_address: item.has_address,
                                        })}
                                    />
                                    {customer && !customer.cpfcnpj && (
                                        <p className="text-destructive text-xs">Este cliente não tem CPF/CNPJ no cadastro.</p>
                                    )}
                                    {customer && type === 'nfe' && !customer.has_address && (
                                        <p className="text-destructive text-xs">
                                            A NF-e exige o endereço completo do destinatário (CEP, logradouro, número, bairro, cidade e UF).
                                        </p>
                                    )}
                                    <InputError message={form.errors.customer_id} />
                                </section>

                                {isService ? (
                                    <section className="grid gap-3">
                                        <Label htmlFor="standalone-description">3. Serviço</Label>
                                        <Textarea
                                            id="standalone-description"
                                            maxLength={2000}
                                            placeholder="Descrição do serviço prestado"
                                            value={form.data.description}
                                            onChange={(e) => form.setData('description', e.target.value)}
                                        />
                                        <div className="grid gap-3 sm:grid-cols-3">
                                            <div className="grid gap-1">
                                                <Label htmlFor="standalone-amount">Valor (R$)</Label>
                                                <Input
                                                    id="standalone-amount"
                                                    inputMode="decimal"
                                                    value={form.data.service_amount}
                                                    onChange={(e) => form.setData('service_amount', e.target.value)}
                                                />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="standalone-lc116">Item LC 116</Label>
                                                <Input
                                                    id="standalone-lc116"
                                                    maxLength={20}
                                                    placeholder={options.nfse.service_list_item ?? 'Não configurado'}
                                                    value={form.data.service_list_item}
                                                    onChange={(e) => form.setData('service_list_item', e.target.value)}
                                                />
                                            </div>
                                            <div className="grid gap-1">
                                                <Label htmlFor="standalone-iss">ISS (%)</Label>
                                                <Input
                                                    id="standalone-iss"
                                                    inputMode="decimal"
                                                    placeholder={options.nfse.iss_rate != null ? String(options.nfse.iss_rate) : 'Não configurado'}
                                                    value={form.data.iss_rate}
                                                    onChange={(e) => form.setData('iss_rate', e.target.value)}
                                                />
                                            </div>
                                        </div>
                                        <p className="text-muted-foreground text-xs">
                                            4. Tributação: {taxationLabels[options.nfse.taxation_type ?? ''] ?? 'tipo de tributação não configurado'}
                                            {options.nfse.city_code ? `, município ${options.nfse.city_code}` : ''}. Em branco, item e alíquota seguem
                                            as configurações fiscais.
                                        </p>
                                    </section>
                                ) : (
                                    <section className="grid gap-3">
                                        <Label>3. Produtos</Label>
                                        {rows.map((row, index) => (
                                            <div
                                                key={index}
                                                className="grid gap-2 rounded-md border p-2 sm:grid-cols-[1fr_90px_120px_auto] sm:items-end"
                                            >
                                                <div className="grid gap-1">
                                                    <span className="text-muted-foreground text-xs">Produto</span>
                                                    <AsyncResourceSelect<PartOption>
                                                        searchUrl={route('app.fiscal-documents.standalone.parts')}
                                                        value={row.part}
                                                        onChange={(option) =>
                                                            updateRow(index, {
                                                                part: option ?? null,
                                                                unit_price:
                                                                    option && !row.unit_price ? String(option.sale_price || '') : row.unit_price,
                                                            })
                                                        }
                                                        mapOption={(item) => ({
                                                            value: item.id,
                                                            label: item.code ? `${item.name} (${item.code})` : item.name,
                                                            sale_price: item.sale_price,
                                                            code: item.code,
                                                            ncm: item.ncm,
                                                            cfop: item.cfop,
                                                        })}
                                                    />
                                                </div>
                                                <div className="grid gap-1">
                                                    <span className="text-muted-foreground text-xs">Qtd.</span>
                                                    <Input
                                                        inputMode="decimal"
                                                        value={row.quantity}
                                                        onChange={(e) => updateRow(index, { quantity: e.target.value })}
                                                    />
                                                </div>
                                                <div className="grid gap-1">
                                                    <span className="text-muted-foreground text-xs">Valor unit. (R$)</span>
                                                    <Input
                                                        inputMode="decimal"
                                                        value={row.unit_price}
                                                        onChange={(e) => updateRow(index, { unit_price: e.target.value })}
                                                    />
                                                </div>
                                                <Button
                                                    type="button"
                                                    size="icon"
                                                    variant="ghost"
                                                    title="Remover produto"
                                                    disabled={rows.length === 1}
                                                    onClick={() => setRows((current) => current.filter((_, i) => i !== index))}
                                                >
                                                    <Trash2 className="h-4 w-4" />
                                                </Button>
                                            </div>
                                        ))}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="justify-self-start"
                                            onClick={() => setRows((current) => [...current, { part: null, quantity: '1', unit_price: '' }])}
                                        >
                                            <Plus className="mr-1 h-4 w-4" /> Adicionar produto
                                        </Button>
                                        <p className="text-muted-foreground text-xs">
                                            4. Tributação: NCM e CFOP de cada produto; origem,{' '}
                                            {options.products.tax_regime && ['1', '2', '4'].includes(options.products.tax_regime) ? 'CSOSN' : 'CST'}{' '}
                                            {options.products.icms_situation ?? '—'} e unidade {options.products.commercial_unit ?? '—'} das
                                            configurações fiscais.
                                        </p>
                                        {missingPartTaxes.length > 0 && (
                                            <p className="text-destructive text-xs">Sem NCM ou CFOP no cadastro: {missingPartTaxes.join(', ')}.</p>
                                        )}
                                    </section>
                                )}

                                <section className="grid gap-2 sm:grid-cols-2">
                                    <div className="grid gap-1">
                                        <Label htmlFor="standalone-method">
                                            Forma de pagamento{isService && financeMode !== 'received' ? ' (opcional)' : ''}
                                        </Label>
                                        <Select value={form.data.payment_method} onValueChange={(value) => form.setData('payment_method', value)}>
                                            <SelectTrigger id="standalone-method">
                                                <SelectValue placeholder="Selecione" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {options.payment_methods.map((method) => (
                                                    <SelectItem key={method} value={method}>
                                                        {methodLabels[method] ?? method}
                                                    </SelectItem>
                                                ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                    <div className="flex items-end justify-end text-sm">
                                        Total: <strong className="ml-1">{money(total)}</strong>
                                    </div>
                                </section>

                                <section className="grid gap-3 rounded-md border p-3">
                                    <div className="flex items-start gap-2">
                                        <Checkbox
                                            id="standalone-finance"
                                            checked={registerFinance}
                                            disabled={!options.finance_enabled}
                                            onCheckedChange={(value) => {
                                                setRegisterFinance(!!value);
                                                if (value && form.data.finance_mode === 'none') form.setData('finance_mode', 'receivable');
                                            }}
                                        />
                                        <div className="grid gap-0.5">
                                            <Label htmlFor="standalone-finance">6. Registrar no financeiro</Label>
                                            <span className="text-muted-foreground text-xs">
                                                {options.finance_enabled
                                                    ? 'Opcional. A autorização da nota não dá baixa: o recebimento só entra quando informado.'
                                                    : 'Financeiro desabilitado para esta conta ou sem permissão do usuário.'}
                                            </span>
                                        </div>
                                    </div>

                                    {registerFinance && (
                                        <div className="grid gap-3 pl-6">
                                            <div className="grid gap-2 sm:grid-cols-2">
                                                {(['receivable', 'received'] as const).map((mode) => (
                                                    <button
                                                        key={mode}
                                                        type="button"
                                                        onClick={() => {
                                                            form.setData((data) => ({
                                                                ...data,
                                                                finance_mode: mode,
                                                                paid_at: mode === 'received' && !data.paid_at ? today() : data.paid_at,
                                                            }));
                                                        }}
                                                        className={cn(
                                                            'rounded-md border p-2 text-left text-sm',
                                                            form.data.finance_mode === mode ? 'border-primary bg-primary/5' : 'hover:bg-muted',
                                                        )}
                                                    >
                                                        {mode === 'receivable' ? 'Conta a receber (com vencimento)' : 'Recebimento já realizado'}
                                                    </button>
                                                ))}
                                            </div>
                                            {form.data.finance_mode === 'receivable' ? (
                                                <div className="grid gap-1 sm:max-w-xs">
                                                    <Label htmlFor="standalone-due">Vencimento</Label>
                                                    <Input
                                                        id="standalone-due"
                                                        type="date"
                                                        value={form.data.due_date}
                                                        onChange={(e) => form.setData('due_date', e.target.value)}
                                                    />
                                                </div>
                                            ) : (
                                                <div className="grid gap-1 sm:max-w-xs">
                                                    <Label htmlFor="standalone-paid">Data do recebimento</Label>
                                                    <Input
                                                        id="standalone-paid"
                                                        type="date"
                                                        max={today()}
                                                        value={form.data.paid_at}
                                                        onChange={(e) => form.setData('paid_at', e.target.value)}
                                                    />
                                                    <span className="text-muted-foreground text-xs">
                                                        Entra no caixa aberto; sem caixa aberto, a emissão é recusada.
                                                    </span>
                                                </div>
                                            )}
                                            <div className="grid gap-1">
                                                <Label htmlFor="standalone-notes">Observação do lançamento (opcional)</Label>
                                                <Input
                                                    id="standalone-notes"
                                                    maxLength={500}
                                                    value={form.data.notes}
                                                    onChange={(e) => form.setData('notes', e.target.value)}
                                                />
                                            </div>
                                        </div>
                                    )}
                                </section>
                            </>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-3 text-sm">
                        {options.environment !== 'production' && (
                            <Alert>
                                <AlertDescription>Ambiente de homologação: a nota não tem valor fiscal.</AlertDescription>
                            </Alert>
                        )}
                        <dl className="grid gap-2 sm:grid-cols-[160px_1fr]">
                            <dt className="text-muted-foreground">Tipo</dt>
                            <dd>{type ? modelLabels[type].title : '—'}</dd>
                            <dt className="text-muted-foreground">Cliente</dt>
                            <dd>{customer?.label ?? 'Consumidor não identificado'}</dd>
                            {isService ? (
                                <>
                                    <dt className="text-muted-foreground">Serviço</dt>
                                    <dd className="whitespace-pre-line">{form.data.description}</dd>
                                    <dt className="text-muted-foreground">Tributação</dt>
                                    <dd>
                                        Item {form.data.service_list_item || options.nfse.service_list_item || '—'}, ISS {issRate ?? '—'}%,{' '}
                                        {taxationLabels[options.nfse.taxation_type ?? ''] ?? options.nfse.taxation_type ?? '—'}
                                    </dd>
                                </>
                            ) : (
                                <>
                                    <dt className="text-muted-foreground">Produtos</dt>
                                    <dd>
                                        <ul className="grid gap-1">
                                            {productRows.map((row, index) => (
                                                <li key={index}>
                                                    {toNumber(row.quantity)} × {row.part!.label} — {money(toNumber(row.unit_price))}
                                                    <span className="text-muted-foreground">
                                                        {' '}
                                                        (NCM {row.part!.ncm || '—'}, CFOP {row.part!.cfop || '—'})
                                                    </span>
                                                </li>
                                            ))}
                                        </ul>
                                    </dd>
                                </>
                            )}
                            <dt className="text-muted-foreground">Pagamento</dt>
                            <dd>{form.data.payment_method ? (methodLabels[form.data.payment_method] ?? form.data.payment_method) : '—'}</dd>
                            <dt className="text-muted-foreground">Total</dt>
                            <dd className="font-semibold">{money(total)}</dd>
                            <dt className="text-muted-foreground">Financeiro</dt>
                            <dd>
                                {financeMode === 'none' && 'Não registrar'}
                                {financeMode === 'receivable' &&
                                    `Conta a receber com vencimento em ${new Date(`${form.data.due_date}T12:00:00`).toLocaleDateString('pt-BR')}`}
                                {financeMode === 'received' &&
                                    `Recebido em ${new Date(`${form.data.paid_at}T12:00:00`).toLocaleDateString('pt-BR')} (entrada no caixa)`}
                            </dd>
                        </dl>
                    </div>
                )}

                <DialogFooter className="gap-2">
                    {step === 'form' ? (
                        <>
                            <Button variant="ghost" onClick={() => close(false)}>
                                Cancelar
                            </Button>
                            <Button disabled={!type || !!blocker} onClick={goToSummary}>
                                Revisar
                            </Button>
                        </>
                    ) : (
                        <>
                            <Button variant="ghost" disabled={form.processing} onClick={() => setStep('form')}>
                                Voltar
                            </Button>
                            <Button disabled={form.processing} onClick={submit}>
                                {form.processing ? 'Enviando...' : 'Emitir nota'}
                            </Button>
                        </>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
