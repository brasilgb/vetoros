/**
 * Valores monetários em BRL (VETOR-UX-MONEY-01).
 *
 * - O valor que trafega com o backend é uma string normalizada com ponto e duas casas
 *   ("1234.56", "-10.00") ou '' quando vazio: o mesmo formato das colunas DECIMAL e do
 *   antigo maskMoneyDot, então nenhuma regra do Laravel muda.
 * - Tudo é feito sobre texto e centavos inteiros: nada de float na conversão, no
 *   arredondamento ou nos totais. A exibição usa Intl.NumberFormat('pt-BR').
 * - Percentuais, quantidades, alíquotas e medidas não passam por aqui.
 *
 * Sem dependências de projeto: é testado direto pelo Node (tests/js/money.test.ts).
 */

export type MoneyValue = string | number | null | undefined;

const BRL = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 2, maximumFractionDigits: 2 });
const BRL_PLAIN = new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const BRL_WHOLE = new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL', minimumFractionDigits: 0, maximumFractionDigits: 0 });

/** Maior valor aceito na digitação: 13 dígitos inteiros cabem em DECIMAL(15,2) e em centavos exatos. */
export const MAX_MONEY_INTEGER_DIGITS = 13;

/**
 * Converte o que vier (número, "1234.56" do banco, "1.234,56", "R$ 1.234,56", "1,234.56")
 * em "1234.56". Retorna '' para vazio ou texto sem número. Arredonda metade para cima
 * na segunda casa, sobre os dígitos.
 */
export function normalizeMoney(value: MoneyValue, options: { allowNegative?: boolean } = {}): string {
    if (value === null || value === undefined) return '';

    let text: string;
    if (typeof value === 'number') {
        if (!Number.isFinite(value)) return '';
        // toFixed(2) arredonda o float só para exibição/serialização; o resto segue em texto.
        text = value.toFixed(2);
    } else {
        text = String(value);
    }

    text = text.replace(/R\$/gi, '').replace(/\s/g, '');
    if (text === '') return '';

    let negative = false;
    if (/^\(.*\)$/.test(text)) {
        negative = true;
        text = text.slice(1, -1);
    }
    if (text.startsWith('-')) {
        negative = true;
        text = text.slice(1);
    } else if (text.endsWith('-')) {
        negative = true;
        text = text.slice(0, -1);
    }

    if (!/^[\d.,]+$/.test(text) || !/\d/.test(text)) return '';

    const decimalSeparator = detectDecimalSeparator(text);
    let integerPart = text;
    let fraction = '';

    if (decimalSeparator) {
        const index = text.lastIndexOf(decimalSeparator);
        integerPart = text.slice(0, index);
        fraction = text.slice(index + 1);
    }

    integerPart = integerPart.replace(/[.,]/g, '').replace(/^0+(?=\d)/, '') || '0';
    fraction = fraction.replace(/[.,]/g, '');

    const [roundedInteger, roundedFraction] = roundToCents(integerPart, fraction);
    const isZero = /^0+$/.test(roundedInteger + roundedFraction);
    const sign = negative && options.allowNegative !== false && !isZero ? '-' : '';

    return `${sign}${roundedInteger}.${roundedFraction}`;
}

/**
 * Qual separador é o decimal. Com os dois, vale o último. Só vírgula: decimal (padrão
 * brasileiro), salvo se repetida. Só ponto: decimal, salvo se repetido ou seguido de
 * exatamente três dígitos ("1.250" = mil duzentos e cinquenta).
 */
function detectDecimalSeparator(text: string): ',' | '.' | null {
    const lastComma = text.lastIndexOf(',');
    const lastDot = text.lastIndexOf('.');

    if (lastComma >= 0 && lastDot >= 0) return lastComma > lastDot ? ',' : '.';
    if (lastComma >= 0) return text.indexOf(',') === lastComma ? ',' : null;
    if (lastDot >= 0) {
        if (text.indexOf('.') !== lastDot) return null;

        return text.length - lastDot - 1 === 3 ? null : '.';
    }

    return null;
}

function roundToCents(integerPart: string, fraction: string): [string, string] {
    const cents = (fraction + '00').slice(0, 2);
    const roundUp = fraction.length > 2 && Number(fraction[2]) >= 5;

    if (!roundUp) return [integerPart, cents];

    // Soma 1 centavo sobre os dígitos, propagando o "vai um".
    const digits = (integerPart + cents).split('').map(Number);
    for (let i = digits.length - 1; i >= 0; i--) {
        if (digits[i] < 9) {
            digits[i] += 1;
            break;
        }
        digits[i] = 0;
        if (i === 0) digits.unshift(1);
    }
    const joined = digits.join('');

    return [joined.slice(0, -2).replace(/^0+(?=\d)/, '') || '0', joined.slice(-2)];
}

/** "1234.56" → 123456 (centavos inteiros, exatos até 2^53). '' → 0. */
export function toCents(value: MoneyValue): number {
    const normalized = normalizeMoney(value);
    if (normalized === '') return 0;

    const negative = normalized.startsWith('-');
    const cents = Number(normalized.replace('-', '').replace('.', ''));

    return negative ? -cents : cents;
}

