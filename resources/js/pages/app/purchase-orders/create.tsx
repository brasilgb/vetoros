import AsyncResourceSelect from '@/components/async-resource-select';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatBRL, multiplyMoney, sumMoney } from '@/lib/money';
import { BreadcrumbItem, OptionType } from '@/types';
import { Head, useForm } from '@inertiajs/react';
import { Plus, Trash2, Truck } from 'lucide-react';
import { useMemo, useState } from 'react';

type Supplier = { id: number; name: string };

type PurchaseOrderItem = {
    id?: number;
    part_id: number;
    quantity: number;
    unit_cost: number;
    part?: { id: number; name: string; reference_number?: string };
};

type PurchaseOrder = {
    id: number;
    purchase_order_number?: number;
    supplier_id?: number | null;
    expected_date?: string | null;
    notes?: string | null;
    items: PurchaseOrderItem[];
};

type ItemFormRow = {
    part_id: number | null;
    quantity: string;
    unit_cost: string;
};

type SuggestedItem = {
    part_id: number;
    part_label: string;
    quantity: number;
    unit_cost: number;
    supplier_id: number | null;
};

export default function PurchaseOrderCreate({
    purchaseOrder,
    suppliers,
    suggestedItem,
}: {
    purchaseOrder?: PurchaseOrder;
    suppliers: Supplier[];
    suggestedItem?: SuggestedItem | null;
}) {
    const isEdit = !!purchaseOrder;

    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Painel', href: route('app.dashboard') },
        { title: 'Ordens de compra', href: route('app.purchase-orders.index') },
        { title: isEdit ? 'Editar' : 'Nova ordem de compra', href: '#' },
    ];

    const emptyRow: ItemFormRow = { part_id: null, quantity: '1', unit_cost: '' };

    const initialItems: ItemFormRow[] = purchaseOrder?.items?.length
        ? purchaseOrder.items.map((item) => ({
              part_id: item.part_id,
              quantity: String(item.quantity),
              unit_cost: String(item.unit_cost),
          }))
        : suggestedItem
          ? [{ part_id: suggestedItem.part_id, quantity: String(suggestedItem.quantity), unit_cost: String(suggestedItem.unit_cost) }]
          : [emptyRow];

    const initialItemOptions: (OptionType | null)[] = purchaseOrder?.items?.length
        ? purchaseOrder.items.map((item) => ({
              value: item.part_id,
              label: item.part ? `${item.part.name}${item.part.reference_number ? ` (${item.part.reference_number})` : ''}` : `Peça #${item.part_id}`,
          }))
        : suggestedItem
          ? [{ value: suggestedItem.part_id, label: suggestedItem.part_label }]
          : [null];

    const form = useForm({
        supplier_id: purchaseOrder?.supplier_id
            ? String(purchaseOrder.supplier_id)
            : suggestedItem?.supplier_id
              ? String(suggestedItem.supplier_id)
              : '',
        expected_date: purchaseOrder?.expected_date ?? '',
        notes: purchaseOrder?.notes ?? '',
        items: initialItems,
    });

    const [itemOptions, setItemOptions] = useState<(OptionType | null)[]>(initialItemOptions);

    const addItem = () => {
        form.setData('items', [...form.data.items, emptyRow]);
        setItemOptions((options) => [...options, null]);
    };

    const removeItem = (index: number) => {
        form.setData(
            'items',
            form.data.items.filter((_, i) => i !== index),
        );
        setItemOptions((options) => options.filter((_, i) => i !== index));
    };

    const updateItem = (index: number, field: keyof ItemFormRow, value: string | number | null) => {
        const items = [...form.data.items];
        items[index] = { ...items[index], [field]: value };
        form.setData('items', items);
    };

    const total = useMemo(() => sumMoney(form.data.items.map((item) => multiplyMoney(item.unit_cost, item.quantity))), [form.data.items]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEdit) {
            form.put(route('app.purchase-orders.update', purchaseOrder!.id));
            return;
        }

        form.post(route('app.purchase-orders.store'));
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={isEdit ? 'Editar ordem de compra' : 'Nova ordem de compra'} />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Truck} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">
                        {isEdit ? `Editar ordem de compra ${purchaseOrder?.purchase_order_number ?? ''}` : 'Nova ordem de compra'}
                    </h2>
                </div>
            </div>

            {!isEdit && suggestedItem && (
                <div className="mx-4 mb-2 rounded-md border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 dark:border-amber-900 dark:bg-amber-950/40 dark:text-amber-300">
                    Sugestão gerada a partir do estoque baixo de <strong>{suggestedItem.part_label}</strong>: quantidade e fornecedor foram
                    preenchidos automaticamente, revise antes de salvar.
                </div>
            )}

            <form onSubmit={handleSubmit} className="space-y-4 p-4">
                <Card>
                    <CardTitle className="border-b px-6 pb-4">Dados da compra</CardTitle>
                    <CardContent className="space-y-4 pt-6">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="supplier_id">Fornecedor</Label>
                                <select
                                    id="supplier_id"
                                    value={form.data.supplier_id}
                                    onChange={(e) => form.setData('supplier_id', e.target.value)}
                                    className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                                >
                                    <option value="">Selecione um fornecedor</option>
                                    {suppliers.map((supplier) => (
                                        <option key={supplier.id} value={supplier.id}>
                                            {supplier.name}
                                        </option>
                                    ))}
                                </select>
                                <InputError message={form.errors.supplier_id} />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="expected_date">Previsão de entrega</Label>
                                <Input
                                    id="expected_date"
                                    type="date"
                                    value={form.data.expected_date}
                                    onChange={(e) => form.setData('expected_date', e.target.value)}
                                />
                                <InputError message={form.errors.expected_date} />
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Observações</Label>
                            <Textarea id="notes" value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} rows={3} />
                            <InputError message={form.errors.notes} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardTitle className="flex items-center justify-between border-b px-6 pb-4">
                        <span>Itens</span>
                        <Button type="button" size="sm" variant="outline" onClick={addItem}>
                            <Plus className="h-4 w-4" />
                            Adicionar peça
                        </Button>
                    </CardTitle>
                    <CardContent className="pt-6">
                        <InputError message={form.errors.items as string} />
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="min-w-[260px]">Peça</TableHead>
                                        <TableHead className="w-32">Quantidade</TableHead>
                                        <TableHead className="w-40">Custo unitário</TableHead>
                                        <TableHead className="w-32">Subtotal</TableHead>
                                        <TableHead className="w-12"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {form.data.items.map((item, index) => (
                                        <TableRow key={index}>
                                            <TableCell>
                                                <AsyncResourceSelect
                                                    inputId={`items.${index}.part_id`}
                                                    searchUrl={route('app.parts.search')}
                                                    value={itemOptions[index] ?? null}
                                                    onChange={(option) => {
                                                        setItemOptions((options) => {
                                                            const next = [...options];
                                                            next[index] = option;
                                                            return next;
                                                        });
                                                        updateItem(index, 'part_id', option ? Number(option.value) : null);
                                                    }}
                                                    placeholder="Buscar peça..."
                                                    mapOption={(part: any) => ({
                                                        value: part.id,
                                                        label: `${part.name}${part.reference_number ? ` (${part.reference_number})` : ''}`,
                                                    })}
                                                />
                                                <InputError message={(form.errors as any)[`items.${index}.part_id`]} />
                                            </TableCell>
                                            <TableCell>
                                                <Input
                                                    type="number"
                                                    min={1}
                                                    step={1}
                                                    value={item.quantity}
                                                    onChange={(e) => updateItem(index, 'quantity', e.target.value)}
                                                />
                                                <InputError message={(form.errors as any)[`items.${index}.quantity`]} />
                                            </TableCell>
                                            <TableCell>
                                                <MoneyInput
                                                    placeholder="0,00"
                                                    aria-label="Custo unitário"
                                                    value={item.unit_cost}
                                                    onValueChange={(value) => updateItem(index, 'unit_cost', value)}
                                                />
                                                <InputError message={(form.errors as any)[`items.${index}.unit_cost`]} />
                                            </TableCell>
                                            <TableCell>{formatBRL(multiplyMoney(item.unit_cost, item.quantity))}</TableCell>
                                            <TableCell>
                                                {form.data.items.length > 1 && (
                                                    <Button type="button" variant="ghost" size="icon" onClick={() => removeItem(index)}>
                                                        <Trash2 className="h-4 w-4" />
                                                    </Button>
                                                )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>

                        <div className="mt-4 flex justify-end text-lg font-semibold">Total: {formatBRL(total)}</div>
                    </CardContent>
                </Card>

                <div className="flex justify-end gap-2">
                    <Button type="button" variant="outline" onClick={() => window.history.back()}>
                        Cancelar
                    </Button>
                    <Button type="submit" disabled={form.processing}>
                        {isEdit ? 'Salvar alterações' : 'Salvar ordem de compra'}
                    </Button>
                </div>
            </form>
        </AppLayout>
    );
}
