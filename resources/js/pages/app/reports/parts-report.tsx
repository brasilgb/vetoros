import ReportPreviewButton from '@/components/reports/report-preview-button';
import { ReportDateRange } from '@/lib/reports/open-report-preview';

export default function PartsReport({ dateRange }: { dateRange?: ReportDateRange; company: ReportCompany | null }) {
    return <ReportPreviewButton type="parts" label="Produtos e Peças" dateRange={dateRange} />;
}
