import ActionCancelSale from '@/components/action-cancel-sale';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { toastError } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import SaleInvoiceModal from '@/components/Modals/SaleInvoiceModal';
import SaleReceiptPDF from '@/components/SaleReceiptPDF';
import { SalesProducts } from '@/components/sales-products';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link, usePage } from '@inertiajs/react';
import { pdf } from '@react-pdf/renderer';
import { EyeIcon, FileText, Loader2, PrinterIcon, ShoppingCartIcon } from 'lucide-react';
import moment from 'moment';
import { useEffect, useRef, useState } from 'react';
import { useReactToPrint } from 'react-to-print';
import SaleDetailsModal from './app-sale-details-modal';
import Receipt from './receipt';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Vendas',
        href: '#',
    },
];

export default function Sales({ sales, parts, search, financial_status, financial_counts }: any) {
    const { auth, fiscalSetting } = usePage().props as any;
    const companyData = auth?.user?.tenant;
    const acessDenied = auth?.user?.roles === 9 || auth?.user?.roles === 1 ? true : false;
    const canIssueProductInvoice =
        Boolean(fiscalSetting?.enabled) &&
        (Boolean(fiscalSetting?.nfe_enabled) || Boolean(fiscalSetting?.native?.nfce)) &&
        auth?.permissions?.includes('fiscal_documents');

    const [isModalOpen, setIsModalOpen] = useState(false);
    const [selectedSale, setSelectedSale] = useState(null);
    const [invoiceModalOpen, setInvoiceModalOpen] = useState(false);
    const [saleForInvoice, setSaleForInvoice] = useState<any>(null);
    const [saleToPrint, setSaleToPrint] = useState<any>(null);
    const receiptRef = useRef<HTMLDivElement>(null);
    const [generatingPdfSaleId, setGeneratingPdfSaleId] = useState<number | null>(null);
    const [printingThermalSaleId, setPrintingThermalSaleId] = useState<number | null>(null);

    const handlePrint = useReactToPrint({
        contentRef: receiptRef,
        onAfterPrint: () => {
            setSaleToPrint(null);
            setPrintingThermalSaleId(null);
        },
    });

    useEffect(() => {
        if (saleToPrint) {
            handlePrint();
        }
    }, [saleToPrint]);

    const handleViewDetails = (sale: any) => {
        setSelectedSale(sale);
        setIsModalOpen(true);
    };

    const handleOpenInvoiceModal = (sale: any) => {
        const mappedSale = {
            customer: sale.customer
                ? [
                      {
                          name: sale.customer?.name,
                          cpfcnpj: sale.customer?.cpfcnpj,
                      },
                  ]
                : [],
            items: (sale.items ?? []).map((item: any) => ({
                name: item.part?.name || item.name || 'Produto',
                selected_quantity: item.quantity,
                sale_price: item.unit_price,
            })),
            total: sale.total_amount,
            fiscal_document_number: sale.fiscal_document_number,
            fiscal_document_url: sale.fiscal_document_url,
            fiscal_issued_at: sale.fiscal_issued_at,
            fiscal_notes: sale.fiscal_notes,
            numberSale: {
                id: sale.id,
            },
        };

        setSaleForInvoice(mappedSale);
        setInvoiceModalOpen(true);
    };

    const handlePrintReceipt = (sale: any) => {
        setPrintingThermalSaleId(sale.id);
        const mappedSale = {
            ...sale,
            items: sale.items.map((item: any) => ({
                name: item.part?.name || item.name || 'Produto',
                selected_quantity: item.quantity,
                sale_price: item.unit_price,
            })),
        };
        setSaleToPrint(mappedSale);
    };

    const handleGeneratePDF = async (sale: any) => {
        if (!sale) return;

        setGeneratingPdfSaleId(sale.id);
        const previewWindow = window.open('', '_blank');

        if (!previewWindow) {
            setGeneratingPdfSaleId(null);
            return;
        }

        previewWindow.document.title = 'Gerando recibo...';
        previewWindow.document.body.innerHTML = '<p style="font-family: Arial, sans-serif; padding: 16px;">Gerando recibo PDF...</p>';

        try {
            const customerNameForPDF = sale.customer?.name || 'Consumidor Final';

            const mappedItems = sale.items.map((item: any) => ({
                name: item.part?.name || item.name || 'Produto',
                selected_quantity: item.quantity,
                sale_price: item.unit_price,
            }));

            // Gera o blob do PDF
            const blob = await pdf(
                <SaleReceiptPDF items={mappedItems} total={sale.total_amount} customerName={customerNameForPDF} sale={sale} company={companyData} />,
            ).toBlob();

            const url = URL.createObjectURL(blob);
            previewWindow.location.href = url;
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (error) {
            previewWindow.close();
            console.error('Erro ao gerar PDF:', error);
            toastError('Erro ao gerar o PDF.');
        } finally {
            setGeneratingPdfSaleId(null);
        }
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <SaleDetailsModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} sale={selectedSale} />
            <SaleInvoiceModal open={invoiceModalOpen} onClose={() => setInvoiceModalOpen(false)} sale={saleForInvoice ?? {}} />
            <div style={{ display: 'none' }}>
                <Receipt
                    ref={receiptRef}
                    paper="80mm"
                    items={saleToPrint?.items || []}
                    total={new Intl.NumberFormat('pt-BR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(
                        saleToPrint?.total_amount || 0,
                    )}
                    customer={saleToPrint?.customer?.name || 'Consumidor'}
                    sale={saleToPrint}
                />
            </div>
            <Head title="Vendas" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={ShoppingCartIcon} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Vendas</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Buscar vendas por número e cliente" url="app.sales.index" />
                </div>
                <div className="flex w-full flex-col gap-2 sm:flex-row sm:flex-wrap lg:w-auto lg:shrink-0 lg:justify-end">
                    <SalesProducts
                        parts={parts ?? []}
                        iconSize={18}
                        triggerLabel="Abrir PDV"
                        triggerClassName="border-input bg-background hover:bg-accent hover:text-accent-foreground inline-flex h-10 w-full items-center justify-center gap-2 rounded-md border px-4 py-2 text-sm font-medium whitespace-nowrap transition-colors sm:w-auto"
                    />
                    <Button variant={financial_status === 'paid' ? 'default' : 'outline'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.sales.index', { search, financial_status: 'paid' })}>Pago ({financial_counts?.paid ?? 0})</Link>
                    </Button>
                    <Button variant={financial_status === 'partial' ? 'default' : 'outline'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.sales.index', { search, financial_status: 'partial' })}>
                            Parcial ({financial_counts?.partial ?? 0})
                        </Link>
                    </Button>
                    <Button variant={financial_status === 'pending' ? 'default' : 'outline'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.sales.index', { search, financial_status: 'pending' })}>
                            Pendente ({financial_counts?.pending ?? 0})
                        </Link>
                    </Button>
                    <Button variant={financial_status === 'cancelled' ? 'default' : 'outline'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.sales.index', { search, financial_status: 'cancelled' })}>
                            Cancelada ({financial_counts?.cancelled ?? 0})
                        </Link>
                    </Button>
                    {financial_status && (
                        <Button variant="ghost" asChild className="w-full whitespace-nowrap sm:w-auto">
                            <Link href={route('app.sales.index', { search })}>Limpar filtro</Link>
                        </Button>
                    )}
                </div>
            </div>

            <div className="p-4">
                <PaginationSummary data={sales} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Cliente</TableHead>
                                <TableHead>Valores</TableHead>
                                <TableHead>Pagamento</TableHead>
                                <TableHead>Financeiro</TableHead>
                                <TableHead>Data venda</TableHead>
                                <TableHead className="min-w-[360px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {sales?.data.length > 0 ? (
                                sales?.data?.map((sale: any) => {
                                    const isGeneratingCurrentPdf = generatingPdfSaleId === sale.id;
                                    const isPrintingCurrentThermal = printingThermalSaleId === sale.id;

                                    return (
                                        <TableRow key={sale.id}>
                                            <TableCell>{sale.sales_number}</TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div className="font-medium">{sale.customer?.name || 'Cliente não informado'}</div>
                                                    <div className="text-muted-foreground text-xs">{sale.items?.length ?? 0} item(ns)</div>
                                                </div>
                                            </TableCell>
                                            <TableCell>
                                                <div className="space-y-1">
                                                    <div>
                                                        {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
                                                            sale.total_amount,
                                                        )}
                                                    </div>
                                                    {sale.remaining_amount !== undefined && (
                                                        <div className="text-muted-foreground text-xs">
                                                            Saldo:{' '}
                                                            {new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(
                                                                Number(sale.remaining_amount || 0),
                                                            )}
                                                        </div>
                                                    )}
                                                </div>
                                            </TableCell>
                                            <TableCell className="capitalize">{sale.payment_method || 'não informado'}</TableCell>
                                            <TableCell>
                                                {sale.financial_status === 'paid' && <Badge>Pago</Badge>}
                                                {sale.financial_status === 'partial' && <Badge variant="secondary">Parcial</Badge>}
                                                {sale.financial_status === 'pending' && <Badge variant="destructive">Pendente</Badge>}
                                                {sale.financial_status === 'cancelled' && <Badge variant="outline">Cancelada</Badge>}
                                            </TableCell>
                                            <TableCell>{moment(sale.created_at).format('DD/MM/YYYY')}</TableCell>
                                            <TableCell className="min-w-[360px]">
                                                <div className="flex flex-wrap justify-end gap-2">
                                                    {sale.status === 'completed' && <ActionCancelSale saleId={sale.id} disabled={acessDenied} />}

                                                    {sale.status === 'cancelled' && <Badge variant="destructive">Cancelada</Badge>}

                                                    <Button
                                                        type="button"
                                                        onClick={() => handleGeneratePDF(sale)}
                                                        disabled={isGeneratingCurrentPdf || isPrintingCurrentThermal}
                                                        className="bg-blue-600 text-white hover:bg-blue-700"
                                                    >
                                                        {isGeneratingCurrentPdf ? (
                                                            <Loader2 className="size-4 animate-spin" />
                                                        ) : (
                                                            <FileText className="size-4" />
                                                        )}
                                                        Recibo PDF
                                                    </Button>

                                                    {canIssueProductInvoice && (
                                                        <Button
                                                            type="button"
                                                            onClick={() => handleOpenInvoiceModal(sale)}
                                                            className="rounded-lg py-2 text-sm font-medium"
                                                            disabled={isGeneratingCurrentPdf || isPrintingCurrentThermal}
                                                        >
                                                            <FileText className="size-4" />
                                                            NF-e
                                                        </Button>
                                                    )}

                                                    <Button
                                                        onClick={() => handlePrintReceipt(sale)}
                                                        size="icon"
                                                        className="bg-blue-600 text-white hover:bg-blue-500"
                                                        title="Imprimir recibo"
                                                        aria-label={`Imprimir recibo da venda ${sale.sales_number}`}
                                                        disabled={isGeneratingCurrentPdf || isPrintingCurrentThermal}
                                                    >
                                                        {isPrintingCurrentThermal ? (
                                                            <Loader2 className="h-4 w-4 animate-spin" />
                                                        ) : (
                                                            <PrinterIcon className="h-4 w-4" />
                                                        )}
                                                    </Button>

                                                    <Button
                                                        onClick={() => handleViewDetails(sale)}
                                                        size="icon"
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        title="Ver detalhes"
                                                        aria-label={`Ver detalhes da venda ${sale.sales_number}`}
                                                        disabled={isGeneratingCurrentPdf || isPrintingCurrentThermal}
                                                    >
                                                        <EyeIcon className="h-4 w-4" />
                                                    </Button>
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={7} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={7}>
                                    <AppPagination data={sales} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
