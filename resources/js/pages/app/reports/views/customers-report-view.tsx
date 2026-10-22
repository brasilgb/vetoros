import { maskCpfCnpj, maskPhone } from '@/Utils/mask';
import moment from 'moment';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function CustomersReportView({ data }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    const customersWithEmail = data.filter((customer) => Boolean(customer.email)).length;
    const customersWithPhone = data.filter((customer) => Boolean(customer.phone)).length;
    const customersWithCpfCnpj = data.filter((customer) => Boolean(customer.cpfcnpj)).length;

    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4 print:grid-cols-4">
                <SummaryCard label="Clientes no período" value={data.length} />
                <SummaryCard label="Com e-mail" value={customersWithEmail} />
                <SummaryCard label="Com telefone" value={customersWithPhone} />
                <SummaryCard label="Com CPF/CNPJ" value={customersWithCpfCnpj} />
            </div>

            <ReportTable
                rows={data}
                emptyMessage="Nenhum cliente cadastrado no período."
                columns={[
                    { header: '#', align: 'center', render: (customer) => customer.customer_number },
                    { header: 'Cliente', render: (customer) => customer.name },
                    { header: 'E-mail', render: (customer) => customer.email || '—' },
                    { header: 'CPF/CNPJ', nowrap: true, render: (customer) => (customer.cpfcnpj ? maskCpfCnpj(customer.cpfcnpj) : '—') },
                    { header: 'Telefone', nowrap: true, render: (customer) => (customer.phone ? maskPhone(customer.phone) : '—') },
                    { header: 'Cadastro', align: 'right', nowrap: true, render: (customer) => moment(customer.created_at).format('DD/MM/YYYY') },
                ]}
            />
        </div>
    );
}
