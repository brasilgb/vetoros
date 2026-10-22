import { toastError } from '@/components/app-toast-messages';
import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import { FileText, Loader2 } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

interface DateRange {
    from?: Date;
    to?: Date;
}

export default function OrdersStatistics({ dateRange, company }: { dateRange?: DateRange; company: ReportCompany | null }) {
    const [loading, setLoading] = useState(false);

    async function handleGeneratePDF() {
        if (!dateRange?.from || !dateRange?.to) return;
        setLoading(true);
        const previewWindow = window.open('', '_blank');

        if (!previewWindow) {
            setLoading(false);
            return;
        }

        previewWindow.document.title = 'Gerando relatório...';
        previewWindow.document.body.innerHTML = '<p style="font-family: Arial, sans-serif; padding: 16px;">Gerando relatório PDF...</p>';

        router.post(
            route('app.reports.store'),
            {
                type: 'orders',
                from: moment(dateRange.from).format('YYYY-MM-DD'),
                to: moment(dateRange.to).format('YYYY-MM-DD'),
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: async (page) => {
                    const reportData = readReportRows(page.props.reportData);
                    const reportMeta = readReportMeta(page.props.reportMeta);

                    // Gera o PDF no frontend
                    // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o documento.
                    const [{ pdf }, { default: OrderStatisticsPDF }] = await Promise.all([
                        import('@react-pdf/renderer'),
                        import('./pdf/OrderStatisticsPDF'),
                    ]);
                    const blob = await pdf(
                        <OrderStatisticsPDF data={reportData} reportMeta={reportMeta} dateRange={dateRange} company={company} />,
                    ).toBlob();

                    const url = URL.createObjectURL(blob);
                    previewWindow.location.href = url;
                },
                onError: (errors) => {
                    toastError('Não foi possível gerar o relatório', Object.values(errors)[0] as string);
                    previewWindow.close();
                },
                onFinish: () => setLoading(false),
            },
        );
    }

    return (
        <div className="flex w-full justify-center">
            <Button onClick={handleGeneratePDF} disabled={loading || !dateRange?.from || !dateRange?.to} className="w-full">
                {loading ? (
                    <>
                        <Loader2 className="mr-2 size-4 animate-spin" />
                        Gerando PDF...
                    </>
                ) : (
                    <>
                        <FileText className="mr-2 size-4" />
                        Ordens - Resumo Geral
                    </>
                )}
            </Button>
        </div>
    );
}
