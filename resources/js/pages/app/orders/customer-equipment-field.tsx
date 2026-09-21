import { toastSuccess } from '@/components/app-toast-messages';
import AsyncResourceSelect from '@/components/async-resource-select';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { OptionType } from '@/types';
import { useForm, usePage } from '@inertiajs/react';
import { Plus, Save } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import EquipmentTypesModal from './equipment-types-modal';

type EquipmentTypeOption = {
    id: number;
    equipment: string;
    kind?: string | null;
};

type CustomerEquipmentOption = {
    id: number;
    customer_equipment_number: number;
    equipment_id: number | null;
    brand: string | null;
    model: string | null;
    serial_number: string | null;
    imei: string | null;
};

type CustomerEquipmentSavedFlash = CustomerEquipmentOption;

type CustomerEquipmentSelectOption = OptionType & { device: CustomerEquipmentOption };

const mapOption = (item: CustomerEquipmentOption): CustomerEquipmentSelectOption => ({
    value: item.id,
    label:
        `#${item.customer_equipment_number} · ${[item.brand, item.model].filter(Boolean).join(' ') || 'Sem marca/modelo'}` +
        (item.serial_number ? ` · SN ${item.serial_number}` : ''),
    device: item,
});

export default function CustomerEquipmentField({
    customerId,
    equipmentTypes,
    initialDevice,
    onChange,
}: {
    customerId: string | number;
    equipmentTypes: EquipmentTypeOption[];
    initialDevice?: CustomerEquipmentOption | null;
    onChange: (customerEquipmentId: string, device?: CustomerEquipmentOption) => void;
}) {
    const { flash } = usePage().props as { flash?: { customer_equipment_saved?: CustomerEquipmentSavedFlash } };
    const [open, setOpen] = useState(false);
    const [selected, setSelected] = useState<CustomerEquipmentSelectOption | null>(
        initialDevice ? mapOption(initialDevice) : null,
    );
    const [reloadToken, setReloadToken] = useState(0);

    const createForm = useForm({
        equipment_id: '',
        brand: '',
        model: '',
        serial_number: '',
        imei: '',
        _inline: true,
    });

    useEffect(() => {
        if (!flash?.customer_equipment_saved?.id) {
            return;
        }

        const device = flash.customer_equipment_saved;
        setSelected(mapOption(device));
        onChange(String(device.id), device);
        setReloadToken((token) => token + 1);
    }, [flash?.customer_equipment_saved?.id]);

    const isFirstRender = useRef(true);
    useEffect(() => {
        if (isFirstRender.current) {
            isFirstRender.current = false;
            return;
        }
        setSelected(null);
    }, [customerId]);

    const submitCreate = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        event.stopPropagation();

        createForm.post(route('app.customers.equipments.store', customerId), {
            only: ['flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                createForm.reset();
                setOpen(false);
                toastSuccess('Sucesso', 'Equipamento cadastrado com sucesso');
            },
        });
    };

    return (
        <div className="flex min-w-0 items-center gap-2">
            <AsyncResourceSelect<CustomerEquipmentSelectOption>
                key={`${customerId}-${reloadToken}`}
                inputId="customer_equipment_id"
                searchUrl={route('app.customer-equipments.search', { customer_id: customerId || 0 })}
                value={selected}
                onChange={(option) => {
                    setSelected(option);
                    onChange(String(option?.value ?? ''), option?.device);
                }}
                mapOption={mapOption as (item: unknown) => CustomerEquipmentSelectOption}
                isDisabled={!customerId}
                placeholder={customerId ? 'Selecione o equipamento' : 'Selecione o cliente primeiro'}
                className="min-w-0 flex-1 text-gray-500"
            />
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        disabled={!customerId}
                        title="Cadastrar equipamento do cliente"
                        aria-label="Cadastrar equipamento do cliente"
                    >
                        <Plus className="h-4 w-4" />
                    </Button>
                </DialogTrigger>
                <DialogContent className="sm:max-w-[480px]">
                    <DialogHeader>
                        <DialogTitle>Cadastrar equipamento do cliente</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitCreate} autoComplete="off">
                        <div className="grid gap-4 py-4 md:grid-cols-2">
                            <div className="grid gap-2 md:col-span-2">
                                <Label htmlFor="new-ce-equipment-type">Tipo de equipamento</Label>
                                <div className="flex min-w-0 items-center gap-2">
                                    <Select
                                        onValueChange={(selectedValue) => createForm.setData('equipment_id', selectedValue)}
                                        value={createForm.data.equipment_id}
                                    >
                                        <SelectTrigger className="w-full" id="new-ce-equipment-type">
                                            <SelectValue placeholder="Selecione o tipo" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {equipmentTypes.map((type) => (
                                                <SelectItem key={type.id} value={String(type.id)}>
                                                    {type.equipment}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                    <EquipmentTypesModal
                                        equipments={equipmentTypes}
                                        selectedEquipmentId={createForm.data.equipment_id}
                                        onSelectEquipment={(equipmentId) => createForm.setData('equipment_id', equipmentId)}
                                    />
                                </div>
                                {createForm.errors.equipment_id && (
                                    <div className="text-sm text-red-500">{createForm.errors.equipment_id}</div>
                                )}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new-ce-brand">Marca</Label>
                                <Input
                                    id="new-ce-brand"
                                    value={createForm.data.brand}
                                    onChange={(e) => createForm.setData('brand', e.target.value)}
                                />
                                {createForm.errors.brand && <div className="text-sm text-red-500">{createForm.errors.brand}</div>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new-ce-model">Modelo</Label>
                                <Input
                                    id="new-ce-model"
                                    value={createForm.data.model}
                                    onChange={(e) => createForm.setData('model', e.target.value)}
                                />
                                {createForm.errors.model && <div className="text-sm text-red-500">{createForm.errors.model}</div>}
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new-ce-serial">Número de série</Label>
                                <Input
                                    id="new-ce-serial"
                                    value={createForm.data.serial_number}
                                    onChange={(e) => createForm.setData('serial_number', e.target.value)}
                                    placeholder="Opcional"
                                />
                            </div>
                            <div className="grid gap-2">
                                <Label htmlFor="new-ce-imei">IMEI</Label>
                                <Input
                                    id="new-ce-imei"
                                    value={createForm.data.imei}
                                    onChange={(e) => createForm.setData('imei', e.target.value)}
                                    placeholder="Opcional"
                                />
                                {createForm.errors.imei && <div className="text-sm text-red-500">{createForm.errors.imei}</div>}
                            </div>
                        </div>
                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={createForm.processing}>
                                <Save className="h-4 w-4" />
                                Salvar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
