import { toastError } from '@/components/app-toast-messages';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Loader2, Printer } from 'lucide-react';
import { ReactNode, useState } from 'react';

export default function ModalReceipt({
    orderid,
    open,
    onOpenChange,
    hideTrigger = false,
    trigger,
}: {
    orderid: number;
    open?: boolean;
    onOpenChange?: (open: boolean) => void;
    hideTrigger?: boolean;
    trigger?: ReactNode;
}) {
    const [loadingType, setLoadingType] = useState<string | null>(null);

    const handlePrintReceipt = async (e: any, type: string) => {
        e.preventDefault();
        setLoadingType(type);

        // Abre a aba em branco de forma síncrona, dentro do próprio clique do
        // usuário: navegadores só liberam window.open sem bloqueio de pop-up
        // quando ele é chamado diretamente por um gesto do usuário, antes de
        // qualquer await.
        const previewWindow = window.open('', '_blank');

        if (!previewWindow) {
            setLoadingType(null);
            toastError('Não foi possível abrir o recibo. Verifique se o bloqueador de pop-ups do navegador está ativo.');
            return;
        }

        previewWindow.document.title = 'Gerando recibo...';
        previewWindow.document.body.innerHTML = '<p style="font-family: Arial, sans-serif; padding: 16px;">Gerando recibo PDF...</p>';

        try {
            const response = await fetch(route('app.receipts.printing.data', { or: orderid, tp: type }), {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                throw new Error(`Falha ao carregar dados do recibo (status ${response.status})`);
            }

            const { order, company, receipt, checklist } = await response.json();

            // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o documento.
            const [{ pdf }, { default: OrderReceiptPDF }] = await Promise.all([import('@react-pdf/renderer'), import('./pdf/OrderReceiptPDF')]);
            const blob = await pdf(<OrderReceiptPDF order={order} company={company} type={type} receipt={receipt} checklist={checklist} />).toBlob();

            const url = URL.createObjectURL(blob);
            previewWindow.location.href = url;
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (error) {
            previewWindow.close();
            console.error('Erro ao gerar recibo em PDF:', error);
            toastError('Erro ao gerar recibo em PDF.');
        } finally {
            setLoadingType(null);
        }
    };

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            {!hideTrigger && (
                <DialogTrigger asChild>
                    {trigger ?? (
                        <Button variant="default" size="icon" className="bg-sky-700 text-white hover:bg-sky-700">
                            <Printer className="h-4 w-4" />
                        </Button>
                    )}
                </DialogTrigger>
            )}
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Selecione o Recibo Desejado</DialogTitle>
                </DialogHeader>
                <div className="grid gap-4 py-4">
                    <div className="flex flex-col gap-3">
                        <Button variant="default" onClick={(e) => handlePrintReceipt(e, 'oraberta')} disabled={loadingType !== null}>
                            {loadingType === 'oraberta' ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                            Entrada de equipamento
                        </Button>
                        <Button variant="default" onClick={(e) => handlePrintReceipt(e, 'orentrega')} disabled={loadingType !== null}>
                            {loadingType === 'orentrega' ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                            Entrega de equipamento
                        </Button>
                        <Button variant="default" onClick={(e) => handlePrintReceipt(e, 'ororcamento')} disabled={loadingType !== null}>
                            {loadingType === 'ororcamento' ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                            Orçamento de equipamento
                        </Button>
                        <Button variant="default" onClick={(e) => handlePrintReceipt(e, 'orchecklist')} disabled={loadingType !== null}>
                            {loadingType === 'orchecklist' ? <Loader2 className="h-4 w-4 animate-spin" /> : null}
                            Checklist de equipamento
                        </Button>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}
