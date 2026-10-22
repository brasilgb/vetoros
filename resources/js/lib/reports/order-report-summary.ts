import { ORDER_STATUS } from '@/Utils/order-status';

export type WarrantyRankingItem = {
    label: string;
    total: number;
};

export type WarrantySeverity = 'Saudável' | 'Atenção' | 'Crítico';

export const orderTotal = (order: ReportRow) => Number(order.parts_value) + Number(order.service_value);

// Compartilhado entre a visualização em HTML e o PDF para que os dois mostrem os mesmos números.
export function buildOrderReportSummary(data: ReportRow[], reportMeta: ReportMeta) {
    const totalGeral = data.reduce((acc: number, order: ReportRow) => acc + orderTotal(order), 0);
    const deliveredCount = data.filter((order: ReportRow) => Number(order.service_status) === ORDER_STATUS.DELIVERED).length;
    const avgTicket = data.length ? totalGeral / data.length : 0;
    const warrantyReturnCount = data.filter((order: ReportRow) => Boolean(order.is_warranty_return)).length;
    const warrantyReturnRate = Number(reportMeta?.warranty_return_rate ?? 0);
    const warrantyThreshold = Number(reportMeta?.warranty_return_threshold ?? 10);
    const warrantyAlert = Boolean(reportMeta?.warranty_return_alert ?? false);
    const warrantySeverity: WarrantySeverity =
        warrantyReturnRate <= 5 ? 'Saudável' : warrantyReturnRate <= warrantyThreshold ? 'Atenção' : 'Crítico';
    const severityColors =
        warrantySeverity === 'Crítico'
            ? { backgroundColor: '#FEF3C7', borderColor: '#F59E0B', color: '#92400E' }
            : warrantySeverity === 'Atenção'
              ? { backgroundColor: '#EFF6FF', borderColor: '#60A5FA', color: '#1D4ED8' }
              : { backgroundColor: '#ECFDF5', borderColor: '#34D399', color: '#065F46' };
    const topWarrantyTechnicians: WarrantyRankingItem[] = Object.entries(
        data.reduce((acc: Record<string, number>, order: ReportRow) => {
            if (!order.is_warranty_return) {
                return acc;
            }

            const key = order.user?.name || 'Não definido';
            acc[key] = (acc[key] ?? 0) + 1;
            return acc;
        }, {}),
    )
        .map(([label, total]) => ({ label, total: Number(total) }))
        .sort((a, b) => b.total - a.total)
        .slice(0, 5);

    return {
        totalGeral,
        deliveredCount,
        avgTicket,
        warrantyReturnCount,
        warrantyReturnRate,
        warrantyThreshold,
        warrantyAlert,
        warrantySeverity,
        severityColors,
        topWarrantyTechnicians,
    };
}
