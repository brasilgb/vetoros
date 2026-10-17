/**
 * Gráfico "Equipamentos recebidos" do dashboard (VETOR-STABILIZE-02).
 *
 * A resposta de /app/chartEquipments/{dias} é lida aqui, sem dependências, para ser testada no Node
 * (tests/js/equipment-chart.test.ts):
 * - `lines`/`data` ausentes, nulos ou vazios significam "sem dados" (estado vazio do gráfico);
 * - resposta que não é um objeto JSON (página HTML de redirecionamento, texto, nulo) é erro real
 *   e não vira um gráfico vazio silenciosamente.
 */

export type ChartLine = { key: string; label: string };
export type ChartPoint = { date: string; [key: string]: string | number };
export type EquipmentBar = { key: string; label: string; value: number; fill: string };

export type EquipmentChartPayload = { ok: true; lines: ChartLine[]; data: ChartPoint[] } | { ok: false; reason: string };

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

export function parseEquipmentChart(payload: unknown): EquipmentChartPayload {
    if (!isRecord(payload)) {
        return { ok: false, reason: 'Resposta inesperada do servidor.' };
    }

    const rawLines = payload.lines;
    const rawData = payload.data;

    if (
        (rawLines !== undefined && rawLines !== null && !Array.isArray(rawLines)) ||
        (rawData !== undefined && rawData !== null && !Array.isArray(rawData))
    ) {
        return { ok: false, reason: 'Resposta inesperada do servidor.' };
    }

    const lines = (rawLines ?? []).filter((line): line is ChartLine => isRecord(line) && typeof line.key === 'string' && line.key !== '');
    const data = (rawData ?? []).filter((point): point is ChartPoint => isRecord(point));

    return { ok: true, lines, data };
}

/** Uma barra por nome de equipamento (somando nomes repetidos), só com totais positivos. */
export function buildEquipmentBars(lines: ChartLine[], data: ChartPoint[]): EquipmentBar[] {
    const grouped = new Map<string, EquipmentBar>();

    lines.forEach((line, index) => {
        const label = String(line.label ?? '').trim() || 'Sem nome';
        const value = data.reduce((acc, item) => acc + (Number(item[line.key]) || 0), 0);

        if (value <= 0) {
            return;
        }

        const existing = grouped.get(label);
        if (existing) {
            existing.value += value;
            return;
        }

        grouped.set(label, {
            key: `equipment_${grouped.size + 1}`,
            label,
            value,
            fill: `var(--chart-${index + 1})`,
        });
    });

    return Array.from(grouped.values());
}
