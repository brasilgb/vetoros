import axios from 'axios';
import { useCallback, useEffect, useState } from 'react';

export type Checkout = {
    provider: 'efi';
    unavailable_message?: string;
    methods: string[];
    payee_code?: string | null;
    environment?: 'production' | 'sandbox';
    recurring?: { card_mask: string | null; amount: string; interval_months: number; authorized_at: string | null } | null;
    billing?: {
        street: string | null;
        number: string | null;
        neighborhood: string | null;
        zipcode: string | null;
        city: string | null;
        state: string | null;
        email: string | null;
        phone: string | null;
    };
};

type Result = {
    id: number;
    provider: string;
    method: string;
    status: string;
    qr_code?: string | null;
    qr_code_image?: string | null;
    qr_code_copy_paste?: string | null;
    barcode?: string | null;
    billet_link?: string | null;
    billet_pdf?: string | null;
    card_mask?: string | null;
    due_date?: string | null;
    expires_at?: string | null;
    error?: string | null;
};

export type CheckoutResult = Result;

const tabs: { key: 'pix' | 'billet' | 'card'; label: string }[] = [
    { key: 'pix', label: 'Pix' },
    { key: 'billet', label: 'Boleto' },
    { key: 'card', label: 'Cartão' },
];

const inputClass = 'block w-full rounded-md border border-gray-300 p-2 text-sm focus:border-indigo-500 focus:ring-indigo-500';
const buttonClass =
    'flex w-full justify-center rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white shadow-sm hover:bg-indigo-700 disabled:opacity-50';

type ErrorData = { errors?: Record<string, string[]>; error?: string; message?: string };

const firstError = (error: unknown): string => {
    const data = (error as { response?: { data?: ErrorData } } | null)?.response?.data;
    if (data?.errors) return Object.values(data.errors).flat().join(' ');
    return data?.error ?? data?.message ?? 'Não foi possível gerar o pagamento. Tente novamente.';
};

/**
 * Checkout Efí da assinatura (VETOR-SAAS-EFI-02). O cartão é tokenizado no navegador pela biblioteca
 * oficial `payment-token-efi`: número, CVV e validade nunca vão ao servidor do VetorOS. A liberação
 * só acontece quando o servidor confirma o pagamento na Efí e a cobertura da assinatura permite
 * (consulta acompanhada por polling ou pelo botão "Consultar pagamento"). VETOR-SAAS-EFI-02/04.
 */
