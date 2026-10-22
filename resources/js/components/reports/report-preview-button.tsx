import { Button } from '@/components/ui/button';
import { openReportPreview, ReportDateRange } from '@/lib/reports/open-report-preview';
import { FileText } from 'lucide-react';

export default function ReportPreviewButton({ type, label, dateRange }: { type: string; label: string; dateRange?: ReportDateRange }) {
    return (
        <div className="flex w-full justify-center">
            <Button onClick={() => openReportPreview(type, dateRange)} disabled={!dateRange?.from || !dateRange?.to} className="w-full">
                <FileText className="mr-2 size-4" />
                {label}
            </Button>
        </div>
    );
}
