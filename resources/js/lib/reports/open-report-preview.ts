import moment from 'moment';

export interface ReportDateRange {
    from?: Date;
    to?: Date;
}

// Abre a visualização em HTML (rápida) numa aba nova; o PDF fica como download opcional nessa tela.
export function openReportPreview(type: string, dateRange?: ReportDateRange) {
    if (!dateRange?.from || !dateRange?.to) return;

    window.open(
        route('app.reports.preview', {
            type,
            from: moment(dateRange.from).format('YYYY-MM-DD'),
            to: moment(dateRange.to).format('YYYY-MM-DD'),
        }),
        '_blank',
    );
}