/** 123456 → "1234.56". */
export function fromCents(cents: number): string {
    const rounded = Math.round(cents);
    const negative = rounded < 0;
    const digits = String(Math.abs(rounded)).padStart(3, '0');

    return `${negative ? '-' : ''}${digits.slice(0, -2)}.${digits.slice(-2)}`;
}

/** Soma valores monetários em centavos inteiros e devolve a string normalizada. */
export function sumMoney(values: MoneyValue[]): string {
    return fromCents(values.reduce<number>((total, value) => total + toCents(value), 0));
}

/** Valor × quantidade, arredondado ao centavo (quantidade pode ser fracionária). */
export function multiplyMoney(value: MoneyValue, quantity: number | string): string {
    return fromCents(Math.round(toCents(value) * Number(quantity || 0)));
}

function formatWith(formatter: Intl.NumberFormat, normalized: string): string {
    // A API aceita string decimal exata (Intl.NumberFormat v3); onde não aceitar, o
    // Number de um valor com duas casas é exato o bastante para exibir.
    return formatter.format(normalized as unknown as number);
}

/**
 * Exibição padrão: "R$ 1.234,56". Vazio/nulo vira "R$ 0,00" (como os formatadores
 * antigos), ou o texto de `empty` quando informado.
 */
export function formatBRL(value: MoneyValue, options: { empty?: string; clampNegative?: boolean; wholeReais?: boolean } = {}): string {
    let normalized = normalizeMoney(value);
    if (normalized === '') {
        if (options.empty !== undefined) return options.empty;
        normalized = '0.00';
    }
    if (options.clampNegative && normalized.startsWith('-')) normalized = '0.00';

    // Reais inteiros: só para rótulos compactos (eixos de gráfico), nunca para valores lançados.
    return formatWith(options.wholeReais ? BRL_WHOLE : BRL, normalized);
}

/** Mesmo valor sem o símbolo: "1.234,56" (campos de edição, colunas já rotuladas em R$). */
export function formatMoneyNumber(value: MoneyValue, options: { empty?: string } = {}): string {
    const normalized = normalizeMoney(value);
    if (normalized === '') return options.empty ?? '';

    return formatWith(BRL_PLAIN, normalized);
}

/**
 * Digitação por centavos (comportamento dos campos com maskMoneyDot): os dígitos entram
 * pela direita. "1" → "0.01", "125075" → "1250.75". Sem dígitos → ''.
 */
export function moneyFromCentsDigits(text: string, options: { allowNegative?: boolean } = {}): string {
    const negative = Boolean(options.allowNegative) && text.trim().startsWith('-');
    const digits = text
        .replace(/\D/g, '')
        .replace(/^0+(?=\d)/, '')
        .slice(0, MAX_MONEY_INTEGER_DIGITS + 2);
    if (digits === '') return negative ? '-' : '';

    const padded = digits.padStart(3, '0');
    const normalized = `${padded.slice(0, -2).replace(/^0+(?=\d)/, '') || '0'}.${padded.slice(-2)}`;

    return negative && normalized !== '0.00' ? `-${normalized}` : normalized;
}

/**
 * Texto livre em edição ("1234,5", "12,", "-3"): mantém dígitos, uma vírgula e até duas
 * casas, para o campo não reformatar enquanto a pessoa digita. Ponto vira vírgula quando
 * ainda não há vírgula e não parece separador de milhar.
 */
export function sanitizeMoneyDraft(text: string, options: { allowNegative?: boolean } = {}): string {
    let draft = text.replace(/R\$/gi, '').replace(/\s/g, '');
    const negative = Boolean(options.allowNegative) && draft.startsWith('-');
    draft = draft.replace(/-/g, '');

    if (!draft.includes(',') && /^\d*\.\d{0,2}$/.test(draft)) {
        draft = draft.replace('.', ',');
    }
    draft = draft.replace(/\./g, '');

    const commaIndex = draft.indexOf(',');
    let integerPart = (commaIndex >= 0 ? draft.slice(0, commaIndex) : draft).replace(/\D/g, '').slice(0, MAX_MONEY_INTEGER_DIGITS);
    const fraction =
        commaIndex >= 0
            ? draft
                  .slice(commaIndex + 1)
                  .replace(/\D/g, '')
                  .slice(0, 2)
            : null;

    integerPart = integerPart.replace(/^0+(?=\d)/, '');

    return `${negative ? '-' : ''}${integerPart}${fraction !== null ? `,${fraction}` : ''}`;
}

/** Rascunho da edição livre → valor normalizado ('' se não houver número). */
export function moneyFromDraft(draft: string, options: { allowNegative?: boolean } = {}): string {
    const cleaned = draft.replace(/,$/, '');
    if (cleaned === '' || cleaned === '-') return '';

    return normalizeMoney(cleaned.replace(',', '.').replace(/^(-?)\./, '$10.'), options);
}

/** Valor normalizado → texto de edição sem milhar ("1234,56"), para o campo em foco. */
export function moneyToDraft(value: MoneyValue): string {
    const normalized = normalizeMoney(value);

    return normalized === '' ? '' : normalized.replace('.', ',');
}
