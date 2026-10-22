import ReportPreviewButton from '@/components/reports/report-preview-button';
import { ReportDateRange } from '@/lib/reports/open-report-preview';

export default function SchedulesReport({ dateRange }: { dateRange?: ReportDateRange; company: ReportCompany | null }) {
    return <ReportPreviewButton type="schedules" label="Agendamentos de visitas" dateRange={dateRange} />;
}
