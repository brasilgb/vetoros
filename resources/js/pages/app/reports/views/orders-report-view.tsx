import { buildOrderReportSummary, orderTotal } from '@/lib/reports/order-report-summary';
import { currencyFormatter } from '@/Utils/currency-formatter';
import { orderStatusLabel } from '@/Utils/order-status';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function OrdersReportView({ data, reportMeta }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    const {
        totalGeral,
        deliveredCount,
        avgTicket,
        warrantyReturnCount,
        warrantyReturnRate,
        warrantyThreshold,
        warrantySeverity,
        severityColors,
        topWarrantyTechnicians,
    } = buildOrderReportSummary(data, reportMeta);

    return (
        <div className="space-y-4">
            <div
                className="rounded-md border px-3 py-2 break-inside-avoid"
                style={{ backgroundColor: severityColors.backgroundColor, borderColor: severityColors.borderColor, color: severityColors.color }}
            >
                <p className="text-[11px] tracking-wide uppercase">Indicador de Garantia</p>
                <p className="text-sm font-semibold">
                    {warrantySeverity} | Taxa atual {warrantyReturnRate}% | Limite configurado {warrantyThreshold}%
                </p>
            </div>

            <div className="grid grid-cols-2 gap-2 sm:grid-cols-5 print:grid-cols-5">
                <SummaryCard label="Ordens no período" value={data.length} />
                <SummaryCard label="Entregues" value={deliveredCount} />
                <SummaryCard label="Valor total" value={currencyFormatter(totalGeral)} />
                <SummaryCard label="Ticket médio" value={currencyFormatter(avgTicket)} />
                <SummaryCard label="Retorno em garantia" value={warrantyReturnCount} />
            </div>

            {topWarrantyTechnicians.length > 0 && (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-5 print:grid-cols-5">
                    {topWarrantyTechnicians.map((item) => (
                        <SummaryCard key={item.label} label="Técnico com retorno" value={`${item.label}: ${item.total}`} />
                    ))}
                </div>
            )}

            <ReportTable
                rows={data}
                emptyMessage="Nenhuma ordem encontrada no período."
                columns={[
                    { header: '#', align: 'center', render: (order) => order.order_number },
                    { header: 'Cliente', render: (order) => order.customer?.name || 'N/A' },
                    { header: 'Modelo', render: (order) => order.model || '—' },
                    { header: 'Técnico', render: (order) => order.user?.name || '—' },
                    { header: 'Status', render: (order) => orderStatusLabel(Number(order.service_status)) },
                    { header: 'Garantia', render: (order) => (order.is_warranty_return ? 'Retorno' : '—') },
                    { header: 'Valor (R$)', align: 'right', nowrap: true, render: (order) => currencyFormatter(orderTotal(order)) },
                ]}
            />
        </div>
    );
}
