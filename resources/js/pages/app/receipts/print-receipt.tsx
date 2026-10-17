import { toastError } from '@/components/app-toast-messages';
import { Button } from '@/components/ui/button';
import { Toaster } from '@/components/ui/sonner';
import { formatBRL } from '@/lib/money';
import { Link } from '@inertiajs/react';
import { ArrowLeft, Printer, Square } from 'lucide-react';
import { useEffect, useState } from 'react';

const normalizePlaceholderKey = (key: string) =>
    key
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-/g, '_');

function receiptTextByType(type: string, receipt: any) {
    if (type === 'oraberta') return receipt?.receivingequipment ?? '';
    if (type === 'orentrega') return receipt?.equipmentdelivery ?? '';
    if (type === 'ororcamento') return receipt?.budgetissuance ?? '';
    if (type === 'orchecklist') return 'checklist';

    return '';
}

function titleByType(type: string) {
    if (type === 'oraberta') return 'Recibo de Entrada de Equipamento';
    if (type === 'orentrega') return 'Recibo de Entrega de Equipamento';
    if (type === 'ororcamento') return 'Orçamento ao Cliente';
    if (type === 'orchecklist') return 'Checklist para Conferência do Equipamento';

    return 'Recibo';
}

function renderReceiptTemplate(template: string, order: any) {
    const values = {
        cliente: order?.customer?.name ?? '',
        cpf_cnpj: order?.customer?.cpfcnpj ?? '',
        defeito: order?.defect ?? '',
        equipamento: order?.equipment?.equipment ?? '',
        modelo: order?.model ?? '',
        ordem: String(order?.order_number ?? ''),
        prazo: order?.delivery_forecast ?? '',
        valor_orcamento: formatBRL(order?.budget_value),
    };

    return template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, key) => {
        const normalizedKey = normalizePlaceholderKey(String(key));

        if (normalizedKey in values) {
            return values[normalizedKey as keyof typeof values];
        }

        return '';
    });
}

