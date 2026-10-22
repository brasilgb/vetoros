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

type Company = {
    shortname?: string | null;
    logo?: string | null;
    companyname?: string | null;
    cnpj?: string | null;
};

export default function OrdersDaily({ dateRange, company }: { dateRange?: DateRange; company: Company | null }) {
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
                    const reportData = readOrderDailyReportRows(page.props.reportData);
                    // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o documento.
                    const [{ pdf }, { default: OrderDailyReportPDF }] = await Promise.all([
                        import('@react-pdf/renderer'),
                        import('./pdf/OrderDailyReportPDF'),
                    ]);
                    const pdfInstance = pdf();

                    await new Promise<void>((resolve) => {
                        pdfInstance.updateContainer(
                            <OrderDailyReportPDF
                                data={reportData}
                                dateRange={{
                                    from: moment(dateRange.from).format('YYYY-MM-DD'),
                                    to: moment(dateRange.to).format('YYYY-MM-DD'),
                                }}
                                company={company}
                            />,
                            resolve,
                        );
                    });

                    const blob = await pdfInstance.toBlob();

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
                        Ordens - Resumo Diário
                    </>
                )}
            </Button>
        </div>
    );
}
