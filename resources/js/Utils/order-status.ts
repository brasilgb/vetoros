export const ORDER_STATUS = {
    OPEN: 1,
    CANCELLED: 2,
    BUDGET_GENERATED: 3,
    BUDGET_APPROVED: 4,
    BUDGET_REJECTED: 5,
    REPAIR_IN_PROGRESS: 6,
    SERVICE_COMPLETED: 7,
    SERVICE_NOT_EXECUTED: 8,
    CUSTOMER_NOTIFIED: 9,
    DELIVERED: 10,
    // 11 e 12 foram aposentados (status de agendamento) e não são reutilizados.
    IN_DIAGNOSIS: 13,
    AWAITING_PART: 14,
    AWAITING_CUSTOMER: 15,
} as const;

export type OrderStatusValue = (typeof ORDER_STATUS)[keyof typeof ORDER_STATUS];

// Ordem de exibição segue o fluxo da OS, não o código numérico.
export const ORDER_STATUS_LABELS: Record<OrderStatusValue, string> = {
    [ORDER_STATUS.OPEN]: 'Ordem Aberta',
    [ORDER_STATUS.IN_DIAGNOSIS]: 'Em diagnóstico',
    [ORDER_STATUS.CANCELLED]: 'Ordem Cancelada',
    [ORDER_STATUS.BUDGET_GENERATED]: 'Orçamento Gerado',
    [ORDER_STATUS.BUDGET_APPROVED]: 'Orçamento Aprovado',
    [ORDER_STATUS.BUDGET_REJECTED]: 'Orçamento reprovado',
    [ORDER_STATUS.AWAITING_CUSTOMER]: 'Aguardando cliente',
    [ORDER_STATUS.AWAITING_PART]: 'Aguardando peça',
    [ORDER_STATUS.REPAIR_IN_PROGRESS]: 'Reparo em andamento',
    [ORDER_STATUS.SERVICE_COMPLETED]: 'Serviço concluído',
    [ORDER_STATUS.SERVICE_NOT_EXECUTED]: 'Serviço não executado',
    [ORDER_STATUS.CUSTOMER_NOTIFIED]: 'Cliente avisado / aguardando retirada',
    [ORDER_STATUS.DELIVERED]: 'Entregue ao cliente',
};

const ORDER_STATUS_DISPLAY_ORDER: OrderStatusValue[] = [
    ORDER_STATUS.OPEN,
    ORDER_STATUS.IN_DIAGNOSIS,
    ORDER_STATUS.BUDGET_GENERATED,
    ORDER_STATUS.AWAITING_CUSTOMER,
    ORDER_STATUS.BUDGET_APPROVED,
    ORDER_STATUS.BUDGET_REJECTED,
    ORDER_STATUS.AWAITING_PART,
    ORDER_STATUS.REPAIR_IN_PROGRESS,
    ORDER_STATUS.SERVICE_COMPLETED,
    ORDER_STATUS.SERVICE_NOT_EXECUTED,
    ORDER_STATUS.CUSTOMER_NOTIFIED,
    ORDER_STATUS.DELIVERED,
    ORDER_STATUS.CANCELLED,
];

export const ORDER_STATUS_OPTIONS = ORDER_STATUS_DISPLAY_ORDER.map((value) => ({
    value: String(value),
    label: ORDER_STATUS_LABELS[value],
}));

export const ORDER_BUDGET_STATUS_OPTIONS = [
    { value: String(ORDER_STATUS.OPEN), label: ORDER_STATUS_LABELS[ORDER_STATUS.OPEN] },
    { value: String(ORDER_STATUS.BUDGET_GENERATED), label: ORDER_STATUS_LABELS[ORDER_STATUS.BUDGET_GENERATED] },
    { value: String(ORDER_STATUS.BUDGET_APPROVED), label: ORDER_STATUS_LABELS[ORDER_STATUS.BUDGET_APPROVED] },
];

export const ORDER_STATUSES_COMPLETED: number[] = [
    ORDER_STATUS.SERVICE_COMPLETED,
    ORDER_STATUS.CUSTOMER_NOTIFIED,
];

