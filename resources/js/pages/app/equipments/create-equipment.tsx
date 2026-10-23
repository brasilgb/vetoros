import { toastSuccess } from '@/components/app-toast-messages';
import FormFieldHelp from '@/components/form-field-help';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { useForm } from '@inertiajs/react';
import { Plus, Save } from 'lucide-react';
import { useState } from 'react';

export default function CreateEquipment() {
    const [open, setOpen] = useState(false);

    const { data, setData, processing, post, errors, reset } = useForm({
        equipment: '',
        chart: false,
    });

    const handleSubmit = (e: any) => {
        e.preventDefault();
        post(route('app.register-equipments.store'), {
            onSuccess: () => {
                (toastSuccess('Sucesso', 'Tipo de equipamento cadastrado com sucesso'), reset(), setOpen(false));
            },
        });
    };

    return (
        <div className="w-full md:w-auto">
            <Dialog open={open} onOpenChange={setOpen}>
                <DialogTrigger asChild>
                    <Button className="w-full gap-2 whitespace-nowrap sm:w-auto">
                        <Plus className="h-4 w-4" />
                        <span>Novo tipo de equipamento</span>
                    </Button>
                </DialogTrigger>
                <DialogContent className="sm:max-w-[500px]">
                    <DialogHeader>
                        <DialogTitle>Cadastrar tipo de equipamento</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={handleSubmit} autoComplete="off">
                        <div className="grid gap-4 py-4">
                            <div className="grid gap-2">
                                <Label htmlFor="equipment">Nome do tipo de equipamento *</Label>
                                <Input id="equipment" value={data.equipment} onChange={(e) => setData('equipment', e.target.value)} />
                                {errors.equipment && <div className="text-sm text-red-500">{errors.equipment}</div>}
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
