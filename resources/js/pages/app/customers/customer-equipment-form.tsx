import { toastSuccess } from '@/components/app-toast-messages';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { OptionType } from '@/types';
import selectStyles from '@/Utils/selectStyles';
import { useForm } from '@inertiajs/react';
import { Pencil, Plus, Save } from 'lucide-react';
import { useState } from 'react';
import Select from 'react-select';

export type CustomerEquipment = {
    id: number;
    customer_equipment_number: number;
    equipment_id: number | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    imei: string | null;
    color: string | null;
    accessories: string | null;
    notes: string | null;
    status: 'active' | 'inactive';
};

export default function CustomerEquipmentForm({
    customerId,
    equipmentTypes,
    equipment,
}: {
    customerId: number;
    equipmentTypes: { id: number; equipment: string }[];
    equipment?: CustomerEquipment;
}) {
    const [open, setOpen] = useState(false);
    const isEdit = Boolean(equipment);

    const { data, setData, post, patch, processing, errors, reset } = useForm({
        equipment_id: equipment?.equipment_id ? String(equipment.equipment_id) : '',
        brand: equipment?.brand ?? '',
        model: equipment?.model ?? '',
        serial_number: equipment?.serial_number ?? '',
        imei: equipment?.imei ?? '',
        color: equipment?.color ?? '',
        accessories: equipment?.accessories ?? '',
        notes: equipment?.notes ?? '',
        status: equipment?.status ?? 'active',
    });

    const optionsEquipmentType: OptionType[] = equipmentTypes.map((type) => ({ value: type.id, label: type.equipment }));
    const selectedEquipmentType = optionsEquipmentType.find((option) => String(option.value) === String(data.equipment_id)) ?? null;

    const handleSubmit = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        const options = {
            preserveScroll: true,
            onSuccess: () => {
                toastSuccess('Sucesso', isEdit ? 'Equipamento atualizado com sucesso' : 'Equipamento cadastrado com sucesso');
                setOpen(false);
                if (!isEdit) reset();
            },
        };

        if (isEdit && equipment) {
            patch(route('app.customer-equipments.update', equipment.id), options);
        } else {
            post(route('app.customers.equipments.store', customerId), options);
        }
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                {isEdit ? (
                    <Button size="icon" className="bg-orange-500 text-white hover:bg-orange-600" title="Editar equipamento">
                        <Pencil className="h-4 w-4" />
                    </Button>
                ) : (
                    <Button className="w-full gap-2 whitespace-nowrap sm:w-auto">
                        <Plus className="h-4 w-4" />
                        <span>Novo equipamento</span>
                    </Button>
                )}
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[560px]">
                <DialogHeader>
                    <DialogTitle>{isEdit ? 'Editar equipamento' : 'Cadastrar equipamento do cliente'}</DialogTitle>
                </DialogHeader>
                <form onSubmit={handleSubmit} autoComplete="off">
                    <div className="grid gap-4 py-4">
                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="equipment_id">Categoria</Label>
                                <Select<OptionType, false>
                                    inputId="equipment_id"
                                    menuPosition="fixed"
                                    isClearable
                                    value={selectedEquipmentType}
                                    options={optionsEquipmentType}
                                    onChange={(selected) => setData('equipment_id', String(selected?.value ?? ''))}
                                    placeholder="Selecione a categoria"
                                    styles={selectStyles}
                                />
                                {errors.equipment_id && <div className="text-sm text-red-500">{errors.equipment_id}</div>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="brand">Marca</Label>
                                <Input id="brand" value={data.brand} onChange={(e) => setData('brand', e.target.value)} />
                                {errors.brand && <div className="text-sm text-red-500">{errors.brand}</div>}
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="model">Modelo</Label>
                                <Input id="model" value={data.model} onChange={(e) => setData('model', e.target.value)} />
                                {errors.model && <div className="text-sm text-red-500">{errors.model}</div>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="color">Cor</Label>
                                <Input id="color" value={data.color} onChange={(e) => setData('color', e.target.value)} />
                            </div>
                        </div>

                        <div className="grid gap-4 md:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="serial_number">Número de série</Label>
                                <Input
                                    id="serial_number"
                                    value={data.serial_number}
                                    onChange={(e) => setData('serial_number', e.target.value)}
                                    placeholder="Opcional"
                                />
                                {errors.serial_number && <div className="text-sm text-red-500">{errors.serial_number}</div>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="imei">IMEI</Label>
                                <Input id="imei" value={data.imei} onChange={(e) => setData('imei', e.target.value)} placeholder="Opcional" />
                                {errors.imei && <div className="text-sm text-red-500">{errors.imei}</div>}
                            </div>
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="accessories">Acessórios</Label>
                            <Textarea id="accessories" value={data.accessories} onChange={(e) => setData('accessories', e.target.value)} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="notes">Observações</Label>
                            <Textarea id="notes" value={data.notes} onChange={(e) => setData('notes', e.target.value)} />
                        </div>

                        <div className="flex items-center gap-2">
                            <Switch
                                id="status"
                                checked={data.status === 'active'}
                                onCheckedChange={(checked) => setData('status', checked ? 'active' : 'inactive')}
                            />
                            <Label htmlFor="status">Equipamento ativo</Label>
                        </div>
                    </div>

                    <DialogFooter className="gap-2">
                        <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                            Cancelar
                        </Button>
                        <Button type="submit" disabled={processing}>
                            <Save className="h-4 w-4" />
                            Salvar
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