export const ORDER_STATUSES_READY_FOR_INVOICE: number[] = [
    ORDER_STATUS.REPAIR_IN_PROGRESS,
    ORDER_STATUS.SERVICE_COMPLETED,
    ORDER_STATUS.SERVICE_NOT_EXECUTED,
];

export const ORDER_STATUSES_DELIVERED_FLOW: number[] = [
    ORDER_STATUS.CUSTOMER_NOTIFIED,
    ORDER_STATUS.DELIVERED,
];

export function orderStatusLabel(value: number): string {
    return ORDER_STATUS_LABELS[value as OrderStatusValue] ?? 'Status desconhecido';
}

/*
 * Matriz de transições — espelho de App\Support\OrderStatus::classifyTransition().
 * O backend é a fonte de verdade; aqui só decidimos quais campos mostrar.
 */
const TERMINAL: number[] = [ORDER_STATUS.CANCELLED, ORDER_STATUS.DELIVERED];
const WAITING: number[] = [ORDER_STATUS.AWAITING_PART, ORDER_STATUS.AWAITING_CUSTOMER];
const REASON_REQUIRED: number[] = [ORDER_STATUS.CANCELLED, ORDER_STATUS.SERVICE_NOT_EXECUTED];
const RANK: Record<number, number> = {
    [ORDER_STATUS.OPEN]: 10,
    [ORDER_STATUS.IN_DIAGNOSIS]: 20,
    [ORDER_STATUS.BUDGET_GENERATED]: 30,
    [ORDER_STATUS.BUDGET_APPROVED]: 40,
    [ORDER_STATUS.BUDGET_REJECTED]: 40,
    [ORDER_STATUS.REPAIR_IN_PROGRESS]: 50,
    [ORDER_STATUS.SERVICE_COMPLETED]: 60,
    [ORDER_STATUS.SERVICE_NOT_EXECUTED]: 60,
    [ORDER_STATUS.CUSTOMER_NOTIFIED]: 70,
    [ORDER_STATUS.DELIVERED]: 80,
};

/**
 * Posição do status no fluxo principal (para progresso e comparações de "já passou de X").
 * Status de espera ficam na etapa em que normalmente ocorrem.
 */
export function orderStatusFlowValue(status: number): number {
    if (status === ORDER_STATUS.IN_DIAGNOSIS) return ORDER_STATUS.OPEN;
    if (status === ORDER_STATUS.AWAITING_CUSTOMER) return ORDER_STATUS.BUDGET_GENERATED;
    if (status === ORDER_STATUS.AWAITING_PART) return ORDER_STATUS.BUDGET_APPROVED;

    return status;
}

export function orderStatusRank(status: number): number {
    return RANK[orderStatusFlowValue(status)] ?? 0;
}

export type OrderStatusChangeRequirement = {
    allowed: boolean;
    reasonRequired: boolean;
    /** Sair de status encerrado: o usuário precisa dizer se é reabertura ou correção. */
    kindRequired: boolean;
    /** Movimento para trás: o usuário pode declarar como correção de lançamento. */
    isBackward: boolean;
};

export function orderStatusChangeRequirement(from: number, to: number): OrderStatusChangeRequirement {
    const none = { allowed: true, reasonRequired: false, kindRequired: false, isBackward: false };

    if (!from || !to || from === to) return none;

    if (TERMINAL.includes(from)) {
        return TERMINAL.includes(to)
            ? { allowed: false, reasonRequired: false, kindRequired: false, isBackward: false }
            : { allowed: true, reasonRequired: true, kindRequired: true, isBackward: true };
    }

    if (REASON_REQUIRED.includes(to)) return { ...none, reasonRequired: true };

    const sameStageForward =
        (from === ORDER_STATUS.BUDGET_APPROVED && to === ORDER_STATUS.BUDGET_REJECTED) ||
        (from === ORDER_STATUS.BUDGET_REJECTED && to === ORDER_STATUS.BUDGET_APPROVED);

    if (WAITING.includes(to) || WAITING.includes(from) || RANK[to] > RANK[from] || sameStageForward) return none;

    return { allowed: true, reasonRequired: true, kindRequired: false, isBackward: true };
}
