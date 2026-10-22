import { toastError } from '@/components/app-toast-messages';
import { Button } from '@/components/ui/button';
import { Head, Link, usePage } from '@inertiajs/react';
import { ArrowLeft, Download, Loader2, Printer } from 'lucide-react';
import moment from 'moment';
import { ComponentType, useState } from 'react';
import CustomersReportView from './views/customers-report-view';
import ExpensesReportView from './views/expenses-report-view';
import FiscalReportView from './views/fiscal-report-view';
import OrdersReportView from './views/orders-report-view';
import PartsReportView from './views/parts-report-view';
import SalesReportView from './views/sales-report-view';
import SchedulesReportView from './views/schedules-report-view';

type ReportDefinition = {
    title: string;
    filename: string;
    View: ComponentType<{ data: ReportRow[]; reportMeta: ReportMeta }>;
    // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o arquivo.
    loadPdf: () => Promise<{ default: ComponentType<ReportPDFProps> }>;
};

const REPORTS: Record<string, ReportDefinition> = {
    orders: {
        title: 'Relatório de Ordens de Serviço',
        filename: 'relatorio-ordens',
        View: OrdersReportView,
        loadPdf: () => import('./pdf/OrderReportPDF'),
    },
    customers: {
        title: 'Relatório de Clientes',
        filename: 'relatorio-clientes',
        View: CustomersReportView,
        loadPdf: () => import('./pdf/CustomerReportPDF'),
    },
    schedules: {
        title: 'Relatório de Visitas',
        filename: 'relatorio-visitas',
        View: SchedulesReportView,
        loadPdf: () => import('./pdf/SchedulesReportPDF'),
    },
    sales: {
        title: 'Relatório de Vendas',
        filename: 'relatorio-vendas',
        View: SalesReportView,
        loadPdf: () => import('./pdf/SaleReportPDF'),
    },
    parts: {
        title: 'Relatório de movimento de produtos e peças',
        filename: 'relatorio-produtos-pecas',
        View: PartsReportView,
        loadPdf: () => import('./pdf/PartReportPDF'),
    },
    expenses: {
        title: 'Relatório de Despesas',
        filename: 'relatorio-despesas',
        View: ExpensesReportView,
        loadPdf: () => import('./pdf/ExpenseReportPDF'),
    },
    fiscal: {
        title: 'Relatório Fiscal',
        filename: 'relatorio-fiscal',
        View: FiscalReportView,
        loadPdf: () => import('./pdf/FiscalReportPDF'),
    },
};

type Props = {
    type: string;
    from: string;
    to: string;
    reportData: ReportRow[];
    reportMeta: ReportMeta;
    error: string | null;
};

export default function ReportView({ type, from, to, reportData, reportMeta, error }: Props) {
    const { company } = usePage<ReportsPageProps>().props;
    const [downloading, setDownloading] = useState(false);
    const report = REPORTS[type];
    const period = `${moment(from).format('DD/MM/YYYY')} - ${moment(to).format('DD/MM/YYYY')}`;
    const issuedAt = moment().format('DD/MM/YYYY HH:mm');

    async function handleDownloadPDF() {
        if (!report) return;
        setDownloading(true);

        try {
            const [{ pdf }, { default: ReportPDF }] = await Promise.all([import('@react-pdf/renderer'), report.loadPdf()]);
            const blob = await pdf(
                <ReportPDF data={reportData} reportMeta={reportMeta} dateRange={{ from: moment(from).toDate(), to: moment(to).toDate() }} company={company} />,
            ).toBlob();

            const url = URL.createObjectURL(blob);
            const link = document.createElement('a');
            link.href = url;
            link.download = `${report.filename}-${from}_${to}.pdf`;
            link.click();
            setTimeout(() => URL.revokeObjectURL(url), 1000);
        } catch {
            toastError('Não foi possível gerar o PDF', 'Tente novamente em instantes.');
        } finally {
            setDownloading(false);
        }
    }

    return (
        <>
            <Head title={report?.title ?? 'Relatório'} />
            {/* O app.css zera a margem de impressão para etiquetas; relatórios precisam de margem. */}
            <style>{'@media print { @page { size: A4; margin: 10mm; } }'}</style>

            <div className="min-h-screen bg-slate-100 text-slate-900 print:bg-white">
                <div className="mx-auto max-w-5xl p-4 md:p-8 print:max-w-none print:p-0">
                    <div className="mb-4 flex flex-wrap items-center justify-between gap-3 print:hidden">
                        <Button asChild variant="outline" className="gap-2">
                            <Link href={route('app.reports.index')}>
                                <ArrowLeft className="h-4 w-4" />
                                Voltar para relatórios
                            </Link>
                        </Button>

                        {report && !error && (
                            <div className="flex flex-wrap gap-2">
                                <Button variant="outline" onClick={() => window.print()} className="gap-2">
                                    <Printer className="h-4 w-4" />
                                    Imprimir
                                </Button>
                                <Button onClick={handleDownloadPDF} disabled={downloading} className="gap-2">
                                    {downloading ? <Loader2 className="h-4 w-4 animate-spin" /> : <Download className="h-4 w-4" />}
                                    {downloading ? 'Gerando PDF...' : 'Baixar PDF'}
                                </Button>
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6 print:rounded-none print:border-none print:p-0 print:shadow-none">
                        <header className="mb-4 flex flex-col items-center gap-1 text-center">
                            <img
                                src={company?.logo ? `/storage/logos/${company.logo}` : '/images/default.png'}
                                alt=""
                                className="mb-1 h-12 w-auto object-contain"
                            />
                            <h1 className="text-lg font-semibold uppercase">{company?.companyname}</h1>
                            <p className="text-sm text-slate-600">{report?.title ?? 'Relatório'}</p>
                            <p className="text-xs text-slate-500">
                                Período: {period} · Emitido em: {issuedAt}
                            </p>
                        </header>

                        {error ? (
                            <p className="rounded-md border border-amber-300 bg-amber-50 p-3 text-sm text-amber-900">{error}</p>
                        ) : report ? (
                            <report.View data={reportData} reportMeta={reportMeta} />
                        ) : (
                            <p className="text-sm text-slate-500">Relatório indisponível.</p>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
