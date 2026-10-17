import { toastSuccess } from '@/components/app-toast-messages';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Textarea } from '@/components/ui/textarea';
import { sumMoney } from '@/lib/money';
import { Budget, OptionType } from '@/types';
import { warrantyOptions } from '@/Utils/dataSelect';
import selectStyles from '@/Utils/selectStyles';
import { useForm, usePage } from '@inertiajs/react';
import { Save } from 'lucide-react';
import { useEffect, useState } from 'react';
import Select from 'react-select';
import EquipmentTypesModal from '../orders/equipment-types-modal';

interface BudgetFormProps {
    initialData?: Budget;
    budgets: Array<Budget | string | null>;
    equipments: { id: number; equipment: string; equipment_number?: number; chart?: boolean }[];
}

export default function BudgetForm({ initialData, equipments }: BudgetFormProps) {
    const isEdit = !!initialData;
    const { auth } = usePage().props as any;
    const canManageEquipments = auth?.permissions?.includes('register_equipments');

    const optionsEquipment: OptionType[] = equipments?.map((equipment) => ({
        value: equipment.id,
        label: equipment.equipment,
    }));

    /* =========================
     FORM STATE (INERTIA)
  ========================= */
    const { data, setData, post, patch, processing, reset, errors } = useForm({
        equipment_id: initialData?.equipment_id ?? '',
        service: initialData?.service ?? '',
        model: initialData?.model ?? '',
        description: initialData?.description ?? '',
        estimated_time: initialData?.estimated_time ?? '',
        part_value: initialData?.part_value ?? '',
        labor_value: initialData?.labor_value ?? '',
        total_value: initialData?.total_value ?? '',
        warranty: initialData?.warranty ?? '',
        validity: initialData?.validity ?? '',
        obs: initialData?.obs ?? '',
    });

    /* =========================
     SELECT OPTIONS
  ========================= */
    const defaultWarranty = warrantyOptions.find((o) => o.value === initialData?.warranty) ?? null;

    const [selectedWarranty, setSelectedWarranty] = useState<OptionType | null>(defaultWarranty);
    const selectedEquipment = optionsEquipment.find((option) => String(option.value) === String(data.equipment_id)) ?? null;

    // Total = peças + mão de obra, somado em centavos (pode ser ajustado depois no campo Total).
    useEffect(() => {
        setData((currentData) => ({ ...currentData, total_value: sumMoney([currentData.part_value, currentData.labor_value]) }));
    }, [data.part_value, data.labor_value]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();

        if (isEdit) {
            patch(route('app.budgets.update', initialData!.id), {
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Orçamento atualizado com sucesso');
                },
            });
        } else {
            post(route('app.budgets.store'), {
                onSuccess: () => {
                    toastSuccess('Sucesso', 'Orçamento criado com sucesso');
                    reset();
                    setSelectedWarranty(null);
                },
            });
        }
    };

    /* =========================
     SELECT HANDLERS
  ========================= */
    const changeWarranty = (option: OptionType | null) => {
        setSelectedWarranty(option);
        setData('warranty', String(option?.value ?? ''));
    };

    const changeEquipment = (selected: OptionType | null) => {
        setData('equipment_id', String(selected?.value ?? ''));
    };

    const textareaClass =
        'flex w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-ring';

    return (
        <form onSubmit={handleSubmit} className="space-y-8">
            <Card>
                <CardTitle className="border-b px-6 pb-4">Dados do orçamento</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="equipment">Equipamento</Label>
                            <div className="flex min-w-0 items-center gap-2">
                                <Select<OptionType, false>
                                    menuPosition="fixed"
                                    value={selectedEquipment}
                                    options={optionsEquipment}
                                    onChange={changeEquipment}
                                    placeholder="Selecione o equipamento"
                                    className="min-w-0 flex-1"
                                    styles={selectStyles}
                                />
                                {canManageEquipments && (
                                    <EquipmentTypesModal
                                        equipments={equipments}
                                        selectedEquipmentId={data.equipment_id}
                                        onSelectEquipment={(equipmentId) => setData('equipment_id', equipmentId)}
                                    />
                                )}
                            </div>
                            {errors.equipment_id && <div className="text-sm text-red-500">{errors.equipment_id}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="model">Marca e Modelo</Label>
                            <Input
                                id="model"
                                type="text"
                                value={data.model}
                                onChange={(e) => setData('model', e.target.value)}
                                placeholder="Digite a marca e o modelo"
                            />
                            <InputError message={errors.model} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Serviço</Label>
                            <Input value={data.service} onChange={(e) => setData('service', e.target.value)} />
                            <InputError message={errors.service} />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label>Descrição</Label>
                        <Textarea className={textareaClass} value={data.description} onChange={(e) => setData('description', e.target.value)} />
                        <InputError message={errors.description} />
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Valores e condições</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label>Valor Peças</Label>
                            <MoneyInput aria-label="Valor peças" value={data.part_value} onValueChange={(value) => setData('part_value', value)} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Tempo Estimado do Serviço (Hs)</Label>
                            <Input value={data.estimated_time} onChange={(e) => setData('estimated_time', e.target.value)} />
                            <InputError message={errors.estimated_time} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Garantia</Label>
                            <Select<OptionType, false>
                                value={selectedWarranty}
                                options={warrantyOptions}
                                onChange={changeWarranty}
                                isClearable
                                styles={selectStyles}
                                placeholder="Selecione ou defina a garantia"
                                className="min-w-0"
                            />
                            <InputError message={errors.warranty} />
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label>Mão de Obra</Label>
                            <MoneyInput
                                aria-label="Mão de obra"
                                value={data.labor_value}
                                onValueChange={(value) => setData('labor_value', value)}
                                error={errors.labor_value}
                            />
                            <InputError message={errors.labor_value} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Total</Label>
                            <MoneyInput
                                aria-label="Total"
                                value={data.total_value}
                                onValueChange={(value) => setData('total_value', value)}
                                error={errors.total_value}
                            />
                            <InputError message={errors.total_value} />
                        </div>

                        <div className="grid gap-2">
                            <Label>Validade do orçamento(dias)</Label>
                            <Input value={String(data.validity)} onChange={(e) => setData('validity', e.target.value)} />
                            <InputError message={errors.validity} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Observações</CardTitle>
                <CardContent className="pt-6">
                    <div className="grid gap-4">
                        <div className="grid gap-2">
                            <Label>Observações</Label>
                            <Textarea className={textareaClass} value={data.obs} onChange={(e) => setData('obs', e.target.value)} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    <Save className="mr-2" />
                    {isEdit ? 'Atualizar' : 'Salvar'}
                </Button>
            </div>
        </form>
    );
}
