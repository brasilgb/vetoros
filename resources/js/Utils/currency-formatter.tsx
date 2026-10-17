import { formatBRL, type MoneyValue } from '@/lib/money';

/** Mantido para os imports existentes; a formatação é a central (VETOR-UX-MONEY-01). */
export const currencyFormatter = (value?: MoneyValue) => formatBRL(value);
