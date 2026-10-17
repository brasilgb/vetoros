import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Select, SelectContent, SelectGroup, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { useForm } from '@inertiajs/react';
import { Edit, Save } from 'lucide-react';
import { useState } from 'react';

interface Plan {
    id: number;
    name: string;
}

interface Period {
    id: number;
    name: string;
    plan_id: number | string;
    interval: string;
    interval_count: string | number;
    price: string | number;
}

interface EditPeriodProps {
    plans: Plan[];
    period: Period;
}

export default function EditPeriod({ plans, period }: EditPeriodProps) {
    const [open, setOpen] = useState(false);

    const { data, setData, patch, processing, errors } = useForm({
        name: period.name,
        plan_id: period.plan_id,
        interval: period.interval,
        interval_count: period.interval_count,
        price: period.price,
    });

    const handleSubmit = async (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        patch(route('admin.periods.update', period.id), {
            onSuccess: () => setOpen(false),
        });
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>
                <Button className="gap-2 bg-orange-500 text-white hover:bg-orange-600">
                    <Edit className="h-4 w-4" />
                </Button>
            </DialogTrigger>
            <DialogContent className="sm:max-w-[500px]">
                <DialogHeader>
                    <DialogTitle>Cadastrar um período</DialogTitle>
                </DialogHeader>
                <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                    <div className="grid gap-2">
                        <Label htmlFor="name">Descrição</Label>
                        <Input type="text" id="name" value={data.name} onChange={(e) => setData('name', e.target.value)} />
                        {errors.name && <div className="text-sm text-red-500">{errors.name}</div>}
                    </div>

                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="interval">Intervalo</Label>
                            <Input type="text" id="interval" value={data.interval} onChange={(e) => setData('interval', e.target.value)} />
                            {errors.interval && <div className="text-sm text-red-500">{errors.interval}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="interval_count">Contagem do intervalo</Label>
                            <Input
                                type="text"
                                id="interval_count"
                                value={data.interval_count}
                                onChange={(e) => setData('interval_count', e.target.value)}
                            />
                            {errors.interval_count && <div className="text-sm text-red-500">{errors.interval_count}</div>}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="plan_id">Plano</Label>
                        <Select onValueChange={(e) => setData('plan_id', e)} defaultValue={`${data.plan_id}`}>
                            <SelectTrigger className="w-full">
                                <SelectValue placeholder="Selecione um plano" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectGroup>
                                    {plans.map((plan) => (
                                        <SelectItem key={plan.id} value={`${plan.id}`}>
                                            {plan.name}
                                        </SelectItem>
                                    ))}
                                </SelectGroup>
                            </SelectContent>
                        </Select>
                        {errors.plan_id && <div className="text-sm text-red-500">{errors.plan_id}</div>}
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="price">Preço</Label>
                        <MoneyInput id="price" value={data.price} onValueChange={(value) => setData('price', value)} error={errors.price} />
                        {errors.price && <div className="text-sm text-red-500">{errors.price}</div>}
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
    );
}
