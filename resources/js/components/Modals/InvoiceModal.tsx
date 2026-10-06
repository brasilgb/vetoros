import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Separator } from '@/components/ui/separator';
import { maskCpfCnpj, maskMoney } from '@/Utils/mask';
import { router, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

interface InvoiceModalProps {
    open: boolean;
    onClose: () => void;
    order: {
        id?: string | number;
        customer?: {
            name?: string;
            cpfcnpj?: string;
        };
        services_performed?: string;
        service_cost?: string | number;
        service_value?: string | number;
        parts_value?: string | number;
        order_number?: string | number;
        fiscal_document_number?: string;
        fiscal_document_url?: string;
        fiscal_issued_at?: string;
        fiscal_notes?: string;
    };
    summary?: {
        service_value?: string | number;
        parts_value?: string | number;
        total_order?: string | number;
    } | null;
}

export default function InvoiceModal({ open, onClose, order, summary = null }: InvoiceModalProps) {
    const { fiscalSetting } = usePage().props as any;
    const orderId = order?.id;
    const partsValue = Number(summary?.parts_value ?? order.parts_value ?? 0);
    const serviceValue = Number(summary?.service_value ?? order.service_value ?? 0);
    const totalOrder = Number(summary?.total_order ?? order.service_cost ?? serviceValue + partsValue);
    const canIssueInvoice = totalOrder > 0;
    const hasRegisteredFiscal = Boolean(order?.fiscal_document_number || order?.fiscal_document_url);
    const hasNative = Boolean(fiscalSetting?.native?.nfse);
    const [emitting, setEmitting] = useState(false);

    const handleEmit = () => {
        if (!orderId) return;

        setEmitting(true);
        router.post(
            route('app.orders.fiscal.emit', orderId),
            {},
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onFinish: () => setEmitting(false),
            },
        );
    };
    const { data, setData, post, processing, errors } = useForm({
        fiscal_document_number: '',
        fiscal_document_url: '',
        fiscal_issued_at: '',
        fiscal_notes: '',
    });

    useEffect(() => {
        if (!open) return;

        setData({
            fiscal_document_number: order?.fiscal_document_number ?? '',
            fiscal_document_url: order?.fiscal_document_url ?? '',
            fiscal_issued_at: order?.fiscal_issued_at ? String(order.fiscal_issued_at).slice(0, 16) : '',
            fiscal_notes: order?.fiscal_notes ?? '',
        });
    }, [open, order?.id, order?.fiscal_document_number, order?.fiscal_document_url, order?.fiscal_issued_at, order?.fiscal_notes, setData]);

    const handleRegisterFiscal = () => {
        if (!orderId) return;

        post(route('app.orders.fiscal.register', orderId), {
            preserveScroll: true,
            onSuccess: () => onClose(),
        });
    };

    return (
        <Dialog open={open} onOpenChange={onClose}>
            <DialogContent className="scrollbar-default max-h-[calc(100svh-1rem)] w-[calc(100%-1rem)] max-w-md overflow-y-auto overscroll-contain p-4 sm:max-h-[90svh] sm:w-full sm:p-6">
                <DialogHeader>
                    <DialogTitle>{hasNative ? 'Emitir NFS-e de serviço' : 'Emitir NFS-e Nacional de serviço'}</DialogTitle>

                    <DialogDescription>
                        {hasNative
                            ? 'A NFS-e é emitida pelo sistema com o valor dos serviços da OS e acompanhada em Notas fiscais.'
                            : 'Use os dados da OS para emitir no Emissor Nacional da NFS-e e registre o comprovante abaixo.'}
                    </DialogDescription>
                </DialogHeader>

                <Card>
                    <CardContent className="space-y-2 pt-4 text-sm">
                        <div>
                            <span className="font-medium">Cliente:</span> {order.customer?.name}
                        </div>

                        <div>
                            <span className="font-medium">CPF/CNPJ:</span> {maskCpfCnpj(order.customer?.cpfcnpj ?? '')}
                        </div>

                        <Separator />

                        <div>
                            <span className="font-medium">Serviço:</span> {order.services_performed}
                        </div>

                        <div>
                            <span className="font-medium">Item da lista:</span> {fiscalSetting?.service_list_item || 'Não configurado'}
                        </div>

                        <div>
                            <span className="font-medium">Município do serviço:</span> {fiscalSetting?.service_city_code || 'Não configurado'}
                        </div>

                        <div className="font-medium">Resumo para emissão da NFS-e</div>

                        <div className="flex justify-between text-sm">
                            <span>Peças</span>
                            <span>R$ {maskMoney(String(partsValue))}</span>
                        </div>

                        <div className="flex justify-between text-sm">
                            <span>Mão de obra</span>
                            <span>R$ {maskMoney(String(serviceValue))}</span>
                        </div>

                        <div className="flex justify-between font-medium">
                            <span>Total</span>
                            <span>R$ {maskMoney(String(totalOrder))}</span>
                        </div>

                        {!canIssueInvoice ? (
                            <div className="rounded-md border border-amber-200 bg-amber-50 p-2 text-amber-900">
                                Informe um valor maior que zero na ordem para emitir a NFS-e.
                            </div>
                        ) : null}

                        <div>
                            <span className="font-medium">Ordem de serviço:</span> #{order.order_number}
                        </div>
                    </CardContent>
                </Card>

                {hasNative && !hasRegisteredFiscal ? (
                    <div className="flex justify-end">
                        <Button type="button" disabled={serviceValue <= 0 || emitting} onClick={handleEmit}>
                            {emitting ? 'Enviando...' : 'Emitir NFS-e'}
                        </Button>
                    </div>
                ) : null}

                <div className={hasNative ? 'hidden' : 'flex justify-end'}>
                    {!canIssueInvoice ? (
                        <Button type="button" disabled>
                            Abrir emissor
                        </Button>
                    ) : (
                        <Button asChild>
                            <a href="https://www.nfse.gov.br/EmissorNacional" target="_blank" rel="noopener noreferrer">
                                Abrir Emissor Nacional
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
                                    <span className="font-medium">Número:</span> {order.fiscal_document_number || '-'}
                                </div>
                                <div>
                                    <span className="font-medium">Emitido em:</span> {order.fiscal_issued_at || '-'}
                                </div>
                                {order.fiscal_notes ? (
                                    <div>
                                        <span className="font-medium">Observações:</span> {order.fiscal_notes}
                                    </div>
                                ) : null}
                                {order.fiscal_document_url ? (
                                    <div className="flex justify-end">
                                        <Button asChild>
                                            <a href={order.fiscal_document_url} target="_blank" rel="noopener noreferrer">
                                                Abrir link da nota
                                            </a>
                                        </Button>
                                    </div>
                                ) : null}
                            </>
                        ) : (
                            <>
                                <div className="font-medium">Registrar NFS-e emitida para consulta e auditoria</div>

                                <div className="space-y-1">
                                    <Label htmlFor="fiscal_document_number">Número do documento</Label>
                                    <Input
                                        id="fiscal_document_number"
                                        value={data.fiscal_document_number}
                                        onChange={(e) => setData('fiscal_document_number', e.target.value)}
                                        placeholder="Número da NFS-e"
                                    />
                                    <InputError message={errors.fiscal_document_number} />
                                </div>

                                <div className="space-y-1">
                                    <Label htmlFor="fiscal_document_url">Link de consulta (opcional)</Label>
                                    <Input
                                        id="fiscal_document_url"
                                        value={data.fiscal_document_url}
                                        onChange={(e) => setData('fiscal_document_url', e.target.value)}
                                        placeholder="https://..."
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
                        <Button onClick={handleRegisterFiscal} disabled={!orderId || processing}>
                            Salvar comprovante
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
