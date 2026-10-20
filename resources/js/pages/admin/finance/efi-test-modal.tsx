import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { money } from '@/pages/admin/fiscal/fiscal-tabs';
import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';

export type IntegrationTest = {
    id: number;
    source: string;
    method: 'pix' | 'billet' | 'card';
    environment: 'homologation' | 'production';
    amount: string;
    provider_id: string | null;
    reference: string;
    status: string;
    provider_status: string | null;
    error: string | null;
    pix_copy_paste: string | null;
    pix_qr_image: string | null;
    barcode: string | null;
    billet_link: string | null;
    billet_pdf: string | null;
    due_date: string | null;
    card_mask: string | null;
    expires_at: string | null;
    paid_at: string | null;
    webhook_received_at: string | null;
    webhook_count: number;
    last_checked_at: string | null;
    created_at: string | null;
    created_by: string | null;
};

export type IntegrationTestConfig = {
    methods: string[];
    minimum_cents: Record<string, number>;
    payee_code: string | null;
    recent: IntegrationTest[];
};

type Method = 'pix' | 'billet' | 'card';

const methodLabels: Record<Method, string> = { pix: 'Pix', billet: 'Boleto', card: 'Cartão de crédito' };
export const testStatusLabels: Record<string, string> = {
    pending: 'Aguardando',
    paid: 'Paga',
    failed: 'Recusada',
    cancelled: 'Cancelada',
    expired: 'Expirada',
    refunded: 'Estornada',
    error: 'Erro na criação',
};
const statusVariant = (status: string) =>
    status === 'paid' ? 'default' : status === 'error' || status === 'failed' ? 'destructive' : status === 'pending' ? 'secondary' : 'outline';
const dateTime = (value: string | null) => (value ? new Date(value).toLocaleString('pt-BR') : '-');

/** Dados fictícios de pagador para homologação (CPF com dígitos válidos, sem pessoa real). */
const homologationPayer = {
    name: 'Teste Integracao VetorOS',
    cpf: '52998224725',
    email: 'teste.integracao@vetoros.test',
    birth: '1990-01-01',
    phone_number: '51999999999',
};
const homologationBilling = { street: 'Rua Teste', number: '100', neighborhood: 'Centro', zipcode: '90010000', city: 'Porto Alegre', state: 'RS' };
const empty = (source: Record<string, string>) => Object.fromEntries(Object.keys(source).map((key) => [key, ''])) as Record<string, string>;

type ErrorData = { errors?: Record<string, string[]>; message?: string };

/**
 * Modal "Testar pagamento" (VETOROS-EFI-TEST-01). Cria cobranças de teste isoladas (source=integration_test)
 * pela integração Efí já configurada. O cartão é tokenizado no navegador pela biblioteca oficial
 * `payment-token-efi`: número, CVV e validade nunca vão ao servidor. Em produção exige confirmação e senha.
 */
