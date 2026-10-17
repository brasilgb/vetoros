import { apios } from '@/Utils/connectApi';
import selectStyles from '@/Utils/selectStyles';
import AsyncResourceSelect from '@/components/async-resource-select';
import { normalizeScannedEan13 } from '@/components/ean13-barcode';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { usePaperSize } from '@/hooks/usePaperSize';
import { formatBRL, multiplyMoney } from '@/lib/money';
import Receipt from '@/pages/app/sales/receipt';
import type { OptionType, PageProps, User } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { Barcode, FileText, FileTextIcon, Loader2, Printer, ShoppingCartIcon, Trash2 } from 'lucide-react'; // Adicionado Trash2 para remover item
import React, { useEffect, useRef, useState } from 'react';
import Select, { type SingleValue } from 'react-select';
import { useReactToPrint } from 'react-to-print';
import SaleInvoiceModal from './Modals/SaleInvoiceModal';
import { toastSuccess, toastWarning } from './app-toast-messages';

interface Customer {
    id: number;
    name: string;
    cpfcnpj?: string;
}

interface CompanyData {
    company?: string;
    cnpj?: string;
    street?: string;
    number?: string | number;
    district?: string;
    city?: string;
    state?: string;
    telephone?: string;
}

interface Part {
    id: number;
    name: string;
    quantity: number; // This is the stock quantity
    sale_price: number;
    customer_id: number | null;
    part_number?: string | number;
    reference_number?: string | number;
}

interface CartItem extends Part {
    cartItemId: string;
    selected_quantity: number;
    stock_quantity: number; // Adicione isso para clareza
}

interface SaleRecord {
    id?: string | number;
    date?: string;
    total_amount?: number;
    sales_number?: number;
    payment_method?: string;
    paid_amount?: number;
    financial_status?: string;
}

interface SaleFormData {
    customer_id: number | '';
    part_id: number | '';
    quantity: number;
    payment_method: 'pix' | 'cartao' | 'dinheiro' | 'transferencia' | 'boleto';
    paid_amount: string;
    parts: { part_id: number; quantity: number }[];
    total_amount: number;
}

interface AxiosErrorLike {
    response?: {
        data?: {
            message?: string;
        };
    };
}

interface CustomerOption extends OptionType {
    cpfcnpj?: string;
}

const mapCustomerOption = (customer: Customer): CustomerOption => ({
    value: customer.id,
    label: customer.name,
    cpfcnpj: customer.cpfcnpj,
});

interface SalesProductsProps {
    parts: Part[];
    iconSize?: number;
    triggerLabel?: string;
    triggerClassName?: string;
}