function ReceiptCopy({
    order,
    company,
    type,
    receipt,
    checklist,
    qrcode,
}: {
    order: any;
    company: any;
    type: string;
    receipt: any;
    checklist: any;
    qrcode: boolean;
}) {
    const checklistItems = checklist?.checklist
        ? String(checklist.checklist)
              .split(',')
              .map((item) => item.trim())
              .filter(Boolean)
        : [];
    const orderParts = order?.order_parts ?? order?.orderParts ?? [];
    const partQuantity = (part: any) => Number(part?.pivot?.quantity ?? part?.quantity ?? 1) || 1;
    const partTotal = (part: any) => Number(part?.sale_price ?? 0) * partQuantity(part);
    const partsValue = orderParts.length > 0 ? orderParts.reduce((total: number, part: any) => total + partTotal(part), 0) : order?.parts_value;
    const receiptText = renderReceiptTemplate(receiptTextByType(type, receipt), order);
    const title = titleByType(type);

    const option = {
        year: 'numeric',
        month: 'long',
        day: 'numeric',
    } as const;
    const locale = 'pt-BR';

    return (
        <div className="flex min-h-[50vh] flex-col border-b-2 border-dashed border-gray-300 bg-white p-6 text-[11px] text-gray-800 print:border-black">
            <div className="mb-4 flex items-start justify-between gap-4 border-b border-gray-300 pb-3">
                <div className="flex items-start gap-3">
                    <div className="flex h-14 w-14 shrink-0 items-center justify-center rounded-md border border-gray-200 bg-white p-1">
                        <img
                            src={`${company?.logo ? `/storage/logos/${company.logo}` : '/images/default.png'}`}
                            alt={company?.companyname ? `Logo ${company.companyname}` : 'Logo da empresa'}
                            className="h-full w-full object-contain"
                            onError={(event) => {
                                event.currentTarget.src = '/images/default.png';
                            }}
                        />
                    </div>
                    <div className="space-y-0.5">
                        <h1 className="text-sm font-bold text-gray-950">{company?.companyname ?? '-'}</h1>
                        <p>CNPJ: {company?.cnpj ?? '-'}</p>
                        <p>
                            {company?.street ?? '-'}, {company?.number ?? '-'} - {company?.district ?? '-'}
                        </p>
                        <p>
                            {company?.city ?? '-'} {company?.state ? `- ${company.state}` : ''} | {company?.telephone ?? '-'}
                        </p>
                    </div>
                </div>
                <div className="min-w-36 rounded-md border border-gray-300 p-2 text-right">
                    <p className="text-[9px] font-semibold tracking-wide text-gray-500 uppercase">Ordem de Serviço</p>
                    <p className="text-base font-bold text-gray-950">#{order?.order_number ?? '-'}</p>
                    <p className="text-[10px] text-gray-500">{new Date().toLocaleDateString(locale)}</p>
                </div>
            </div>

            <div className="mb-3 rounded-md border border-gray-300">
                <div className="border-b border-gray-300 bg-gray-100 px-3 py-1.5 text-center text-[11px] font-bold tracking-wide text-gray-950 uppercase">
                    {title}
                </div>
                <div className="grid gap-x-6 gap-y-1 p-3 md:grid-cols-2">
                    <div className="space-y-1">
                        <p>
                            <span className="font-semibold">Cliente:</span> {order?.customer?.name ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">CPF/CNPJ:</span> {order?.customer?.cpfcnpj ?? '-'}
                        </p>
                    </div>
                    <div className="space-y-1">
                        <p>
                            <span className="font-semibold">Telefone:</span> {order?.customer?.phone ?? '-'}
                        </p>
                        <p>
                            <span className="font-semibold">Endereço:</span> {order?.customer?.street ?? '-'}, {order?.customer?.number ?? '-'} -{' '}
                            {order?.customer?.district ?? '-'}
                        </p>
                    </div>
                </div>
            </div>

            <div className="mb-3 rounded-md border border-gray-300">
                <h2 className="border-b border-gray-300 bg-gray-50 px-3 py-1.5 text-[11px] font-bold text-gray-950">Informações do Equipamento</h2>
                <div className="grid gap-x-4 gap-y-1 p-3 md:grid-cols-3">
                    <p>
                        <span className="font-semibold">Equipamento:</span> {order?.equipment?.equipment ?? '-'}
                    </p>
                    <p>
                        <span className="font-semibold">Modelo:</span> {order?.model ?? '-'}
                    </p>
                    <p>
                        <span className="font-semibold">Defeito:</span> {order?.defect ?? '-'}
                    </p>
                    <p className="md:col-span-2">
                        <span className="font-semibold">Pré-orçamento:</span> {order?.budget_description ?? '-'}
                    </p>
                    <p>
                        <span className="font-semibold">Valor:</span> {formatBRL(order?.budget_value)}
                    </p>
                </div>
            </div>

            <div className="mb-3 rounded-md border border-gray-300">
                <h2 className="border-b border-gray-300 bg-gray-50 px-3 py-1.5 text-[11px] font-bold text-gray-950">Garantias e Observações</h2>
                <p className="min-h-10 p-3 text-[10px] leading-snug whitespace-pre-wrap">{receiptText || '-'}</p>
            </div>

            {type === 'orchecklist' && (
                <div className="mb-3 rounded-md border border-gray-300">
                    <h2 className="border-b border-gray-300 bg-gray-50 px-3 py-1.5 text-[11px] font-bold text-gray-950">Checklist de Conferência</h2>
                    <div className="p-3">
                        <ul className="grid gap-x-6 gap-y-2 md:grid-cols-2">
                            {(checklistItems.length > 0 ? checklistItems : ['Sem itens cadastrados']).map((list: string) => (
                                <li key={list} className="flex items-center gap-2">
                                    <Square className="h-3.5 w-3.5 shrink-0" /> <span>{list}</span>
                                </li>
                            ))}
                        </ul>
                    </div>
                </div>
            )}

            {type === 'ororcamento' && (
                <div className="mb-3 rounded-md border border-gray-300">
                    <h2 className="border-b border-gray-300 bg-gray-50 px-3 py-1.5 text-[11px] font-bold text-gray-950">Orçamento Gerado</h2>
                    <div className="p-3">
                        <p className="mb-2 whitespace-pre-wrap">{order?.budget_description ?? '-'}</p>
                        <p className="border-t border-gray-300 pt-2 text-right font-bold">Valor: {formatBRL(order?.budget_value)}</p>
                    </div>
                </div>
            )}

            {type === 'orentrega' && (
                <div className="mb-3 rounded-md border border-gray-300">
                    <h2 className="border-b border-gray-300 bg-gray-50 px-3 py-1.5 text-[11px] font-bold text-gray-950">Serviço Prestado</h2>
                    <div className="p-3">
                        <p className="mb-2 whitespace-pre-wrap">{order?.services_performed ?? '-'}</p>
                        {orderParts.length > 0 ? (
                            <p className="mb-2">
                                <span className="font-bold">Peças adicionadas: </span>
                                {orderParts
                                    .map((part: any) => {
                                        const quantity = partQuantity(part);
                                        return quantity > 1 ? `${quantity}x ${part.name}` : part.name;
                                    })
                                    .join(', ')}
                            </p>
                        ) : (
                            <p className="mb-2">
                                <span className="font-bold">Peças adicionadas: </span>
                                {order?.parts ?? '-'}
                            </p>
                        )}
                        <div className="grid grid-cols-3 border-t border-gray-300 pt-2 text-right">
                            <div>
                                <span className="block text-[10px] text-gray-500">Peças</span>
                                <span className="font-semibold">{formatBRL(partsValue)}</span>
                            </div>
                            <div>
                                <span className="block text-[10px] text-gray-500">Serviço</span>
                                <span className="font-semibold">{formatBRL(order?.service_value)}</span>
                            </div>
                            <div>
                                <span className="block text-[10px] text-gray-500">Total</span>
                                <span className="font-bold">{formatBRL(order?.service_cost)}</span>
                            </div>
                        </div>
                    </div>
                </div>
            )}

            <div className="mt-auto w-full">
                {type === 'oraberta' && (
                    <div className="mb-4 rounded-md border border-gray-300 px-3 py-2 text-[10px]">
                        Acompanhe o status da ordem de serviço em{' '}
                        <span className="font-semibold">https://vetoros.com.br/os/{order?.tracking_token}</span>
                    </div>
                )}
                <div className="grid grid-cols-2 items-end gap-8 pt-5">
                    <div className="text-center">
                        {company?.city}, {new Date().toLocaleDateString(locale, option)}
                    </div>
                    <div className="text-center">
                        {type === 'oraberta' && order?.customer_signature_url ? (
                            <img src={order.customer_signature_url} alt="Assinatura do cliente" className="mx-auto h-14 object-contain" />
                        ) : (
                            <div className="mb-1 border-t border-black"></div>
                        )}
                        <p>Assinatura do Cliente</p>
                    </div>
                </div>
            </div>
        </div>
    );
}

export default function Receipt({
    order,
    company,
    type,
    receipt,
    checklist,
    backUrl,
    copies = 2,
}: {
    order: any;
    company: any;
    type: any;
    receipt: any;
    checklist: any;
    backUrl?: string;
    copies?: number;
}) {
    const [openAsPdf] = useState(() => {
        if (typeof window === 'undefined') return false;

        const query = new URLSearchParams(window.location.search);
        return query.get('pdf') === '1';
    });
    const [isGeneratingPdf, setIsGeneratingPdf] = useState(false);

    useEffect(() => {
        if (!openAsPdf) return;

        let isMounted = true;
        const generatePdf = async () => {
            setIsGeneratingPdf(true);
            try {
                // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o documento.
                const [{ pdf }, { default: OrderReceiptPDF }] = await Promise.all([import('@react-pdf/renderer'), import('./pdf/OrderReceiptPDF')]);
                const blob = await pdf(
                    <OrderReceiptPDF order={order} company={company} type={type} receipt={receipt} checklist={checklist} />,
                ).toBlob();
                if (!isMounted) return;

                const url = URL.createObjectURL(blob);
                window.location.href = url;
                setTimeout(() => URL.revokeObjectURL(url), 60_000);
            } catch (error) {
                console.error('Erro ao gerar recibo em PDF:', error);
                toastError('Erro ao gerar recibo em PDF.');
            } finally {
                if (isMounted) setIsGeneratingPdf(false);
            }
        };

        generatePdf();
        return () => {
            isMounted = false;
        };
    }, [openAsPdf, order, company, type, receipt, checklist]);

    if (openAsPdf) {
        return (
            <div className="mx-auto flex min-h-[60vh] max-w-4xl items-center justify-center p-6">
                <Toaster />
                <p className="text-muted-foreground text-sm">{isGeneratingPdf ? 'Gerando recibo PDF...' : 'Abrindo recibo PDF...'}</p>
            </div>
        );
    }

    const handlePrint = () => {
        window.print();
    };
    return (
        <div className="relative mx-auto max-w-4xl">
            {/* Botão de Impressão - oculto na impressão */}
            <div className="absolute mb-4 flex w-full items-center justify-between print:hidden">
                <Button asChild className="gap-2">
                    <Link href={backUrl ?? route('app.orders.index')}>
                        <ArrowLeft className="h-4 w-4" />
                    </Link>
                </Button>
                <Button onClick={handlePrint} className="gap-2">
                    <Printer className="h-4 w-4" />
                </Button>
            </div>

            {/* Recibo para Impressão */}
            <div className="bg-white shadow-lg print:h-screen print:shadow-none">
                {/* Primeira Via */}
                <ReceiptCopy order={order} company={company} type={type} receipt={receipt} checklist={checklist} qrcode={false} />

                {/* Segunda Via */}
                {copies > 1 && <ReceiptCopy order={order} company={company} type={type} receipt={receipt} checklist={checklist} qrcode={false} />}
            </div>
        </div>
    );
}