export default function EfiTestModal({
    open,
    onOpenChange,
    environment,
    config,
    initial = null,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    environment: 'homologation' | 'production';
    config: IntegrationTestConfig;
    initial?: IntegrationTest | null;
}) {
    const production = environment === 'production';
    const methods = (['pix', 'billet', 'card'] as Method[]).filter((method) => config.methods.includes(method));
    const [method, setMethod] = useState<Method>(methods[0] ?? 'pix');
    const [amount, setAmount] = useState('1,00');
    const [payer, setPayer] = useState<Record<string, string>>(production ? empty(homologationPayer) : homologationPayer);
    const [billing, setBilling] = useState<Record<string, string>>(production ? empty(homologationBilling) : homologationBilling);
    const [card, setCard] = useState({ number: '', holderName: '', cvv: '', expirationMonth: '', expirationYear: '' });
    const [confirmProduction, setConfirmProduction] = useState(false);
    const [password, setPassword] = useState('');
    const [steps, setSteps] = useState<string[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [error, setError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const [checking, setChecking] = useState(false);
    const [result, setResult] = useState<IntegrationTest | null>(initial);

    useEffect(() => {
        if (open) setResult(initial);
    }, [open, initial]);

    const minimum = (config.minimum_cents[method] ?? 1) / 100;
    const step = (text: string) => setSteps((current) => [...current, `${new Date().toLocaleTimeString('pt-BR')} · ${text}`]);

    const fail = (e: unknown) => {
        const response = (e as { response?: { status?: number; data?: ErrorData & Partial<IntegrationTest> } } | null)?.response;
        if (response?.status === 422 && response.data?.errors) {
            setErrors(Object.fromEntries(Object.entries(response.data.errors).map(([key, messages]) => [key, messages[0]])));
            setError('Confira os campos destacados.');
            step('Validação recusou o envio.');
            return;
        }
        if (response?.data && 'status' in response.data && response.data.id) {
            // Teste registrado com erro técnico da Efí (mensagem saneada no servidor).
            setResult(response.data as IntegrationTest);
            step('A Efí recusou a operação.');
            return;
        }
        setError(response?.data?.message ?? 'Falha inesperada. Tente novamente.');
        step('Falha na comunicação.');
    };

    /** Tokeniza o cartão na Efí, direto do navegador; devolve só token e máscara. */
    const tokenize = async (): Promise<{ payment_token: string; card_mask: string } | null> => {
        const { default: EfiPay } = await import('payment-token-efi');
        const efiEnvironment = production ? 'production' : 'sandbox';
        const number = card.number.replace(/\D/g, '');
        const brand = await EfiPay.CreditCard.setEnvironment(efiEnvironment).setCardNumber(number).verifyCardBrand();
        const token = await EfiPay.CreditCard.setAccount(String(config.payee_code))
            .setEnvironment(efiEnvironment)
            .setCreditCardData({
                brand,
                number,
                cvv: card.cvv,
                expirationMonth: card.expirationMonth.padStart(2, '0'),
                expirationYear: card.expirationYear.length === 2 ? `20${card.expirationYear}` : card.expirationYear,
                holderName: card.holderName,
                holderDocument: payer.cpf.replace(/\D/g, ''),
                reuse: false,
            })
            .getPaymentToken();

        if (!('payment_token' in token)) {
            setError(token.error_description || 'Cartão não aceito pela Efí. Confira os dados.');
            step('Tokenização recusada pela Efí.');
            return null;
        }

        return { payment_token: token.payment_token, card_mask: token.card_mask };
    };

    const generate = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setErrors({});
        setError(null);
        setSteps([]);
        setResult(null);
        try {
            let tokenized = null;
            if (method === 'card') {
                step('Tokenizando o cartão na Efí (os dados não passam pelo VetorOS)...');
                tokenized = await tokenize();
                if (!tokenized) return;
                step(`Cartão tokenizado (${tokenized.card_mask}).`);
                setCard({ number: '', holderName: card.holderName, cvv: '', expirationMonth: '', expirationYear: '' });
            }
            step(`Criando cobrança ${methodLabels[method]} na Efí (${production ? 'produção' : 'homologação'})...`);
            const response = await axios.post(route('admin.finance.efi.integration-tests.store'), {
                method,
                amount,
                payer: method === 'pix' && !payer.name ? undefined : payer,
                billing: method === 'card' ? billing : undefined,
                card: tokenized ?? undefined,
                confirm_production: production ? confirmProduction : undefined,
                password: production ? password : undefined,
            });
            setResult(response.data);
            setPassword('');
            setConfirmProduction(false);
            step(`Cobrança criada: ${response.data.provider_id}.`);
        } catch (err) {
            fail(err);
        } finally {
            setLoading(false);
        }
    };

    const refresh = useCallback(async () => {
        if (!result) return;
        setChecking(true);
        try {
            const response = await axios.post(route('admin.finance.efi.integration-tests.refresh', result.id));
            setResult(response.data);
            setSteps((current) => [
                ...current,
                `${new Date().toLocaleTimeString('pt-BR')} · Consulta à Efí: ${response.data.provider_status ?? '-'}.`,
            ]);
        } catch (err) {
            fail(err);
        } finally {
            setChecking(false);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [result]);

    // Atualização automática: acompanha o registro local, que o webhook atualiza ao confirmar na API.
    useEffect(() => {
        if (!open || !result || result.status !== 'pending') return;
        const interval = setInterval(async () => {
            try {
                const response = await axios.get(route('admin.finance.efi.integration-tests.show', result.id));
                if (response.data.status !== result.status || response.data.webhook_count !== result.webhook_count) {
                    setResult(response.data);
                    if (response.data.webhook_count !== result.webhook_count) {
                        setSteps((current) => [...current, `${new Date().toLocaleTimeString('pt-BR')} · Webhook da Efí recebido.`]);
                    }
                }
            } catch {
                // Falha momentânea: tenta de novo no próximo ciclo.
            }
        }, 5000);

        return () => clearInterval(interval);
    }, [open, result]);

    const field = (group: 'payer' | 'billing', key: string, label: string, props: React.ComponentProps<typeof Input> = {}) => {
        const values = group === 'payer' ? payer : billing;
        const setValues = group === 'payer' ? setPayer : setBilling;

        return (
            <div className="grid gap-1">
                <Label htmlFor={`efi-test-${group}-${key}`} className="text-xs">
                    {label}
                </Label>
                <Input
                    id={`efi-test-${group}-${key}`}
                    value={values[key] ?? ''}
                    onChange={(e) => setValues({ ...values, [key]: e.target.value })}
                    {...props}
                />
                <InputError message={errors[`${group}.${key}`]} />
            </div>
        );
    };

    const image = result?.pix_qr_image
        ? result.pix_qr_image.startsWith('data:')
            ? result.pix_qr_image
            : `data:image/png;base64,${result.pix_qr_image}`
        : null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>Testar pagamento Efí</DialogTitle>
                    <DialogDescription>
                        Cobrança marcada como teste de integração: não vincula empresa, contrato ou assinatura, não gera nota fiscal e não entra no
                        financeiro.
                    </DialogDescription>
                </DialogHeader>

                <div className="flex flex-wrap items-center gap-2 text-sm">
                    Ambiente ativo:
                    <Badge variant={production ? 'destructive' : 'secondary'}>{production ? 'Produção' : 'Homologação'}</Badge>
                    {production && <span className="text-xs text-rose-600">Cobranças em produção são reais.</span>}
                </div>

                {methods.length === 0 && (
                    <p className="text-muted-foreground text-sm">Nenhum método com credenciais configuradas. Cadastre as credenciais primeiro.</p>
                )}

                {methods.length > 0 && (
                    <form onSubmit={generate} className="space-y-4">
                        <div className="grid gap-2">
                            <Label>Método</Label>
                            <div className="flex flex-wrap gap-2">
                                {methods.map((item) => (
                                    <Button
                                        key={item}
                                        type="button"
                                        size="sm"
                                        variant={method === item ? 'default' : 'outline'}
                                        onClick={() => setMethod(item)}
                                        aria-pressed={method === item}
                                    >
                                        {methodLabels[item]}
                                    </Button>
                                ))}
                            </div>
                        </div>

                        <div className="grid gap-1 sm:max-w-xs">
                            <Label htmlFor="efi-test-amount">Valor (R$)</Label>
                            <Input id="efi-test-amount" inputMode="decimal" value={amount} onChange={(e) => setAmount(e.target.value)} />
                            <p className="text-muted-foreground text-xs">
                                Mínimo para {methodLabels[method]}: {money(minimum)}.
                            </p>
                            <InputError message={errors.amount} />
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2">
                            <p className="text-muted-foreground text-xs sm:col-span-2">
                                {method === 'pix' ? 'Pagador (opcional no Pix)' : 'Pagador'}
                                {!production && ' · dados fictícios de homologação, editáveis'}
                            </p>
                            {field('payer', 'name', 'Nome')}
                            {field('payer', 'cpf', 'CPF', { inputMode: 'numeric' })}
                            {method !== 'pix' && field('payer', 'email', 'E-mail', { type: 'email' })}
                            {method === 'card' && field('payer', 'birth', 'Nascimento', { type: 'date' })}
                            {method === 'card' && field('payer', 'phone_number', 'Telefone', { inputMode: 'tel' })}
                        </div>

                        {method === 'card' && (
                            <>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <p className="text-muted-foreground text-xs sm:col-span-2">
                                        Cartão: em homologação use o cartão de teste indicado pela Efí. Os dados vão direto para a Efí e não são
                                        armazenados no VetorOS.
                                    </p>
                                    <div className="grid gap-1 sm:col-span-2">
                                        <Label htmlFor="efi-test-card-number" className="text-xs">
                                            Número do cartão
                                        </Label>
                                        <Input
                                            id="efi-test-card-number"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            value={card.number}
                                            onChange={(e) => setCard({ ...card, number: e.target.value })}
                                            required
                                        />
                                    </div>
                                    <div className="grid gap-1 sm:col-span-2">
                                        <Label htmlFor="efi-test-card-holder" className="text-xs">
                                            Nome impresso
                                        </Label>
                                        <Input
                                            id="efi-test-card-holder"
                                            autoComplete="off"
                                            value={card.holderName}
                                            onChange={(e) => setCard({ ...card, holderName: e.target.value })}
                                            required
                                        />
                                    </div>
                                    <div className="grid grid-cols-3 gap-2 sm:col-span-2">
                                        <Input
                                            aria-label="Mês de validade"
                                            placeholder="MM"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            value={card.expirationMonth}
                                            onChange={(e) => setCard({ ...card, expirationMonth: e.target.value })}
                                            required
                                        />
                                        <Input
                                            aria-label="Ano de validade"
                                            placeholder="AAAA"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            value={card.expirationYear}
                                            onChange={(e) => setCard({ ...card, expirationYear: e.target.value })}
                                            required
                                        />
                                        <Input
                                            aria-label="CVV"
                                            placeholder="CVV"
                                            inputMode="numeric"
                                            autoComplete="off"
                                            value={card.cvv}
                                            onChange={(e) => setCard({ ...card, cvv: e.target.value })}
                                            required
                                        />
                                    </div>
                                    <InputError message={errors['card.payment_token']} />
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <p className="text-muted-foreground text-xs sm:col-span-2">Endereço de cobrança</p>
                                    {field('billing', 'street', 'Rua')}
                                    {field('billing', 'number', 'Número')}
                                    {field('billing', 'neighborhood', 'Bairro')}
                                    {field('billing', 'zipcode', 'CEP', { inputMode: 'numeric' })}
                                    {field('billing', 'city', 'Cidade')}
                                    {field('billing', 'state', 'UF', { maxLength: 2 })}
                                </div>
                            </>
                        )}

                        {production && (
                            <div className="space-y-2 rounded-md border border-rose-300 p-3">
                                <label className="flex items-start gap-2 text-sm">
                                    <Checkbox checked={confirmProduction} onCheckedChange={(value) => setConfirmProduction(value === true)} />
                                    Confirmo a criação de uma cobrança REAL em produção, no valor de R$ {amount}.
                                </label>
                                <InputError message={errors.confirm_production} />
                                <Input type="password" placeholder="Sua senha" value={password} onChange={(e) => setPassword(e.target.value)} />
                                <InputError message={errors.password} />
                            </div>
                        )}

                        {error && (
                            <p role="alert" className="rounded bg-rose-50 p-2 text-sm text-rose-700 dark:bg-rose-950 dark:text-rose-200">
                                {error}
                            </p>
                        )}

                        <Button type="submit" disabled={loading || (production && !confirmProduction)} className="w-full sm:w-auto">
                            {loading ? 'Gerando...' : 'Gerar cobrança de teste'}
                        </Button>
                    </form>
                )}

                {steps.length > 0 && (
                    <ol className="text-muted-foreground space-y-1 border-t pt-3 text-xs" aria-live="polite">
                        {steps.map((item, index) => (
                            <li key={index}>{item}</li>
                        ))}
                    </ol>
                )}

                {result && <TestResult result={result} image={image} checking={checking} onRefresh={refresh} />}
            </DialogContent>
        </Dialog>
    );
}

function TestResult({
    result,
    image,
    checking,
    onRefresh,
}: {
    result: IntegrationTest;
    image: string | null;
    checking: boolean;
    onRefresh: () => void;
}) {
    const ok = result.status !== 'error' && result.status !== 'failed';

    return (
        <div className="space-y-3 border-t pt-3 text-sm" role="status" aria-live="polite">
            <p className={ok ? 'font-medium text-emerald-700 dark:text-emerald-400' : 'font-medium text-rose-600'}>
                {result.status === 'error'
                    ? 'Falha ao criar a cobrança de teste.'
                    : result.status === 'failed'
                      ? 'Pagamento recusado pela Efí.'
                      : result.status === 'paid'
                        ? 'Pagamento confirmado pela Efí.'
                        : 'Cobrança de teste criada com sucesso.'}
            </p>
            <dl className="grid grid-cols-[auto_1fr] gap-x-3 gap-y-1 text-xs sm:text-sm">
                <dt className="text-muted-foreground">Método</dt>
                <dd>{methodLabels[result.method]}</dd>
                <dt className="text-muted-foreground">Valor</dt>
                <dd>{money(result.amount)}</dd>
                <dt className="text-muted-foreground">Ambiente</dt>
                <dd>{result.environment === 'production' ? 'Produção' : 'Homologação'}</dd>
                <dt className="text-muted-foreground">ID Efí</dt>
                <dd className="break-all">{result.provider_id ?? '-'}</dd>
                <dt className="text-muted-foreground">Situação</dt>
                <dd>
                    <Badge variant={statusVariant(result.status)}>{testStatusLabels[result.status] ?? result.status}</Badge>
                    {result.provider_status && <span className="text-muted-foreground ml-2 text-xs">Efí: {result.provider_status}</span>}
                </dd>
                {result.method !== 'card' && (
                    <>
                        <dt className="text-muted-foreground">Webhook</dt>
                        <dd>
                            {result.webhook_received_at
                                ? `Recebido em ${dateTime(result.webhook_received_at)} (${result.webhook_count}x)`
                                : 'Ainda não recebido'}
                        </dd>
                    </>
                )}
                {result.paid_at && (
                    <>
                        <dt className="text-muted-foreground">Pago em</dt>
                        <dd>{dateTime(result.paid_at)}</dd>
                    </>
                )}
                {result.due_date && (
                    <>
                        <dt className="text-muted-foreground">Vencimento</dt>
                        <dd>{new Date(`${result.due_date}T12:00:00`).toLocaleDateString('pt-BR')}</dd>
                    </>
                )}
                {result.card_mask && (
                    <>
                        <dt className="text-muted-foreground">Cartão</dt>
                        <dd>{result.card_mask}</dd>
                    </>
                )}
                <dt className="text-muted-foreground">Última consulta</dt>
                <dd>{dateTime(result.last_checked_at)}</dd>
            </dl>
            {result.error && <p className="rounded bg-rose-50 p-2 text-xs text-rose-700 dark:bg-rose-950 dark:text-rose-200">{result.error}</p>}
            {image && <img src={image} alt="QR Code Pix do teste" className="mx-auto h-48 w-48 max-w-full rounded border bg-white p-2" />}
            {result.pix_copy_paste && (
                <div className="grid gap-1">
                    <Label htmlFor="efi-test-copy" className="text-xs">
                        Pix Copia e Cola
                    </Label>
                    <div className="flex gap-1">
                        <Input id="efi-test-copy" readOnly value={result.pix_copy_paste} className="min-w-0 text-xs" />
                        <Button type="button" variant="outline" size="sm" onClick={() => navigator.clipboard?.writeText(result.pix_copy_paste ?? '')}>
                            Copiar
                        </Button>
                    </div>
                </div>
            )}
            {result.barcode && (
                <div className="grid gap-1">
                    <Label htmlFor="efi-test-barcode" className="text-xs">
                        Linha digitável
                    </Label>
                    <Input id="efi-test-barcode" readOnly value={result.barcode} className="text-xs" />
                </div>
            )}
            {(result.billet_pdf || result.billet_link) && (
                <a href={result.billet_pdf ?? result.billet_link ?? '#'} target="_blank" rel="noreferrer" className="text-sm underline">
                    Abrir boleto
                </a>
            )}
            {result.provider_id && (
                <Button type="button" variant="outline" onClick={onRefresh} disabled={checking}>
                    {checking ? 'Consultando...' : 'Consultar novamente'}
                </Button>
            )}
            {result.status === 'pending' && result.method !== 'card' && (
                <p className="text-muted-foreground text-xs">A situação é atualizada automaticamente quando o webhook da Efí chegar.</p>
            )}
        </div>
    );
}
