import { partTypeLabel } from '@/lib/reports/report-labels';
import { currencyFormatter } from '@/Utils/currency-formatter';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function PartsReportView({ data }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    const stockTotal = data.reduce((acc: number, part) => acc + Number(part.quantity || 0), 0);
    const lowStock = data.filter((part) => Number(part.quantity || 0) <= Number(part.minimum_stock_level || 0)).length;
    const stockCost = data.reduce((acc: number, part) => acc + Number(part.quantity || 0) * Number(part.cost_price || 0), 0);
    const stockSale = data.reduce((acc: number, part) => acc + Number(part.quantity || 0) * Number(part.sale_price || 0), 0);

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-5 print:grid-cols-5">
                <SummaryCard label="Itens" value={data.length} />
                <SummaryCard label="Quantidade em estoque" value={stockTotal} />
                <SummaryCard label="Abaixo do mínimo" value={lowStock} />
                <SummaryCard label="Estoque a custo" value={currencyFormatter(stockCost)} />
                <SummaryCard label="Estoque a venda" value={currencyFormatter(stockSale)} />
            </div>

            <ReportTable
                rows={data}
                emptyMessage="Nenhum produto ou peça cadastrado no período."
                columns={[
                    { header: 'Tipo', render: (part) => partTypeLabel(part.type) },
                    { header: 'Referência', render: (part) => part.reference_number || '-' },
                    { header: 'Nome', render: (part) => part.name || '-' },
                    { header: 'Fabricante', render: (part) => part.manufacturer || '-' },
                    { header: 'Qtd.', align: 'right', render: (part) => Number(part.quantity || 0) },
                    { header: 'Mín.', align: 'right', render: (part) => Number(part.minimum_stock_level || 0) },
                    { header: 'Custo', align: 'right', nowrap: true, render: (part) => currencyFormatter(part.cost_price ?? 0) },
                    { header: 'Venda', align: 'right', nowrap: true, render: (part) => currencyFormatter(part.sale_price ?? 0) },
                ]}
            />
        </div>
    );
}
