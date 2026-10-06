import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';

import { maskCpfCnpj, maskMoney } from '@/Utils/mask';
import { router, useForm, usePage } from '@inertiajs/react';
import { useState } from 'react';

interface SaleInvoiceModalProps {
    open: boolean;
    onClose: () => void;
    sale: {
        customer?: Array<{
            name?: string;
            cpfcnpj?: string;
        }>;
        items?: Array<{
            name?: string;
            selected_quantity?: number;
            sale_price?: string | number;
        }>;
        total?: string | number;
        fiscal_document_number?: string;
        fiscal_document_url?: string;
        fiscal_issued_at?: string;
        fiscal_notes?: string;
        numberSale: {
            id?: string | number;
        };
    };
}

export default function SaleInvoiceModal({ open, onClose, sale }: SaleInvoiceModalProps) {
    const { fiscalSetting } = usePage().props as any;
    const saleId = sale?.numberSale?.id;
    const totalSale = Number(sale?.total ?? 0);
    const canIssueInvoice = totalSale > 0;
    const hasRegisteredFiscal = Boolean(sale?.fiscal_document_number || sale?.fiscal_document_url);
    const nativeNfe = Boolean(fiscalSetting?.native?.nfe);
    const nativeNfce = Boolean(fiscalSetting?.native?.nfce);
    const hasNative = nativeNfe || nativeNfce;
    const [emitting, setEmitting] = useState<string | null>(null);

    const handleEmit = (model: 'nfe' | 'nfce') => {
        if (!saleId) return;

        setEmitting(model);
        router.post(
            route('app.sales.fiscal.emit', saleId),
            { model },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setEmitting(null),
            },
        );
    };
    const { data, setData, post, processing, errors } = useForm({
        fiscal_document_number: '',
        fiscal_document_url: '',
        fiscal_issued_at: '',
        fiscal_notes: '',
    });

    const handleRegisterFiscal = () => {
        if (!saleId) return;

        post(route('app.sales.fiscal.register', saleId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent className="scrollbar-default max-h-[calc(100svh-1rem)] w-[calc(100%-1rem)] max-w-md overflow-y-auto overscroll-contain p-4 sm:max-h-[90svh] sm:w-full sm:p-6">
                <DialogHeader>
                    <DialogTitle>{hasNative ? 'Emitir nota fiscal da venda' : 'Emitir NF-e de produtos'}</DialogTitle>

                    <DialogDescription>
                        {hasNative
                            ? 'A nota é emitida pelo sistema e acompanhada em Notas fiscais.'
                            : 'Use os dados da venda para emitir a NF-e no portal fiscal do emissor e registre o comprovante abaixo.'}
                    </DialogDescription>
                </DialogHeader>

                <Card>
                    <CardContent className="space-y-2 pt-4 text-sm">
                        <div>
                            <span className="font-medium">Cliente:</span> {sale.customer?.[0]?.name ?? 'Venda avulsa'}
                        </div>

                        <div>
                            <span className="font-medium">CPF/CNPJ:</span> {maskCpfCnpj(sale.customer?.[0]?.cpfcnpj ?? '')}
                        </div>

                        <Separator />

                        <div className="font-medium">Produtos</div>

                        <div>
                            <span className="font-medium">Série padrão:</span> {fiscalSetting?.default_nfe_series || 'Não configurada'}
                        </div>

                        {sale.items?.map((item, index: number) => (
                            <div key={index} className="flex justify-between text-sm">
                                <span>
                                    {item?.name} x {item.selected_quantity}
                                </span>

                                <span>R$ {maskMoney(String(item.sale_price ?? ''))}</span>
                            </div>
                        ))}

                        <Separator />

                        <div className="flex justify-between font-medium">
                            <span>Total</span>

                            <span>R$ {maskMoney(String(sale.total ?? ''))}</span>
                        </div>

                        {!canIssueInvoice ? (
                            <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-900">
                                Informe um valor maior que zero na venda para emitir a NF-e.
                            </div>
                        ) : null}

                        <div>
                            <span className="font-medium">Venda N°:</span> {sale?.numberSale?.id ?? '-'}
                        </div>
                    </CardContent>
                </Card>

                {hasNative && !hasRegisteredFiscal ? (
                    <div className="flex flex-wrap justify-end gap-2">
                        {nativeNfce && (
                            <Button
                                type="button"
                                variant={nativeNfe ? 'outline' : 'default'}
                                disabled={!canIssueInvoice || emitting !== null}
                                onClick={() => handleEmit('nfce')}
                            >
                                {emitting === 'nfce' ? 'Enviando...' : 'Emitir NFC-e'}
                            </Button>
                        )}
                        {nativeNfe && (
                            <Button
                                type="button"
                                disabled={!canIssueInvoice || emitting !== null || !sale.customer?.length}
                                onClick={() => handleEmit('nfe')}
                            >
                                {emitting === 'nfe' ? 'Enviando...' : 'Emitir NF-e'}
                            </Button>
                        )}
                        {nativeNfe && !sale.customer?.length && (
                            <p className="text-muted-foreground w-full text-right text-xs">A NF-e exige cliente identificado na venda.</p>
                        )}
                    </div>
                ) : null}

                <div className={hasNative ? 'hidden' : 'flex justify-end'}>
                    {!canIssueInvoice ? (
                        <Button type="button" disabled>
                            Abrir emissor
                        </Button>
                    ) : (
                        <Button asChild>
                            <a href="https://www.nfe.fazenda.gov.br/portal/principal.aspx" target="_blank" rel="noopener noreferrer">
                                Abrir portal NF-e
                            </a>
                        </Button>
                    )}
                </div>

                {(!hasNative || hasRegisteredFiscal) && (
                    <Card>
                        <CardContent className="space-y-3 pt-4 text-sm">
                            {hasRegisteredFiscal ? (
                                <>
                                    <div className="font-medium">Comprovante fiscal já registrado</div>
                                    <div>
                                        <span className="font-medium">Número:</span> {sale.fiscal_document_number || '-'}
                                    </div>
                                    <div>
                                        <span className="font-medium">Emitido em:</span> {sale.fiscal_issued_at || '-'}
                                    </div>
                                    {sale.fiscal_notes ? (
                                        <div>
                                            <span className="font-medium">Observações:</span> {sale.fiscal_notes}
                                        </div>
                                    ) : null}
                                    {sale.fiscal_document_url ? (
                                        <div className="flex justify-end">
                                            <Button asChild>
                                                <a href={sale.fiscal_document_url} target="_blank" rel="noopener noreferrer">
                                                    Abrir link da nota
                                                </a>
                                            </Button>
                                        </div>
                                    ) : null}
                                </>
                            ) : (
                                <>
                                    <div className="font-medium">Registrar NF-e emitida para consulta e auditoria</div>
                                    <div className="space-y-1">
                                        <Label htmlFor="fiscal_document_number">Número do documento</Label>
                                        <Input
                                            id="fiscal_document_number"
                                            value={data.fiscal_document_number}
                                            onChange={(e) => setData('fiscal_document_number', e.target.value)}
                                        />
                                        <InputError message={errors.fiscal_document_number} />
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="fiscal_document_url">URL</Label>
                                        <Input
                                            id="fiscal_document_url"
                                            value={data.fiscal_document_url}
                                            onChange={(e) => setData('fiscal_document_url', e.target.value)}
                                        />
                                        <InputError message={errors.fiscal_document_url} />
                                    </div>

                                    <div className="space-y-1">
                                        <Label htmlFor="fiscal_issued_at">Emitido em</Label>
                                        <Input
                                            id="fiscal_issued_at"
                                            type="datetime-local"
                                            value={data.fiscal_issued_at}
                                            onChange={(e) => setData('fiscal_issued_at', e.target.value)}
                                        />
                                        <InputError message={errors.fiscal_issued_at} />
                                    </div>
                                </>
                            )}
                        </CardContent>
                    </Card>
                )}

                <DialogFooter className="bg-background sticky bottom-0 z-10 -mx-4 -mb-4 flex justify-between border-t px-4 py-4 sm:-mx-6 sm:-mb-6 sm:px-6">
                    <Button variant="ghost" onClick={onClose}>
                        Fechar
                    </Button>
                    {!hasRegisteredFiscal && !hasNative && (
                        <Button onClick={handleRegisterFiscal} disabled={!saleId || processing}>
                            Salvar comprovante
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
