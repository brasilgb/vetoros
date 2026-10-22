// Rótulos compartilhados entre a visualização em HTML e os PDFs dos relatórios.

export const SCHEDULE_STATUS_LABELS: Record<number, string> = {
    1: 'Ordem Aberta',
    2: 'Em atendimento',
    3: 'Fechada',
};

const PART_TYPE_LABELS: Record<string, string> = {
    part: 'Peça',
    product: 'Produto',
};

export function partTypeLabel(value: unknown) {
    if (value === null || value === undefined) return '-';
    return PART_TYPE_LABELS[String(value)] ?? '-';
}

export function paymentMethodLabel(method?: string) {
    switch (method) {
        case 'pix':
            return 'Pix';
        case 'cartao':
            return 'Cartão';
        case 'dinheiro':
            return 'Dinheiro';
        case 'transferencia':
            return 'Transferência';
        case 'boleto':
            return 'Boleto';
        default:
            return '-';
    }
}

export function fiscalTargetLabel(document: ReportRow) {
    const type = String(document.documentable_type || '');
    if (type.endsWith('\\Sale')) return `Venda #${document.documentable_id}`;
    if (type.endsWith('\\Order')) return `OS #${document.documentable_id}`;
    return `Registro #${document.documentable_id}`;
}

export function fiscalProviderLabel(provider: string) {
    return provider === 'manual' ? 'Manual' : 'Integração anterior';
}
