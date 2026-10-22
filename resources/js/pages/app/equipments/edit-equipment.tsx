import { toastSuccess } from '@/components/app-toast-messages';
import FormFieldHelp from '@/components/form-field-help';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/react';
import { Pencil, Save } from 'lucide-react';
import { useState } from 'react';
import { EQUIPMENT_KINDS } from './equipment-kinds';

export default function EditEquipment({ equipment, page, search }: any) {
    const [open, setOpen] = useState(false);

    const { data, setData, processing, patch, errors } = useForm({
        equipment: equipment.equipment,
        kind: equipment.kind ?? '',
        chart: equipment.chart,
    });

    const handleSubmit = (e: any) => {
        e.preventDefault();
        patch(route('app.register-equipments.update', equipment.id), {
            onSuccess: () => {
                toastSuccess('Sucesso', 'Tipo de equipamento atualizado com sucesso');
                setOpen(false);
            },
        });
    };

    return (
        <div>
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button size={'icon'} className="bg-orange-500 text-white hover:bg-orange-600">
                        <Pencil className="h-4 w-4" />
                    </Button>
                </DialogTrigger>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>Editar tipo de equipamento</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} autoComplete="off">
                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="nome">Nome do tipo de equipamento *</Label>
                                <Input id="nome" value={data.equipment} onChange={(e) => setData('equipment', e.target.value)} />
                                {errors.equipment && <div className="text-sm text-red-500">{errors.equipment}</div>}
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="kind">Grupo</Label>
                                <Select onValueChange={(value) => setData('kind', value)} value={data.kind}>
                                    <SelectTrigger className="w-full" id="kind">
                                        <SelectValue placeholder="Selecione (opcional)" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {EQUIPMENT_KINDS.map((option) => (
                                            <SelectItem key={option.value} value={option.value}>
                                                {option.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {errors.kind && <div className="text-sm text-red-500">{errors.kind}</div>}
                            </div>

                            <div className="grid gap-2">
                                <FormFieldHelp
                                    label="Adicionar ao gráfico do dashboard"
                                    content="Quando ativado, este tipo de equipamento será considerado no gráfico do dashboard."
                                />
                                <Switch id="chart" checked={data.chart} onCheckedChange={(checked: any) => setData('chart', checked)} />
                            </div>
                        </div>

                        <DialogFooter className="gap-2">
                            <Button type="button" variant="outline" onClick={() => setOpen(false)}>
                                Cancelar
                            </Button>
                            <Button type="submit" disabled={processing}>
                                <Save />
                                Salvar
                            </Button>
                        </DialogFooter>
                    </form>
                </DialogContent>
            </Dialog>
        </div>
    );
}
