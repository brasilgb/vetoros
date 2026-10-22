import { toastSuccess } from '@/components/app-toast-messages';
import FormFieldHelp from '@/components/form-field-help';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { useForm, usePage } from '@inertiajs/react';
import { Pencil, Plus, Save, Trash2, X } from 'lucide-react';
import { useEffect, useState } from 'react';
import { EQUIPMENT_KINDS, equipmentKindLabel } from '../equipments/equipment-kinds';

type EquipmentType = {
    id: number;
    equipment: string;
    equipment_number?: number;
    kind?: string | null;
    chart?: boolean;
};

type EquipmentFlash = {
    id: number;
    equipment?: string;
    kind?: string | null;
};

export default function EquipmentTypesModal({
    equipments,
    selectedEquipmentId,
    onSelectEquipment,
}: {
    equipments: EquipmentType[];
    selectedEquipmentId?: string | number | null;
    onSelectEquipment: (equipmentId: string) => void;
}) {
    const { flash } = usePage().props as {
        flash?: {
            equipment_saved?: EquipmentFlash;
            equipment_deleted?: { id: number };
        };
    };
    const [open, setOpen] = useState(false);
    const [editingEquipment, setEditingEquipment] = useState<EquipmentType | null>(null);

    const createForm = useForm({
        equipment: '',
        kind: '',
        chart: false,
        _inline: true,
    });

    const editForm = useForm({
        equipment: '',
        kind: '',
        chart: false,
        _inline: true,
    });

    const deleteForm = useForm({
        _inline: true,
    });

    useEffect(() => {
        if (!flash?.equipment_saved?.id) {
            return;
        }

        onSelectEquipment(String(flash.equipment_saved.id));
    }, [flash?.equipment_saved?.id]);

    useEffect(() => {
        if (!flash?.equipment_deleted?.id || String(flash.equipment_deleted.id) !== String(selectedEquipmentId ?? '')) {
            return;
        }

        onSelectEquipment('');
    }, [flash?.equipment_deleted?.id, selectedEquipmentId]);

    const submitCreate = (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        // Dialog content is rendered via a React Portal: it sits outside the
        // order form in the DOM, but React still bubbles this submit event
        // through the component tree. Without stopPropagation() it also
        // triggers the outer order form's onSubmit, firing two competing
        // Inertia requests (the equipment save gets cancelled mid-flight).
        event.stopPropagation();

        createForm.post(route('app.register-equipments.store'), {
            only: ['equipments', 'flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                createForm.reset();
                toastSuccess('Sucesso', 'Tipo de equipamento cadastrado com sucesso');
            },
        });
    };

    const startEdit = (equipment: EquipmentType) => {
        setEditingEquipment(equipment);
        editForm.setData({
            equipment: equipment.equipment,
            kind: equipment.kind ?? '',
            chart: Boolean(equipment.chart),
            _inline: true,
        });
    };

    const submitEdit = (event?: React.FormEvent<HTMLFormElement> | React.MouseEvent<HTMLButtonElement>) => {
        event?.preventDefault();
        // See submitCreate: this form also lives inside a Dialog portal, so its
        // submit event (e.g. pressing Enter in the input) would otherwise bubble
        // through the React tree into the outer order form's onSubmit.
        event?.stopPropagation();

        if (!editingEquipment) {
            return;
        }

        editForm.patch(route('app.register-equipments.update', editingEquipment.id), {
            only: ['equipments', 'flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                setEditingEquipment(null);
                toastSuccess('Sucesso', 'Tipo de equipamento atualizado com sucesso');
            },
        });
    };

    const destroyEquipment = (equipmentId: number) => {
        deleteForm.delete(route('app.register-equipments.destroy', { equipment: equipmentId, _inline: true }), {
            only: ['equipments', 'flash'],
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                toastSuccess('Sucesso', 'Tipo de equipamento excluído com sucesso');
            },
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button
                    type="button"
                    variant="outline"
                    size="icon"
                    title="Gerenciar tipos de equipamento"
                    aria-label="Gerenciar tipos de equipamento"
                >
                    <Plus className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[760px]">
                <DialogHeader>
                    <DialogTitle>Tipos de equipamento</DialogTitle>
                </DialogHeader>

                <form onSubmit={submitCreate} className="grid gap-4 rounded-md border p-4" autoComplete="off">
                    <div className="grid gap-4 md:grid-cols-[1fr_160px_auto_auto] md:items-end">
                        <div className="grid gap-2">
                            <Label htmlFor="new-equipment">Nome do tipo de equipamento *</Label>
                            <Input
                                id="new-equipment"
                                value={createForm.data.equipment}
                                onChange={(event) => createForm.setData('equipment', event.target.value)}
                            />
                            {createForm.errors.equipment && <div className="text-sm text-red-500">{createForm.errors.equipment}</div>}
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor="new-equipment-kind">Grupo</Label>
                            <Select onValueChange={(value) => createForm.setData('kind', value)} value={createForm.data.kind}>
                                <SelectTrigger className="w-full" id="new-equipment-kind">
                                    <SelectValue placeholder="Opcional" />
                                </SelectTrigger>
                                <SelectContent>
                                    {EQUIPMENT_KINDS.map((option) => (
                                        <SelectItem key={option.value} value={option.value}>
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="flex items-center gap-2 pb-2">
                            <Switch
                                id="new-equipment-chart"
                                checked={createForm.data.chart}
                                onCheckedChange={(checked) => createForm.setData('chart', checked)}
                            />
                            <FormFieldHelp
                                label="Gráfico"
                                content="Quando ativado, este tipo de equipamento será considerado no gráfico do dashboard."
                            />
                        </div>
                        <Button type="submit" disabled={createForm.processing}>
                            <Save className="h-4 w-4" />
                            Salvar
                        </Button>
                    </div>
                </form>

                <div className="rounded-md border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[90px]">#</TableHead>
                                <TableHead>Tipo de equipamento</TableHead>
                                <TableHead className="w-[140px]">Grupo</TableHead>
                                <TableHead className="w-[120px]">Gráfico</TableHead>
                                <TableHead className="w-[120px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {equipments.length ? (
                                equipments.map((equipment) => (
                                    <TableRow key={equipment.id}>
                                        <TableCell>{equipment.equipment_number ?? equipment.id}</TableCell>
                                        <TableCell>
                                            {editingEquipment?.id === equipment.id ? (
                                                <form onSubmit={submitEdit} className="grid gap-2" autoComplete="off">
                                                    <Input
                                                        value={editForm.data.equipment}
                                                        onChange={(event) => editForm.setData('equipment', event.target.value)}
                                                    />
                                                    {editForm.errors.equipment && (
                                                        <div className="text-sm text-red-500">{editForm.errors.equipment}</div>
                                                    )}
                                                </form>
                                            ) : (
                                                <button
                                                    type="button"
                                                    className="text-left font-medium hover:underline"
                                                    onClick={() => onSelectEquipment(String(equipment.id))}
                                                >
                                                    {equipment.equipment}
                                                </button>
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {editingEquipment?.id === equipment.id ? (
                                                <Select onValueChange={(value) => editForm.setData('kind', value)} value={editForm.data.kind}>
                                                    <SelectTrigger className="w-full">
                                                        <SelectValue placeholder="Opcional" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {EQUIPMENT_KINDS.map((option) => (
                                                            <SelectItem key={option.value} value={option.value}>
                                                                {option.label}
                                                            </SelectItem>
                                                        ))}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                equipmentKindLabel(equipment.kind)
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            {editingEquipment?.id === equipment.id ? (
                                                <Switch
                                                    checked={editForm.data.chart}
                                                    onCheckedChange={(checked) => editForm.setData('chart', checked)}
                                                    aria-label="Adicionar ao gráfico"
                                                />
                                            ) : equipment.chart ? (
                                                'Sim'
                                            ) : (
                                                'Não'
                                            )}
                                        </TableCell>
                                        <TableCell>
                                            <div className="flex justify-end gap-2">
                                                {editingEquipment?.id === equipment.id ? (
                                                    <>
                                                        <Button type="button" size="icon" onClick={submitEdit} disabled={editForm.processing}>
                                                            <Save className="h-4 w-4" />
                                                        </Button>
                                                        <Button type="button" variant="outline" size="icon" onClick={() => setEditingEquipment(null)}>
                                                            <X className="h-4 w-4" />
                                                        </Button>
                                                    </>
                                                ) : (
                                                    <>
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            className="bg-orange-500 text-white hover:bg-orange-600"
                                                            onClick={() => startEdit(equipment)}
                                                            title="Editar tipo de equipamento"
                                                            aria-label="Editar tipo de equipamento"
                                                        >
                                                            <Pencil className="h-4 w-4" />
                                                        </Button>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button
                                                                    type="button"
                                                                    variant="destructive"
                                                                    size="icon"
                                                                    title="Excluir tipo de equipamento"
                                                                    aria-label="Excluir tipo de equipamento"
                                                                >
                                                                    <Trash2 className="h-4 w-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Excluir este tipo de equipamento?</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        Esta ação remove o tipo de equipamento do cadastro.
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancelar</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        className="bg-red-600 hover:bg-red-700"
                                                                        onClick={() => destroyEquipment(equipment.id)}
                                                                    >
                                                                        Excluir
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </>
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="h-16 text-center">
                                        Nenhum tipo de equipamento cadastrado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </DialogContent>
        </Dialog>
    );
}