export function SalesProducts({ parts, iconSize, triggerLabel, triggerClassName }: SalesProductsProps) {
    const { auth, cashier } = usePage<PageProps<{ auth: { user: User & { tenant?: CompanyData } }; cashier?: { isOpen?: boolean } }>>().props;
    const companyData = auth?.user?.tenant;
    const isCashierOpen = Boolean(cashier?.isOpen);

    const [selectedPart, setSelectedPart] = useState<Part | null>(null);
    const [open, setOpen] = useState(false);
    const [successMessage, setSuccessMessage] = useState('');
    const [cartItems, setCartItems] = useState<CartItem[]>([]); // New state for cart items
    const [saleCompleted, setSaleCompleted] = useState(false);
    const [saleData, setSaleData] = useState<SaleRecord | null>(null);
    const [barcode, setBarcode] = useState('');

    const [isPrintingThermal, setIsPrintingThermal] = useState(false);
    const [isGeneratingPdf, setIsGeneratingPdf] = useState(false);
    const [openInvoiceModal, setOpenInvoiceModal] = useState(false);
    const [selectedCustomer, setSelectedCustomer] = useState<CustomerOption | null>(null);

    const receiptRef = useRef<HTMLDivElement>(null);

    const { data, setData, processing, errors, reset } = useForm<SaleFormData>({
        customer_id: '',
        part_id: '', // Keep part_id for selecting a part to add to cart
        quantity: 1, // Quantity for the currently selected part
        payment_method: 'pix',
        paid_amount: '0.00',
        parts: [] as { part_id: number; quantity: number }[], // This will be populated from cartItems
        total_amount: 0,
    });

    const normalizeCode = (value: string | number | null | undefined) =>
        String(value ?? '')
            .trim()
            .toLowerCase();

    const optionsParts: OptionType[] = parts.map((part) => ({
        value: part.id,
        label: part.reference_number ? `${part.name} - ${part.reference_number}` : part.name,
    }));

    useEffect(() => {
        const total = cartItems.reduce((sum, item) => sum + Number(item.sale_price) * item.selected_quantity, 0);
        setData((prevData) => ({
            ...prevData,
            parts: cartItems.map((item) => ({ part_id: item.id, quantity: item.selected_quantity })),
            total_amount: total,
            paid_amount: total.toFixed(2),
        }));
    }, [cartItems, setData]);

    const addPartToCart = (part: Part, quantity: number) => {
        if (quantity > 0) {
            // 1. Verifica se o item já está no carrinho
            const existingItemIndex = cartItems.findIndex((item) => item.id === part.id);
            // 2. Calcula quanto já tem no carrinho desse item
            const quantityInCart = existingItemIndex > -1 ? cartItems[existingItemIndex].selected_quantity : 0;
            // 3. Validação REAL de estoque (Carrinho + O que está tentando adicionar agora)
            if (quantityInCart + quantity > part.quantity) {
                toastWarning('Estoque insuficiente', `Você já tem ${quantityInCart} no carrinho e o estoque total é ${part.quantity}.`);
                return;
            }

            if (existingItemIndex > -1) {
                // ATUALIZAR ITEM EXISTENTE
                const updatedCart = cartItems.map((item, index) =>
                    index === existingItemIndex ? { ...item, selected_quantity: item.selected_quantity + quantity } : item,
                );
                setCartItems(updatedCart);
            } else {
                const { quantity: stockQuantity, ...partDetails } = part;

                const newItem: CartItem = {
                    ...partDetails, // Copia id, name, price... (SEM o quantity do estoque)
                    cartItemId: Date.now().toString(),
                    selected_quantity: quantity, // Esta é a quantidade vendida
                    quantity: stockQuantity,
                    stock_quantity: part.quantity,
                };
                setCartItems((prevItems) => [...prevItems, newItem]);
            }

            // Reset inputs
            setSelectedPart(null);
            setData((prevData) => ({ ...prevData, part_id: '', quantity: 1 }));
        }
    };

    const addToCart = () => {
        if (selectedPart && data.quantity > 0) {
            addPartToCart(selectedPart, data.quantity);
        } else if (selectedPart && data.quantity > selectedPart.quantity) {
            toastWarning('Quantidade maior que o estoque disponível.');
        }
    };

    const addByBarcode = () => {
        const code = normalizeScannedEan13(barcode) ?? normalizeCode(barcode);

        if (!code) return;

        const part = parts.find((item) =>
            [item.reference_number, item.part_number, item.id].some(
                (value) => (normalizeScannedEan13(String(value ?? '')) ?? normalizeCode(value)) === code,
            ),
        );

        if (!part) {
            toastWarning('Produto não encontrado', 'Confira o código de barras informado.');
            return;
        }

        addPartToCart(part, data.quantity || 1);
        setBarcode('');
    };

    const removeFromCart = (cartItemId: string) => {
        setCartItems((prevItems) => prevItems.filter((item) => item.cartItemId !== cartItemId));
        setData((data) => ({
            ...data,
            part_id: '',
            quantity: 1,
        }));
    };

    const handleSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (cartItems.length === 0) {
            toastWarning('Adicione itens ao carrinho antes de finalizar a venda.');
            return;
        }

        try {
            const response = await apios.post(route('app.sales.store'), {
                customer_id: data.customer_id,
                payment_method: data.payment_method,
                paid_amount: cartTotal.toFixed(2),
                parts: cartItems.map((item) => ({
                    part_id: item.id,
                    quantity: item.selected_quantity,
                })),
                total_amount: cartTotal,
            });
            setSaleData(response.data.sale);
            toastSuccess('Sucesso', 'Venda realizada com sucesso');
            setSaleCompleted(true);
            setOpen(true);
        } catch (error: unknown) {
            const message = (error as AxiosErrorLike).response?.data?.message ?? 'Erro ao realizar venda';
            toastWarning(message);
        }
    };

    const changeParts = (selected: SingleValue<OptionType>) => {
        const selectedValue = typeof selected?.value === 'number' ? selected.value : '';
        setData('part_id', selectedValue);
        const part = parts.find((part) => part.id === selectedValue);
        setSelectedPart(part || null);
        setData('quantity', 1); // Reset quantity when part changes
    };

    const changeCustomers = (selected: SingleValue<CustomerOption>) => {
        const selectedValue = typeof selected?.value === 'number' ? selected.value : '';
        setData('customer_id', selectedValue);
        setSelectedCustomer(selected ?? null);
    };

    const handleClose = () => {
        setOpen(false);
        setSuccessMessage('');
        reset();
        setSelectedPart(null);
        setSelectedCustomer(null);
        setBarcode('');
        setCartItems([]); // Clear cart on dialog close
        setSaleCompleted(false); // Reseta o estado da venda // Limpa o nome do cliente para a próxima venda
    };

    const handleNewSale = () => {
        setSuccessMessage('');
        reset();
        setSelectedPart(null);
        setSelectedCustomer(null);
        setBarcode('');
        setCartItems([]);
        setSaleCompleted(false);
    };

    const selectedOptionParts = optionsParts.find((option) => option.value === data.part_id) || null;
    const selectedOptionCustomers = selectedCustomer;
    const nfCustomer = selectedCustomer
        ? [{ id: Number(selectedCustomer.value), name: selectedCustomer.label, cpfcnpj: selectedCustomer.cpfcnpj }]
        : [];

    const cartTotal = cartItems.reduce((sum, item) => sum + Number(item.sale_price) * item.selected_quantity, 0);

    const paper = usePaperSize();

    // 1. Função para Cupom Não Fiscal (Térmica)
    const handlePrintThermal = useReactToPrint({
        contentRef: receiptRef,
        documentTitle: 'Comprovante de Pagamento',
        // Troque 'onBeforeGetContent' por 'onBeforePrint'
        onBeforePrint: async () => {
            setIsPrintingThermal(true);
            // Se precisar fazer alguma validação ou busca de dados antes de imprimir, faça aqui
            return Promise.resolve();
        },
        onAfterPrint: () => setIsPrintingThermal(false),
        onPrintError: () => setIsPrintingThermal(false),
    });

    // 2. Função para Recibo A4 (PDF)
    const customerNameForPDF = selectedOptionCustomers?.label || 'Consumidor Final';
    const handleGeneratePDF = async () => {
        if (cartItems.length === 0) return;

        setIsGeneratingPdf(true);
        const previewWindow = window.open('', '_blank');

        if (!previewWindow) {
            setIsGeneratingPdf(false);
            return;
        }

        previewWindow.document.title = 'Gerando recibo...';
        previewWindow.document.body.innerHTML = '<p style="font-family: Arial, sans-serif; padding: 16px;">Gerando recibo PDF...</p>';

        try {
            // Gera o blob do PDF
            // O gerador de PDF (~1,5 MB) só é baixado quando o usuário pede o documento.
            const [{ pdf }, { default: SaleReceiptPDF }] = await Promise.all([import('@react-pdf/renderer'), import('./SaleReceiptPDF')]);
            const blob = await pdf(
                <SaleReceiptPDF
                    items={cartItems}
                    total={cartTotal}
                    customerName={customerNameForPDF}
                    sale={saleData ?? {}}
                    company={companyData ?? {}}
                />,
            ).toBlob();

            const url = URL.createObjectURL(blob);
            previewWindow.location.href = url;
            setTimeout(() => URL.revokeObjectURL(url), 60_000);
        } catch (error) {
            previewWindow.close();
            console.error('Erro ao gerar PDF:', error);
            toastWarning('Erro ao gerar o PDF.');
        } finally {
            setIsGeneratingPdf(false);
        }
    };

    const dataSalesTotal = {
        customer: nfCustomer,
        items: cartItems,
        total: String(Number(saleData?.total_amount ?? cartTotal)),
        numberSale: saleData ?? {},
    };
    const canIssueSaleInvoice = Number(saleData?.total_amount ?? cartTotal) > 0;

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <Tooltip>
                <TooltipTrigger asChild>
                    <DialogTrigger
                        className={triggerClassName ?? 'flex h-full cursor-pointer items-center justify-center'}
                        aria-label="Abrir frente de caixa"
                    >
                        <ShoppingCartIcon size={iconSize} className={isCashierOpen ? 'text-green-300' : 'text-amber-400 opacity-70'} />
                        {triggerLabel && <span>{triggerLabel}</span>}
                    </DialogTrigger>
                </TooltipTrigger>
                <TooltipContent>Abrir frente de caixa (PDV)</TooltipContent>
            </Tooltip>
            <DialogContent className="sm:max-w-lvh">
                <DialogHeader>
                    <DialogTitle>Frente de Caixa</DialogTitle>
                    <DialogDescription>Registre uma nova venda.</DialogDescription>
                </DialogHeader>
                {!isCashierOpen && (
                    <div className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
                        O caixa diário precisa estar aberto para registrar vendas.
                    </div>
                )}
                <SaleInvoiceModal open={openInvoiceModal} onClose={() => setOpenInvoiceModal(false)} sale={dataSalesTotal} />
                <form onSubmit={handleSubmit} autoComplete="off">
                    {successMessage && <p className="mb-4 text-center text-green-500">{successMessage}</p>}
                    <div className="grid gap-4 py-4">
                        <div className="items-center gap-4">
                            <Label htmlFor="customer_id" className="mb-1 text-right">
                                Selecione o cliente
                            </Label>
                            <AsyncResourceSelect<CustomerOption>
                                inputId="customer_id"
                                searchUrl={route('app.customers.search')}
                                value={selectedOptionCustomers}
                                onChange={changeCustomers}
                                mapOption={mapCustomerOption}
                                placeholder="Digite o nome do cliente..."
                                className="w-full"
                            />
                            {errors.customer_id && <p className="col-span-4 text-right text-xs text-red-500">{errors.customer_id}</p>}
                        </div>
                        <div className="items-center gap-4">
                            <Label htmlFor="payment_method" className="mb-1 text-right">
                                Forma de pagamento
                            </Label>
                            <select
                                id="payment_method"
                                value={data.payment_method}
                                onChange={(e) => setData('payment_method', e.target.value as SaleFormData['payment_method'])}
                                className="h-9 w-full rounded-md border border-gray-300 bg-transparent px-3 text-sm shadow-xs"
                            >
                                <option value="pix">Pix</option>
                                <option value="cartao">Cartão</option>
                                <option value="dinheiro">Dinheiro</option>
                                <option value="transferencia">Transferência</option>
                                <option value="boleto">Boleto</option>
                            </select>
                            {errors.payment_method && <p className="col-span-4 text-right text-xs text-red-500">{errors.payment_method}</p>}
                        </div>
                        <div className="grid grid-cols-1 gap-2 lg:grid-cols-6 lg:gap-4">
                            <div className="items-center gap-4 lg:col-span-2">
                                <Label htmlFor="part_id" className="mb-1 text-right">
                                    Selecione o Produto
                                </Label>
                                <Select
                                    isSearchable
                                    value={selectedOptionParts}
                                    options={optionsParts}
                                    onChange={changeParts}
                                    placeholder="Selecione a peça/produto"
                                    className="w-full"
                                    styles={selectStyles}
                                />
                                {errors.part_id && <p className="col-span-4 text-right text-xs text-red-500">{errors.part_id}</p>}
                            </div>
                            <div className="items-center gap-4 lg:col-span-2">
                                <Label htmlFor="sale_barcode" className="mb-1 text-right">
                                    Código de barras
                                </Label>
                                <div className="relative">
                                    <Input
                                        id="sale_barcode"
                                        value={barcode}
                                        onChange={(event) => setBarcode(event.target.value)}
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter') {
                                                event.preventDefault();
                                                addByBarcode();
                                            }
                                        }}
                                        placeholder="Digite ou leia o código"
                                        autoComplete="off"
                                        className="pr-12"
                                    />
                                    <Button
                                        type="button"
                                        size="icon"
                                        className="absolute top-0 right-0 h-full rounded-l-none"
                                        title="Adicionar produto pelo código de barras"
                                        onClick={addByBarcode}
                                    >
                                        <Barcode className="h-4 w-4" />
                                    </Button>
                                </div>
                            </div>
                            <div className="items-center gap-4 lg:col-span-1">
                                <div>
                                    <Label htmlFor="quantity" className="mb-1 text-right">
                                        Quantidade
                                    </Label>
                                    <Input
                                        id="quantity"
                                        type="number"
                                        value={data.quantity}
                                        onChange={(e) => setData('quantity', parseInt(e.target.value, 10) || 1)}
                                        className="col-span-3 w-full"
                                    />
                                </div>
                                <div className="h-4">
                                    {!selectedPart ||
                                        data.quantity <= 0 ||
                                        (data.quantity > selectedPart.quantity && (
                                            <div className="col-span-4 text-left text-xs text-red-500">Estoque insuficiente</div>
                                        ))}
                                </div>
                            </div>
                            <div className="lg:mt-6">
                                <Button
                                    type="button"
                                    onClick={addToCart}
                                    disabled={!selectedPart || data.quantity <= 0 || data.quantity > selectedPart.quantity}
                                    className="w-full"
                                >
                                    <ShoppingCartIcon /> Adicionar
                                </Button>
                            </div>
                        </div>
                    </div>
                    <div className="mb-2 flex items-center justify-between">
                        <div className="flex items-center justify-between">
                            <div className="flex items-center justify-start gap-4">
                                {selectedPart && (
                                    <>
                                        <div className="text-sm">
                                            Em estoque: <span className="font-semibold text-blue-400">{selectedPart?.quantity}</span>
                                        </div>
                                        <div className="text-sm">
                                            Valor unitário:{' '}
                                            <span className="font-semibold text-blue-400">{formatBRL(Number(selectedPart?.sale_price))}</span>
                                        </div>
                                        <div className="text-sm">
                                            Total:{' '}
                                            <span className="font-semibold text-blue-400">
                                                {formatBRL(
                                                    multiplyMoney(
                                                        selectedPart?.sale_price,
                                                        data?.quantity <= selectedPart?.quantity ? data?.quantity : selectedPart?.quantity,
                                                    ),
                                                )}
                                            </span>
                                        </div>
                                    </>
                                )}
                            </div>
                        </div>
                    </div>

                    {cartItems.length > 0 && (
                        <div className="mt-4 border-t pt-4">
                            <h3 className="mb-2 text-lg font-semibold">Itens no Carrinho</h3>
                            {errors.parts && <p className="col-span-4 text-right text-xs text-red-500">{errors.parts}</p>}
                            {cartItems.map((item) => (
                                <div key={item.cartItemId} className="mb-2 flex items-center justify-between">
                                    <span>
                                        {item.name} (x{item.selected_quantity}) - {formatBRL(Number(item.sale_price) * item.selected_quantity)}
                                    </span>
                                    <Button variant="destructive" size="sm" onClick={() => removeFromCart(item.cartItemId)}>
                                        <Trash2 size={16} />
                                    </Button>
                                </div>
                            ))}
                            <div className="mt-4 flex items-center justify-between font-bold">
                                <span>Total do Carrinho:</span>
                                <span>{formatBRL(cartTotal)}</span>
                            </div>
                            {errors.paid_amount && <p className="mt-2 text-right text-xs text-red-500">{errors.paid_amount}</p>}
                            {errors.total_amount && <p className="col-span-4 text-right text-xs text-red-500">{errors.total_amount}</p>}
                        </div>
                    )}

                    <DialogFooter className="mt-4 gap-2 sm:justify-between">
                        {/* Botão Fechar (sempre visível ou ajuste conforme preferência) */}
                        <Button type="button" variant="outline" onClick={handleClose}>
                            Fechar
                        </Button>

                        {!saleCompleted ? (
                            <Button type="submit" disabled={processing || cartItems.length === 0 || !isCashierOpen} className="w-full sm:w-auto">
                                {processing ? <Loader2 className="mr-2 animate-spin" /> : null}
                                {processing ? 'Processando...' : 'Finalizar Venda'}
                            </Button>
                        ) : (
                            <div className="flex w-full flex-col justify-end gap-2 sm:w-auto sm:flex-row">
                                {/* Botão Nova Venda */}
                                <Button type="button" variant="secondary" onClick={handleNewSale}>
                                    Nova Venda
                                </Button>

                                {/* Botão emitir nota fiscal */}
                                <Button
                                    type="button"
                                    onClick={() => setOpenInvoiceModal(true)}
                                    disabled={!canIssueSaleInvoice}
                                    title={canIssueSaleInvoice ? 'Emitir NFE' : 'Informe um valor maior que zero para emitir NF-e'}
                                    className="rounded-lg py-2 text-sm font-medium"
                                >
                                    <FileTextIcon className="h-4 w-4" />
                                    Emitir NFE
                                </Button>

                                {/* Botão 1: Cupom Não Fiscal (Térmica) */}
                                <Button
                                    type="button"
                                    onClick={() => handlePrintThermal()}
                                    disabled={isPrintingThermal || isGeneratingPdf}
                                    className="bg-orange-500 text-white hover:bg-orange-600"
                                >
                                    {isPrintingThermal ? <Loader2 className="size-4 animate-spin" /> : <Printer className="size-4" />}
                                    Cupom
                                </Button>

                                {/* Botão 2: Recibo PDF */}
                                <Button
                                    type="button"
                                    onClick={handleGeneratePDF}
                                    disabled={isPrintingThermal || isGeneratingPdf}
                                    className="bg-blue-600 text-white hover:bg-blue-700"
                                >
                                    {isGeneratingPdf ? <Loader2 className="size-4 animate-spin" /> : <FileText className="size-4" />}
                                    Recibo PDF
                                </Button>
                            </div>
                        )}
                    </DialogFooter>
                </form>

                <div className="hidden">
                    <Receipt
                        ref={receiptRef}
                        paper={paper}
                        items={cartItems}
                        total={cartTotal}
                        customer={selectedOptionCustomers?.label || 'Consumidor Final'}
                        sale={saleData}
                    />
                </div>
            </DialogContent>
        </Dialog>
    );
}
