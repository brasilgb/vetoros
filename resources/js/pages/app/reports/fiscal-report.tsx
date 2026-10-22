import ReportPreviewButton from '@/components/reports/report-preview-button';
import { ReportDateRange } from '@/lib/reports/open-report-preview';

export default function FiscalReport({ dateRange }: { dateRange?: ReportDateRange; company: ReportCompany | null }) {
    return <ReportPreviewButton type="fiscal" label="Documentos fiscais" dateRange={dateRange} />;
}
