// Testes da lógica monetária (VETOR-UX-MONEY-01). Rodam no Node, sem dependências:
// npm run test:js
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import {
    formatBRL,
    formatMoneyNumber,
    fromCents,
    moneyFromCentsDigits,
    moneyFromDraft,
    moneyToDraft,
    multiplyMoney,
    normalizeMoney,
    sanitizeMoneyDraft,
    sumMoney,
    toCents,
} from '../../resources/js/lib/money.ts';

// Intl usa espaço não separável entre "R$" e o número.
const brl = (text: string) => text.replace(' ', '\u00a0');

describe('normalizeMoney', () => {
    test('valores do banco e números', () => {
        assert.equal(normalizeMoney('1250.00'), '1250.00');
        assert.equal(normalizeMoney('1250.5'), '1250.50');
        assert.equal(normalizeMoney(1250), '1250.00');
        assert.equal(normalizeMoney(0.1 + 0.2), '0.30');
        assert.equal(normalizeMoney(0), '0.00');
    });

    test('formato brasileiro e colagens', () => {
        assert.equal(normalizeMoney('0,00'), '0.00');
        assert.equal(normalizeMoney('0,01'), '0.01');
        assert.equal(normalizeMoney('1.250,75'), '1250.75');
        assert.equal(normalizeMoney('R$ 1.250,75'), '1250.75');
        assert.equal(normalizeMoney('R$\u00a01.250,75'), '1250.75');
        assert.equal(normalizeMoney('1,250.75'), '1250.75');
        assert.equal(normalizeMoney('1.250'), '1250.00');
        assert.equal(normalizeMoney('1.234.567,89'), '1234567.89');
        assert.equal(normalizeMoney('12,5'), '12.50');
    });

    test('valores grandes sem perda de precisão', () => {
        assert.equal(normalizeMoney('9.999.999.999.999,99'), '9999999999999.99');
        assert.equal(toCents('9999999999999.99'), 999999999999999);
        assert.equal(formatBRL('9999999999999.99'), brl('R$ 9.999.999.999.999,99'));
    });

    test('negativos só quando permitidos', () => {
        assert.equal(normalizeMoney('-10,50'), '-10.50');
        assert.equal(normalizeMoney('(10,50)'), '-10.50');
        assert.equal(normalizeMoney('-10,50', { allowNegative: false }), '10.50');
        assert.equal(normalizeMoney('-0,00'), '0.00');
    });

    test('vazio e texto inválido', () => {
        assert.equal(normalizeMoney(''), '');
        assert.equal(normalizeMoney(null), '');
        assert.equal(normalizeMoney(undefined), '');
        assert.equal(normalizeMoney('abc'), '');
        assert.equal(normalizeMoney(Number.NaN), '');
    });

    test('arredonda metade para cima sobre os dígitos', () => {
        assert.equal(normalizeMoney('1,005'), '1.01');
        assert.equal(normalizeMoney('1,004'), '1.00');
        assert.equal(normalizeMoney('9,995'), '10.00');
        assert.equal(normalizeMoney('99,999'), '100.00');
    });
});

describe('centavos e totais sem float', () => {
    test('toCents/fromCents', () => {
        assert.equal(toCents('1250.75'), 125075);
        assert.equal(toCents('-0.01'), -1);
        assert.equal(fromCents(125075), '1250.75');
        assert.equal(fromCents(5), '0.05');
        assert.equal(fromCents(-5), '-0.05');
    });

    test('soma e multiplicação exatas', () => {
        assert.equal(sumMoney(['0.10', '0.20']), '0.30');
        assert.equal(sumMoney(['1250.75', '-250.75', '']), '1000.00');
        assert.equal(multiplyMoney('125.00', 2), '250.00');
        assert.equal(multiplyMoney('19.99', 3), '59.97');
        assert.equal(multiplyMoney('10.00', '1.5'), '15.00');
    });
});

describe('exibição', () => {
    test('formatBRL', () => {
        assert.equal(formatBRL('1250.75'), brl('R$ 1.250,75'));
        assert.equal(formatBRL(250), brl('R$ 250,00'));
        // Regressão do maskMoney: total inteiro calculado não pode virar centavos.
        assert.equal(formatBRL(String(2 * 125)), brl('R$ 250,00'));
        assert.equal(formatBRL(null), brl('R$ 0,00'));
        assert.equal(formatBRL(null, { empty: '—' }), '—');
        assert.equal(formatBRL('-10.5'), brl('-R$ 10,50'));
        assert.equal(formatBRL('-10.5', { clampNegative: true }), brl('R$ 0,00'));
    });

    test('formatMoneyNumber', () => {
        assert.equal(formatMoneyNumber('1250.75'), '1.250,75');
        assert.equal(formatMoneyNumber('0'), '0,00');
        assert.equal(formatMoneyNumber(''), '');
    });
});

describe('digitação', () => {
    test('modo centavos (dígitos pela direita)', () => {
        assert.equal(moneyFromCentsDigits(''), '');
        assert.equal(moneyFromCentsDigits('0'), '0.00');
        assert.equal(moneyFromCentsDigits('1'), '0.01');
        assert.equal(moneyFromCentsDigits('0,012'), '0.12');
        assert.equal(moneyFromCentsDigits('1.250,75'), '1250.75');
        assert.equal(moneyFromCentsDigits('1.250,756'), '12507.56');
        assert.equal(moneyFromCentsDigits('-5', { allowNegative: true }), '-0.05');
        assert.equal(moneyFromCentsDigits('-5'), '0.05');
        assert.equal(moneyFromCentsDigits('-', { allowNegative: true }), '-');
    });

    test('modo decimal (edição livre)', () => {
        assert.equal(sanitizeMoneyDraft('1250,75'), '1250,75');
        assert.equal(sanitizeMoneyDraft('12,'), '12,');
        assert.equal(sanitizeMoneyDraft('12,345'), '12,34');
        assert.equal(sanitizeMoneyDraft('1.250,75'), '1250,75');
        assert.equal(sanitizeMoneyDraft('12.5'), '12,5');
        assert.equal(sanitizeMoneyDraft('abc12'), '12');
        assert.equal(sanitizeMoneyDraft('007'), '7');
        assert.equal(sanitizeMoneyDraft('-3', { allowNegative: true }), '-3');
        assert.equal(sanitizeMoneyDraft('-3'), '3');
        assert.equal(moneyFromDraft('1250,75'), '1250.75');
        assert.equal(moneyFromDraft('12,'), '12.00');
        assert.equal(moneyFromDraft(',5'), '0.50');
        assert.equal(moneyFromDraft(''), '');
        assert.equal(moneyFromDraft('-'), '');
        assert.equal(moneyToDraft('1250.75'), '1250,75');
        assert.equal(moneyToDraft(''), '');
    });
});

test('rótulo compacto de eixo em reais inteiros', () => {
    assert.equal(formatBRL('1250.75', { wholeReais: true }), 'R$ 1.251');
    assert.equal(formatBRL(0, { wholeReais: true }), 'R$ 0');
});
