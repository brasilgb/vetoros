import ReportPreviewButton from '@/components/reports/report-preview-button';
import { ReportDateRange } from '@/lib/reports/open-report-preview';

export default function CustomersReport({ dateRange }: { dateRange?: ReportDateRange; company: ReportCompany | null }) {
    return <ReportPreviewButton type="customers" label="Clientes cadastrados" dateRange={dateRange} />;
}
