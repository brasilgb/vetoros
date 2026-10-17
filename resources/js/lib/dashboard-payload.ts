/**
 * Validação das respostas das APIs do dashboard (VETOR-STABILIZE-03), sem dependências, testada no Node
 * (tests/js/dashboard-payload.test.ts). Devolve `null` quando a resposta não tem o formato esperado
 * (página HTML, texto, tipo errado): quem chama mostra erro com nova tentativa, e não zeros fictícios.
 * Listas ausentes dentro de uma resposta válida viram listas vazias.
 */

function isRecord(value: unknown): value is Record<string, unknown> {
    return typeof value === 'object' && value !== null && !Array.isArray(value);
}

/** `{ kpis: {...} }` → os indicadores; qualquer outra coisa → null. */
export function parseKpisPayload<T extends object>(payload: unknown): T | null {
    return isRecord(payload) && isRecord(payload.kpis) ? (payload.kpis as T) : null;
}

/** Série diária: a API devolve uma lista; outro formato → null. Itens que não são objeto são descartados. */
export function parseSeriesPayload<T extends object>(payload: unknown): T[] | null {
    return Array.isArray(payload) ? (payload.filter(isRecord) as T[]) : null;
}

const listOrEmpty = <T>(value: unknown): T[] => (Array.isArray(value) ? (value.filter(isRecord) as T[]) : []);

/** Compras: indicadores obrigatórios; rankings e tendência opcionais (ausentes = vazios). */
export function parsePurchasesPayload<K extends object, S extends object, P extends object, D extends object>(
    payload: unknown,
): { kpis: K; topSuppliers: S[]; topParts: P[]; trend: D[] } | null {
    const kpis = parseKpisPayload<K>(payload);
    if (!kpis || !isRecord(payload)) return null;

    return {
        kpis,
        topSuppliers: listOrEmpty<S>(payload.topSuppliers),
        topParts: listOrEmpty<P>(payload.topParts),
        trend: listOrEmpty<D>(payload.trend),
    };
}