export default function EfiCheckout({
    checkout,
    planId,
    onConfirmed,
    initialResult = null,
}: {
    checkout: Checkout;
    planId?: number | null;
    onConfirmed: () => void;
    initialResult?: Result | null;
}) {
    const available = tabs.filter(
        (tab) => checkout.methods.includes(tab.key) || (tab.key === 'card' && checkout.methods.includes('card_subscription')),
    );
    const [tab, setTab] = useState(available[0]?.key ?? 'pix');
    const [result, setResult] = useState<Result | null>(initialResult);
    const [loading, setLoading] = useState(false);
    const [checking, setChecking] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [notice, setNotice] = useState<string | null>(null);

    const billing = checkout.billing;
    const [card, setCard] = useState({
        number: '',
        holderName: '',
        cvv: '',
        expirationMonth: '',
        expirationYear: '',
        cpf: '',
        birth: '',
        email: billing?.email ?? '',
        phone: billing?.phone ?? '',
        street: billing?.street ?? '',
        addressNumber: billing?.number ?? '',
        neighborhood: billing?.neighborhood ?? '',
        zipcode: billing?.zipcode ?? '',
        city: billing?.city ?? '',
        state: billing?.state ?? '',
        recurring: false,
        authorize: false,
    });
    const recurringAvailable = checkout.methods.includes('card_subscription') && !checkout.recurring;
    const singleCardAvailable = checkout.methods.includes('card');

    /**
     * Decide pela situação calculada no servidor (pagamento + cobertura da assinatura). Devolve true quando
     * a transação terminou e o acompanhamento pode parar.
     */
    const evaluate = useCallback(
        (status: string): boolean => {
            switch (status) {
                case 'active':
                    onConfirmed();
                    return true;
                case 'paid_pending_obligations':
                    setResult(null);
                    setNotice('Pagamento confirmado. Ainda há outra cobrança vencida: atualize a página para pagá-la e liberar o acesso.');
                    return true;
                case 'failed':
                    setResult(null);
                    setError('Pagamento recusado. Tente outro meio ou outro cartão.');
                    return true;
                case 'expired':
                case 'cancelled':
                    setResult(null);
                    setError('Esta cobrança expirou ou foi cancelada. Gere uma nova para pagar.');
                    return true;
                default:
                    return false;
            }
        },
        [onConfirmed],
    );

    const follow = useCallback(
        async (paymentId: number): Promise<boolean> => {
            const response = await axios.get(route('subscription.check_status'), { params: { payment: paymentId } });

            return evaluate(response.data.status);
        },
        [evaluate],
    );

    useEffect(() => {
        if (!result || result.status !== 'pending') return;
        const interval = setInterval(async () => {
            try {
                if (await follow(result.id)) clearInterval(interval);
            } catch {
                // Falha momentânea na consulta: tenta de novo no próximo ciclo.
            }
        }, 5000);

        return () => clearInterval(interval);
    }, [result, follow]);

    const handle = async (next: Result) => {
        if (next.status === 'failed') {
            setError(next.error ?? 'Pagamento recusado.');
            return;
        }
        if (next.status === 'paid') {
            // Confirmado pela Efí no servidor; a liberação ainda depende da cobertura da assinatura.
            await follow(next.id);
            return;
        }
        setResult(next);
    };

    /** "Consultar pagamento": o servidor pergunta à Efí agora (útil quando a notificação atrasa). */
    const refresh = async () => {
        if (!result) return;
        setChecking(true);
        setError(null);
        try {
            const response = await axios.post(route('subscription.efi.refresh', result.id));
            if (response.data.status === 'pending') {
                setResult(response.data);
                setNotice('Pagamento ainda não confirmado pelo banco. Assim que for, a liberação é automática.');
            } else {
                await handle(response.data);
                await follow(result.id);
            }
        } catch (e) {
            setError(firstError(e));
        } finally {
            setChecking(false);
        }
    };

    const generate = async (method: 'pix' | 'billet') => {
        setLoading(true);
        setError(null);
        setNotice(null);
        try {
            const response = await axios.post(route(`subscription.efi.${method}`), planId ? { plan_id: planId } : {});
            handle(response.data);
        } catch (e) {
            setError(firstError(e));
        } finally {
            setLoading(false);
        }
    };

    const payWithCard = async (e: React.FormEvent) => {
        e.preventDefault();
        setLoading(true);
        setError(null);
        try {
            const { default: EfiPay } = await import('payment-token-efi');
            const environment = checkout.environment ?? 'sandbox';
            const brand = await EfiPay.CreditCard.setEnvironment(environment).setCardNumber(card.number.replace(/\D/g, '')).verifyCardBrand();
            const token = await EfiPay.CreditCard.setAccount(String(checkout.payee_code))
                .setEnvironment(environment)
                .setCreditCardData({
                    brand,
                    number: card.number.replace(/\D/g, ''),
                    cvv: card.cvv,
                    expirationMonth: card.expirationMonth.padStart(2, '0'),
                    expirationYear: card.expirationYear.length === 2 ? `20${card.expirationYear}` : card.expirationYear,
                    holderName: card.holderName,
                    holderDocument: card.cpf.replace(/\D/g, ''),
                    reuse: card.recurring,
                })
                .getPaymentToken();

            if (!('payment_token' in token)) {
                setError(token.error_description || 'Cartão não aceito. Confira os dados.');
                return;
            }

            const recurring = card.recurring || !singleCardAvailable;
            const response = await axios.post(route('subscription.efi.card'), {
                plan_id: planId ?? null,
                payment_token: token.payment_token,
                card_mask: token.card_mask,
                recurring,
                authorize_recurring: recurring ? card.authorize : undefined,
                holder: { name: card.holderName, cpf: card.cpf, birth: card.birth, email: card.email, phone_number: card.phone },
                billing: {
                    street: card.street,
                    number: card.addressNumber,
                    neighborhood: card.neighborhood,
                    zipcode: card.zipcode,
                    city: card.city,
                    state: card.state,
                },
            });
            handle(response.data);
        } catch (e) {
            setError(firstError(e));
        } finally {
            setLoading(false);
        }
    };

    const cancelRecurring = async () => {
        if (!window.confirm('Cancelar a cobrança recorrente no cartão? As próximas faturas virão por boleto/Pix.')) return;
        try {
            await axios.post(route('subscription.efi.recurring.cancel'), { confirm: true });
            window.location.reload();
        } catch (e) {
            setError(firstError(e));
        }
    };

    const set = (field: keyof typeof card) => (e: React.ChangeEvent<HTMLInputElement>) =>
        setCard({ ...card, [field]: e.target.type === 'checkbox' ? e.target.checked : e.target.value });

    if (result) {
        const image = result.qr_code_image ?? (result.qr_code ? `data:image/png;base64,${result.qr_code}` : null);
        const isBillet = result.method === 'billet';
        const isCard = result.method.startsWith('card');
        const formatDateTime = (value: string) =>
            new Date(value).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });

        return (
            <div className="space-y-4 text-center">
                <div className="rounded-md border border-amber-200 bg-amber-50 p-3" role="status" aria-live="polite">
                    <p className="font-medium text-amber-900">
                        {isBillet
                            ? 'Boleto gerado: aguardando compensação.'
                            : isCard
                              ? 'Pagamento no cartão em análise.'
                              : 'Pix gerado: aguardando pagamento.'}
                    </p>
                    <p className="text-sm text-amber-800">A liberação é automática quando o banco confirmar. Não é preciso enviar comprovante.</p>
                    {!isBillet && result.expires_at && <p className="mt-1 text-xs text-amber-800">Válido até {formatDateTime(result.expires_at)}.</p>}
                    {isBillet && result.due_date && (
                        <p className="mt-1 text-xs text-amber-800">
                            Vencimento: {new Date(`${result.due_date}T12:00:00`).toLocaleDateString('pt-BR')}.
                        </p>
                    )}
                </div>
                {notice && <p className="rounded bg-sky-50 p-2 text-sm text-sky-800">{notice}</p>}
                {error && (
                    <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-600">
                        {error}
                    </p>
                )}
                {image && <img src={image} alt="QR Code Pix para pagamento" className="mx-auto h-56 w-56 max-w-full rounded border p-2" />}
                {result.qr_code_copy_paste && (
                    <div className="text-left">
                        <label htmlFor="efi-pix-copy" className="block text-sm font-medium text-gray-700">
                            Pix copia e cola
                        </label>
                        <div className="mt-1 flex">
                            <input
                                id="efi-pix-copy"
                                readOnly
                                value={result.qr_code_copy_paste}
                                className={`${inputClass} min-w-0 rounded-r-none bg-gray-50 text-xs`}
                            />
                            <button
                                type="button"
                                onClick={() => {
                                    navigator.clipboard?.writeText(result.qr_code_copy_paste ?? '');
                                    setNotice('Código Pix copiado.');
                                }}
                                className="rounded-r-md border border-l-0 border-gray-300 bg-gray-50 px-3 text-sm"
                            >
                                Copiar
                            </button>
                        </div>
                    </div>
                )}
                {result.barcode && (
                    <div className="text-left">
                        <label htmlFor="efi-billet-line" className="block text-sm font-medium text-gray-700">
                            Linha digitável
                        </label>
                        <input id="efi-billet-line" readOnly value={result.barcode} className={`${inputClass} bg-gray-50 text-xs`} />
                    </div>
                )}
                {(result.billet_pdf || result.billet_link) && (
                    <a
                        href={result.billet_pdf ?? result.billet_link ?? '#'}
                        target="_blank"
                        rel="noreferrer"
                        className="inline-block text-sm font-medium text-indigo-600 underline"
                    >
                        Abrir boleto (PDF)
                    </a>
                )}
                {isCard && result.card_mask && <p className="text-sm text-gray-600">Cartão {result.card_mask}.</p>}
                <button type="button" onClick={refresh} disabled={checking} className={buttonClass}>
                    {checking ? 'Consultando...' : 'Consultar pagamento'}
                </button>
                <p className="text-xs text-gray-500">A situação também é verificada automaticamente a cada poucos segundos.</p>
                <button
                    type="button"
                    onClick={() => {
                        setResult(null);
                        setNotice(null);
                    }}
                    className="text-xs text-gray-500 underline"
                >
                    Escolher outro meio de pagamento
                </button>
            </div>
        );
    }

    return (
        <div className="space-y-4">
            {checkout.recurring && (
                <div className="rounded-md border border-indigo-200 bg-indigo-50 p-3 text-sm text-indigo-900">
                    Cobrança automática ativa no cartão {checkout.recurring.card_mask}.{' '}
                    <button type="button" onClick={cancelRecurring} className="underline">
                        Cancelar recorrência
                    </button>
                </div>
            )}

            <div className="flex gap-2">
                {available.map((item) => (
                    <button
                        key={item.key}
                        type="button"
                        onClick={() => setTab(item.key)}
                        className={`flex-1 rounded-md border px-3 py-2 text-sm ${tab === item.key ? 'border-indigo-500 bg-indigo-50 font-medium' : 'border-gray-300'}`}
                    >
                        {item.label}
                    </button>
                ))}
            </div>

            {notice && (
                <p role="status" className="rounded bg-sky-50 p-2 text-sm text-sky-800">
                    {notice}
                </p>
            )}
            {error && (
                <p role="alert" className="rounded bg-red-50 p-2 text-sm text-red-600">
                    {error}
                </p>
            )}

            {tab === 'pix' && (
                <button type="button" onClick={() => generate('pix')} disabled={loading} className={buttonClass}>
                    {loading ? 'Gerando...' : 'Gerar QR Code Pix'}
                </button>
            )}

            {tab === 'billet' && (
                <button type="button" onClick={() => generate('billet')} disabled={loading} className={buttonClass}>
                    {loading ? 'Gerando...' : 'Gerar boleto (com Pix)'}
                </button>
            )}

            {tab === 'card' && (
                <form onSubmit={payWithCard} className="grid grid-cols-2 gap-2 text-sm" autoComplete="on">
                    <input
                        className={`${inputClass} col-span-2`}
                        placeholder="Número do cartão"
                        inputMode="numeric"
                        autoComplete="cc-number"
                        value={card.number}
                        onChange={set('number')}
                        required
                    />
                    <input
                        className={`${inputClass} col-span-2`}
                        placeholder="Nome impresso no cartão"
                        autoComplete="cc-name"
                        value={card.holderName}
                        onChange={set('holderName')}
                        required
                    />
                    <input
                        className={inputClass}
                        placeholder="Mês (MM)"
                        inputMode="numeric"
                        autoComplete="cc-exp-month"
                        value={card.expirationMonth}
                        onChange={set('expirationMonth')}
                        required
                    />
                    <input
                        className={inputClass}
                        placeholder="Ano (AAAA)"
                        inputMode="numeric"
                        autoComplete="cc-exp-year"
                        value={card.expirationYear}
                        onChange={set('expirationYear')}
                        required
                    />
                    <input
                        className={inputClass}
                        placeholder="CVV"
                        inputMode="numeric"
                        autoComplete="cc-csc"
                        value={card.cvv}
                        onChange={set('cvv')}
                        required
                    />
                    <input className={inputClass} placeholder="CPF do titular" inputMode="numeric" value={card.cpf} onChange={set('cpf')} required />
                    <label className="col-span-2 text-xs text-gray-500">Data de nascimento do titular</label>
                    <input className={`${inputClass} col-span-2`} type="date" value={card.birth} onChange={set('birth')} required />
                    <input className={inputClass} placeholder="E-mail" type="email" value={card.email} onChange={set('email')} required />
                    <input className={inputClass} placeholder="Telefone" value={card.phone} onChange={set('phone')} required />
                    <input
                        className={`${inputClass} col-span-2`}
                        placeholder="Endereço de cobrança"
                        value={card.street}
                        onChange={set('street')}
                        required
                    />
                    <input className={inputClass} placeholder="Número" value={card.addressNumber} onChange={set('addressNumber')} required />
                    <input className={inputClass} placeholder="Bairro" value={card.neighborhood} onChange={set('neighborhood')} required />
                    <input className={inputClass} placeholder="CEP" value={card.zipcode} onChange={set('zipcode')} required />
                    <input className={inputClass} placeholder="Cidade" value={card.city} onChange={set('city')} required />
                    <input className={inputClass} placeholder="UF" maxLength={2} value={card.state} onChange={set('state')} required />

                    {recurringAvailable && singleCardAvailable && (
                        <label className="col-span-2 flex items-center gap-2">
                            <input type="checkbox" checked={card.recurring} onChange={set('recurring')} />
                            Cobrar automaticamente neste cartão a cada ciclo
                        </label>
                    )}
                    {recurringAvailable && (card.recurring || !singleCardAvailable) && (
                        <label className="col-span-2 flex items-start gap-2 text-xs text-gray-600">
                            <input type="checkbox" checked={card.authorize} onChange={set('authorize')} className="mt-0.5" />
                            Autorizo a ABrasil Sistemas a cobrar o valor da assinatura do VetorOS neste cartão a cada ciclo contratado, até eu
                            cancelar a recorrência.
                        </label>
                    )}

                    <button type="submit" disabled={loading} className={`${buttonClass} col-span-2 mt-2`}>
                        {loading ? 'Processando...' : 'Pagar com cartão'}
                    </button>
                    <p className="col-span-2 text-xs text-gray-400">Os dados do cartão vão direto para a Efí; o VetorOS não os armazena.</p>
                </form>
            )}
        </div>
    );
}
