// Gráfico "Equipamentos recebidos" (VETOR-STABILIZE-02): leitura da resposta da API e barras.
// npm run test:js
import assert from 'node:assert/strict';
import { describe, test } from 'node:test';

import { buildEquipmentBars, parseEquipmentChart } from '../../resources/js/lib/equipment-chart.ts';

describe('parseEquipmentChart', () => {
    test('resposta vazia: sem linhas nem dados vira estado vazio, não erro', () => {
        assert.deepEqual(parseEquipmentChart({ lines: [], data: [] }), { ok: true, lines: [], data: [] });
        assert.deepEqual(parseEquipmentChart({}), { ok: true, lines: [], data: [] });
    });

    test('resposta incompleta: lines ausente ou nulo não quebra (era o "lines is undefined")', () => {
        const data = [{ date: '2026-10-17', eq_1: 2 }];

        assert.deepEqual(parseEquipmentChart({ data }), { ok: true, lines: [], data });
        assert.deepEqual(parseEquipmentChart({ lines: null, data }), { ok: true, lines: [], data });
        assert.deepEqual(parseEquipmentChart({ lines: [{ key: 'eq_1', label: 'Celular' }], data: null }), {
            ok: true,
            lines: [{ key: 'eq_1', label: 'Celular' }],
            data: [],
        });
        // Itens malformados são descartados, os válidos ficam.
        const parsed = parseEquipmentChart({ lines: [null, { label: 'sem chave' }, { key: 'eq_2', label: 'Tablet' }], data: [] });
        assert.deepEqual(parsed.ok && parsed.lines, [{ key: 'eq_2', label: 'Tablet' }]);
    });

    test('resposta inválida é erro real, nunca gráfico fictício', () => {
        for (const payload of ['<!DOCTYPE html><html>login</html>', null, undefined, 42, [], { lines: 'x', data: [] }, { lines: [], data: {} }]) {
            const parsed = parseEquipmentChart(payload);
            assert.equal(parsed.ok, false, JSON.stringify(payload));
        }
    });

    test('resposta válida preserva linhas e dados', () => {
        const payload = {
            lines: [
                { key: 'eq_1', label: 'Celular' },
                { key: 'eq_2', label: 'Notebook' },
            ],
            data: [
                { date: '2026-10-16', eq_1: 1, eq_2: 0 },
                { date: '2026-10-17', eq_1: '2', eq_2: 3 },
            ],
        };
        assert.deepEqual(parseEquipmentChart(payload), { ok: true, ...payload });
    });
});

describe('buildEquipmentBars', () => {
    test('soma por equipamento, ignora zerados e agrupa nomes repetidos', () => {
        const bars = buildEquipmentBars(
            [
                { key: 'eq_1', label: 'Celular' },
                { key: 'eq_2', label: 'Notebook' },
                { key: 'eq_3', label: ' Celular ' },
                { key: 'eq_4', label: '' },
            ],
            [
                { date: '2026-10-16', eq_1: 1, eq_2: 0, eq_3: 1, eq_4: 0 },
                { date: '2026-10-17', eq_1: '2', eq_2: 0, eq_3: 'x', eq_4: 4 },
            ],
        );

        assert.deepEqual(
            bars.map((bar) => [bar.label, bar.value]),
            [
                ['Celular', 4],
                ['Sem nome', 4],
            ],
        );
    });

    test('sem dados não gera barras', () => {
        assert.deepEqual(buildEquipmentBars([], []), []);
        assert.deepEqual(buildEquipmentBars([{ key: 'eq_1', label: 'Celular' }], []), []);
    });
});
