import { fiscalProviderLabel, fiscalTargetLabel } from '@/lib/reports/report-labels';
import moment from 'moment';
import ReportTable from './report-table';
import SummaryCard from './summary-card';

export default function FiscalReportView({ data, reportMeta }: { data: ReportRow[]; reportMeta: ReportMeta }) {
    return (
        <div className="space-y-4">
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-6 print:grid-cols-6">
                <SummaryCard label="Documentos" value={reportMeta?.documents_count || 0} />
                <SummaryCard label="NF-e" value={reportMeta?.nfe_count || 0} />
                <SummaryCard label="NFS-e" value={reportMeta?.nfse_count || 0} />
                <SummaryCard label="Manual" value={reportMeta?.manual_count || 0} />
                <SummaryCard label="Integração anterior" value={reportMeta?.integration_count || 0} />
                <SummaryCard label="Erros" value={reportMeta?.error_count || 0} />
            </div>

            <ReportTable
                rows={data}
                emptyMessage="Nenhum documento fiscal no período."
                columns={[
                    { header: 'Tipo', render: (document) => String(document.type || '').toUpperCase() },
                    { header: 'Origem', render: (document) => fiscalProviderLabel(document.provider ?? '') },
                    { header: 'Vínculo', render: (document) => fiscalTargetLabel(document) },
                    { header: 'Número', render: (document) => document.number || '-' },
                    { header: 'Status', render: (document) => document.status || '-' },
                    { header: 'Emissão', nowrap: true, render: (document) => (document.issued_at ? moment(document.issued_at).format('DD/MM/YYYY') : '-') },
                    { header: 'Registrado por', render: (document) => document.registered_by?.name || '-' },
                ]}
            />
        </div>
    );
}
