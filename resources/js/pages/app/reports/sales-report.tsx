import ReportPreviewButton from '@/components/reports/report-preview-button';
import { ReportDateRange } from '@/lib/reports/open-report-preview';

export default function SalesReport({ dateRange }: { dateRange?: ReportDateRange; company: ReportCompany | null }) {
    return <ReportPreviewButton type="sales" label="Vendas de Produtos" dateRange={dateRange} />;
}
