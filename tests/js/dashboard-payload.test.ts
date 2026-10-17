// Validação das respostas das abas Financeiro, Compras e Agendamentos (VETOR-STABILIZE-03).
// npm run test:js
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import { parseKpisPayload, parsePurchasesPayload, parseSeriesPayload } from '../../resources/js/lib/dashboard-payload.ts';

const invalid = ['<!DOCTYPE html><html>login</html>', null, undefined, 0, 'texto', []];

describe('parseKpisPayload', () => {
    test('válida: devolve os indicadores, inclusive zeros reais', () => {
        assert.deepEqual(parseKpisPayload({ kpis: { total: 0, orders_count: 3 } }), { total: 0, orders_count: 3 });
        assert.deepEqual(parseKpisPayload({ kpis: {} }), {});
    });

    test('incompleta ou inválida: erro (null), nunca indicadores zerados inventados', () => {
        for (const payload of [...invalid, {}, { kpis: null }, { kpis: [] }, { kpis: 'x' }, { data: [] }]) {
            assert.equal(parseKpisPayload(payload), null, JSON.stringify(payload));
        }
    });
});

describe('parseSeriesPayload', () => {
    test('vazia e válida', () => {
        assert.deepEqual(parseSeriesPayload([]), []);
        assert.deepEqual(parseSeriesPayload([{ date: '2026-10-17', total: 10 }]), [{ date: '2026-10-17', total: 10 }]);
    });

    test('itens malformados são descartados', () => {
        assert.deepEqual(parseSeriesPayload([null, 1, { date: '2026-10-17' }]), [{ date: '2026-10-17' }]);
    });

    test('formato errado é erro', () => {
        for (const payload of ['<html></html>', null, undefined, {}, { data: [] }, 42]) {
            assert.equal(parseSeriesPayload(payload), null, JSON.stringify(payload));
        }
    });
});

describe('parsePurchasesPayload', () => {
    test('válida com rankings e tendência', () => {
        const payload = { kpis: { total_spent: 100 }, topSuppliers: [{ id: 1 }], topParts: [{ id: 2 }], trend: [{ date: '2026-10-17', total: 100 }] };
        assert.deepEqual(parsePurchasesPayload(payload), payload);
    });

    test('rankings ausentes ou nulos viram listas vazias', () => {
        assert.deepEqual(parsePurchasesPayload({ kpis: { total_spent: 0 }, topSuppliers: null }), {
            kpis: { total_spent: 0 },
            topSuppliers: [],
            topParts: [],
            trend: [],
        });
    });

    test('sem indicadores ou inválida é erro', () => {
        for (const payload of [...invalid, {}, { topSuppliers: [] }, { kpis: null }]) {
            assert.equal(parsePurchasesPayload(payload), null, JSON.stringify(payload));
        }
    });
});
