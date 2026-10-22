import { paymentMethodLabel } from '@/lib/reports/report-labels';
import { currencyFormatter } from '@/Utils/currency-formatter';
import moment from 'moment';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function SalesReportView({ data, reportMeta }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    const cancelled = data.filter((sale) => sale.status === 'cancelled');
    const totalGeral = data.reduce((acc: number, sale) => acc + Number(sale.total_amount || 0), 0);
    const totalCanceled = cancelled.reduce((acc: number, sale) => acc + Number(sale.total_amount || 0), 0);
    const totalItems = data.reduce((acc: number, sale) => acc + Number(sale.items_count || 0), 0);
    const totalProfit = totalGeral - Number(reportMeta?.expenses_total || 0);

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6 print:grid-cols-6">
                <SummaryCard label="Nº Vendas" value={data.length} />
                <SummaryCard label="Itens Vendidos" value={totalItems} />
                <SummaryCard label="Nº Canceladas" value={cancelled.length} />
                <SummaryCard label="Total" value={currencyFormatter(totalGeral)} />
                <SummaryCard label="Lucro (Vendas - Despesas)" value={currencyFormatter(totalProfit)} />
                <SummaryCard label="Canceladas" value={currencyFormatter(totalCanceled)} />
            </div>

            <ReportTable
                rows={data}
                emptyMessage="Nenhuma venda no período."
                columns={[
                    { header: '#', align: 'center', render: (sale) => sale.sales_number || sale.id },
                    { header: 'Cliente', render: (sale) => sale.customer?.name || 'Consumidor Final' },
                    { header: 'Data Compra', nowrap: true, render: (sale) => moment(sale.created_at).format('DD/MM/YYYY') },
                    { header: 'Status', render: (sale) => (sale.status === 'cancelled' ? 'Cancelada' : 'Completa') },
                    { header: 'Pagamento', render: (sale) => paymentMethodLabel(sale.payment_method ?? undefined) },
                    { header: 'Itens', align: 'center', render: (sale) => sale.items_count || 0 },
                    { header: 'Total', align: 'right', nowrap: true, render: (sale) => currencyFormatter(Number(sale.total_amount || 0)) },
                ]}
            />
        </div>
    );
}
