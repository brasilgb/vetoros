import { currencyFormatter } from '@/Utils/currency-formatter';
import moment from 'moment';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function ExpensesReportView({ data }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    const totalExpenses = data.reduce((acc: number, expense) => acc + Number(expense.amount || 0), 0);
    const uniqueCategories = new Set(data.map((expense) => String(expense.category || '').trim()).filter(Boolean)).size;
    const avgExpense = data.length ? totalExpenses / data.length : 0;

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 print:grid-cols-4">
                <SummaryCard label="Nº Despesas" value={data.length} />
                <SummaryCard label="Categorias" value={uniqueCategories} />
                <SummaryCard label="Média" value={currencyFormatter(avgExpense)} />
                <SummaryCard label="Total" value={currencyFormatter(totalExpenses)} />
            </div>

            <ReportTable
                rows={data}
                emptyMessage="Nenhuma despesa no período."
                columns={[
                    { header: '#', align: 'center', render: (expense) => expense.expense_number || expense.id },
                    { header: 'Data', nowrap: true, render: (expense) => moment(expense.expense_date).format('DD/MM/YYYY') },
                    { header: 'Descrição', render: (expense) => expense.description || '-' },
                    { header: 'Categoria', render: (expense) => expense.category || '-' },
                    { header: 'Lançado por', render: (expense) => expense.created_by?.name || '-' },
                    { header: 'Valor', align: 'right', nowrap: true, render: (expense) => currencyFormatter(expense.amount || 0) },
                ]}
            />
        </div>
    );
}
