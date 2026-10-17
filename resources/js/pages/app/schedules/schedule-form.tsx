import { DateTimePicker } from '@/components/date-time-picker';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { formatBRL } from '@/lib/money';
import { Customer, Scheduler, User } from '@/types';
import { statusAgenda } from '@/Utils/dataSelect';
import selectStyles from '@/Utils/selectStyles';
import { useForm } from '@inertiajs/react';
import { Camera, CheckCircle2, ClipboardCheck, CreditCard, FileText, Plus, Save, Smartphone, Trash2, XCircle } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';
import Select from 'react-select';
import AddPartsModal from '../orders/add-parts';

interface ScheduleFormProps {
    initialData?: Scheduler;
    customers: Customer[];
    parts?: any[];
    technicals: User[];
    enableTechnicianScheduleNotifications: boolean;
}

type MaterialChecklistItem = {
    name: string;
    quantity: number;
    part_id?: number | null;
    used?: boolean;
};

function normalizeMaterialChecklist(items: any): MaterialChecklistItem[] {
    if (!Array.isArray(items)) return [];

    return items
        .map((item: any) => {
            if (typeof item === 'string') {
                const name = item.trim();

                return name ? { name, quantity: 1, part_id: null, used: false } : null;
            }

            const name = String(item?.name || item?.label || item?.item || '').trim();
            const quantity = Math.max(1, parseInt(String(item?.quantity ?? 1), 10) || 1);

            return name ? { name, quantity, part_id: item?.part_id ?? null, used: Boolean(item?.used) } : null;
        })
        .filter(Boolean) as MaterialChecklistItem[];
}

function formatDateTime(value: any) {
    if (!value) return 'Não informado';

    const date = moment(value);

    return date.isValid() ? date.format('DD/MM/YYYY [às] HH:mm') : 'Não informado';
}

function getMobileStage(summary: any) {
    if (!summary?.sent_to_technician) {
        return { label: 'Não enviado ao técnico', className: 'border-muted-foreground/30 text-muted-foreground' };
    }

    if (summary.has_check_out) {
        return { label: 'Finalizado no app', className: 'border-emerald-200 bg-emerald-50 text-emerald-700' };
    }

    if (summary.has_check_in) {
        return { label: 'Em atendimento', className: 'border-sky-200 bg-sky-50 text-sky-700' };
    }

    return { label: 'Enviado ao técnico', className: 'border-amber-200 bg-amber-50 text-amber-700' };
}

function TechnicianAttendancePanel({ schedule }: { schedule: any }) {
    const summary = schedule?.mobile_summary;
    const report = summary?.technician_report;
    const stage = getMobileStage(summary);
    const checklistItems = summary?.checklist_items || [];
    const completedItems = summary?.checklist_completed_items || [];
    const images = summary?.images || schedule?.images || [];
    const localPaymentAmount = summary?.local_payment_amount ?? schedule?.local_payment_amount;
    const cashierForm = useForm({});

    return (
        <Card id="technician-report">
            <CardTitle className="border-b px-6 pb-4">Atendimento técnico</CardTitle>
            <CardContent className="space-y-5 pt-6">
                <div className="flex flex-wrap gap-2">
                    <Badge variant="outline" className={stage.className}>
                        <Smartphone className="h-3 w-3" />
                        {stage.label}
                    </Badge>
                    <Badge variant="outline" className={summary?.has_report ? 'border-indigo-200 bg-indigo-50 text-indigo-700' : ''}>
                        <FileText className="h-3 w-3" />
                        {summary?.has_report ? 'Relatório preenchido' : 'Relatório pendente'}
                    </Badge>
                    <Badge
                        variant="outline"
                        className={
                            summary?.has_checklist
                                ? summary?.checklist_completed
                                    ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
                                    : 'border-amber-200 bg-amber-50 text-amber-700'
                                : ''
                        }
                    >
                        <ClipboardCheck className="h-3 w-3" />
                        {summary?.has_checklist ? (summary?.checklist_completed ? 'Checklist concluído' : 'Checklist pendente') : 'Sem checklist'}
                    </Badge>
                    <Badge variant="outline" className="border-slate-200 bg-slate-50 text-slate-700">
                        <Camera className="h-3 w-3" />
                        {summary?.images_count || 0} foto{Number(summary?.images_count || 0) === 1 ? '' : 's'}
                    </Badge>
                    {summary?.local_payment_received && (
                        <Badge variant="outline" className="border-emerald-200 bg-emerald-50 text-emerald-700">
                            <CreditCard className="h-3 w-3" />
                            Pagamento local
                        </Badge>
                    )}
                </div>

                <div className="grid gap-3 md:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-md border p-3">
                        <div className="text-muted-foreground text-xs">Check-in</div>
                        <div className="mt-1 font-medium">{summary?.has_check_in ? 'Realizado' : 'Pendente'}</div>
                    </div>
                    <div className="rounded-md border p-3">
                        <div className="text-muted-foreground text-xs">Check-out</div>
                        <div className="mt-1 font-medium">{summary?.has_check_out ? 'Realizado' : 'Pendente'}</div>
                    </div>
                    <div className="rounded-md border p-3">
                        <div className="text-muted-foreground text-xs">Fotos anexadas</div>
                        <div className="mt-1 font-medium">{summary?.images_count || 0}</div>
                    </div>
                    <div className="rounded-md border p-3">
                        <div className="text-muted-foreground text-xs">Pagamentos</div>
                        <div className="mt-1 font-medium">{summary?.payments_count || 0}</div>
                    </div>
                </div>

                {(report?.diagnosis || report?.solution || report?.observations) && (
                    <div className="grid gap-3 lg:grid-cols-3">
                        <div className="rounded-md border p-3">
                            <div className="text-muted-foreground text-xs">Diagnóstico</div>
                            <div className="mt-2 text-sm whitespace-pre-wrap">{report?.diagnosis || 'Não informado'}</div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-muted-foreground text-xs">Solução aplicada</div>
                            <div className="mt-2 text-sm whitespace-pre-wrap">{report?.solution || 'Não informado'}</div>
                        </div>
                        <div className="rounded-md border p-3">
                            <div className="text-muted-foreground text-xs">Observações do técnico</div>
                            <div className="mt-2 text-sm whitespace-pre-wrap">{report?.observations || 'Não informado'}</div>
                        </div>
                    </div>
                )}

                {checklistItems.length > 0 && (
                    <div className="rounded-md border p-3">
                        <div className="text-muted-foreground mb-3 text-xs">Checklist do equipamento</div>
                        <div className="grid gap-2 md:grid-cols-2">
                            {checklistItems.map((item: string) => {
                                const isDone = completedItems.includes(item);

                                return (
                                    <div key={item} className="flex items-start gap-2 text-sm">
                                        {isDone ? (
                                            <CheckCircle2 className="mt-0.5 h-4 w-4 text-emerald-600" />
                                        ) : (
                                            <XCircle className="mt-0.5 h-4 w-4 text-amber-600" />
                                        )}
                                        <span>{item}</span>
                                    </div>
                                );
                            })}
                        </div>
                    </div>
                )}

                {images.length > 0 && (
                    <div className="rounded-md border p-3">
                        <div className="text-muted-foreground mb-3 text-xs">Fotos do atendimento</div>
                        <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-4">
                            {images.map((image: any) => (
                                <a
                                    key={image.id}
                                    href={image.url}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="group bg-muted block overflow-hidden rounded-md border"
                                >
                                    <img
                                        src={image.url}
                                        alt={`Foto do agendamento ${schedule?.schedules_number ?? ''}`}
                                        className="aspect-square w-full object-cover transition-transform group-hover:scale-105"
                                    />
                                </a>
                            ))}
                        </div>
                    </div>
                )}

                {summary?.local_payment_received && (
                    <div className="rounded-md border p-3">
                        <div className="flex flex-wrap items-start justify-between gap-3">
                            <div>
                                <div className="text-muted-foreground text-xs">Pagamento recebido pelo técnico</div>
                                {summary?.local_payment_registered_in_cashier && (
                                    <Badge variant="outline" className="mt-2 border-emerald-200 bg-emerald-50 text-emerald-700">
                                        Inserido no caixa
                                    </Badge>
                                )}
                            </div>
                            {summary?.can_register_local_payment_cashier && (
                                <Button
                                    type="button"
                                    size="sm"
                                    disabled={cashierForm.processing}
                                    onClick={() =>
                                        cashierForm.post(route('app.schedules.local-payment-cashier', schedule.id), {
                                            preserveScroll: true,
                                        })
                                    }
                                >
                                    <CreditCard className="h-4 w-4" />
                                    {cashierForm.processing ? 'Inserindo...' : 'Inserir no caixa'}
                                </Button>
                            )}
                        </div>
                        <div className="mt-3 grid gap-2 text-sm md:grid-cols-2">
                            <div>
                                <span className="text-muted-foreground">Valor: </span>
                                <span className="font-medium">{formatBRL(localPaymentAmount)}</span>
                            </div>
                            <div>
                                <span className="text-muted-foreground">Registrado em: </span>
                                <span className="font-medium">
                                    {formatDateTime(summary?.local_payment_received_at || schedule?.local_payment_received_at)}
                                </span>
                            </div>
                        </div>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default function ScheduleForm({ customers, parts = [], initialData, technicals, enableTechnicianScheduleNotifications }: ScheduleFormProps) {
    const isEdit = !!initialData;
    const [newMaterialItem, setNewMaterialItem] = useState('');
    const [newChecklistItem, setNewChecklistItem] = useState('');

    const optionsCustomer = customers.map((customer: any) => ({
        value: customer.id,
        label: customer.name,
    }));

    const optionsTechnical = technicals.map((technical: any) => ({
        value: technical.id,
        label: technical.name,
    }));

    const { data, setData, post, patch, processing, reset, errors } = useForm({
        customer_id: initialData?.customer_id ?? '',
        schedules: initialData?.schedules ?? '',
        service: initialData?.service ?? '',
        details: initialData?.details ?? '',
        material_checklist: normalizeMaterialChecklist(initialData?.material_checklist),
        technician_checklist: Array.isArray(initialData?.technician_checklist) ? initialData.technician_checklist : [],
        user_id: initialData?.user_id ?? '',
        status: initialData?.status ?? '',
        observations: initialData?.observations ?? '',
        send_to_technician: enableTechnicianScheduleNotifications ? (initialData?.send_to_technician ?? false) : false,
    });

    const changeCustomer = (selected: any) => {
        setData('customer_id', selected?.value || '');
    };

    const handleSubmit = (e: any) => {
        e.preventDefault();
        if (isEdit) {
            patch(route('app.schedules.update', initialData!.id));
        } else {
            post(route('app.schedules.store'), {
                onSuccess: () => {
                    reset();
                },
            });
        }
    };

    const changeServiceStatus = (selected: any) => {
        setData('status', selected?.value ?? '');
    };

    const changeResponsibleTechnician = (selected: any) => {
        setData('user_id', selected?.value ?? '');
    };

    const addMaterialItem = () => {
        const name = newMaterialItem.trim();
        if (!name) return;

        setData('material_checklist', [...data.material_checklist, { name, quantity: 1, part_id: null, used: false }]);
        setNewMaterialItem('');
    };

    const handlePartsModalSubmit = (selectedParts: any[]) => {
        const stockItems = selectedParts
            .map((part: any) => {
                const name = String(part?.name || '').trim();

                return name
                    ? {
                          name,
                          quantity: Math.max(1, parseInt(String(part?.quantity ?? 1), 10) || 1),
                          part_id: part?.id ?? null,
                          used: false,
                      }
                    : null;
            })
            .filter(Boolean) as MaterialChecklistItem[];

        if (stockItems.length > 0) {
            setData('material_checklist', [...data.material_checklist, ...stockItems]);
        }
    };

    const removeMaterialItem = (index: number) => {
        setData(
            'material_checklist',
            data.material_checklist.filter((_: MaterialChecklistItem, itemIndex: number) => itemIndex !== index),
        );
    };

    const addChecklistItem = () => {
        const item = newChecklistItem.trim();
        if (!item || data.technician_checklist.includes(item)) return;

        setData('technician_checklist', [...data.technician_checklist, item]);
        setNewChecklistItem('');
    };

    const removeChecklistItem = (index: number) => {
        setData(
            'technician_checklist',
            data.technician_checklist.filter((_: string, itemIndex: number) => itemIndex !== index),
        );
    };

    const defaultCustomer = optionsCustomer
        ?.filter((o: any) => o.value == initialData?.customer_id)
        .map((opt: any) => ({ value: opt.value, label: opt.label }));
    const statusDefault = statusAgenda
        ?.filter((o: any) => o.value == initialData?.status)
        .map((opt: any) => ({ value: opt.value, label: opt.label }));
    const defaultTechnical = optionsTechnical
        ?.filter((o: any) => o.value == initialData?.user_id)
        .map((opt: any) => ({ value: opt.value, label: opt.label }));

    return (
        <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
            <Card>
                <CardTitle className="border-b px-6 pb-4">Agendamento</CardTitle>
                <CardContent className="pt-6">
                    <div className="grid gap-4 lg:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="customer_id">Cliente</Label>
                            <Select
                                menuPosition="fixed"
                                defaultValue={defaultCustomer}
                                options={optionsCustomer}
                                onChange={changeCustomer}
                                placeholder="Selecione o cliente"
                                className="min-w-0"
                                styles={selectStyles}
                            />
                            <InputError className="mt-2" message={errors.customer_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="schedules">Horário da visita</Label>
                            <DateTimePicker id="schedules" value={data.schedules} onChange={(value) => setData('schedules', value)} />
                            <InputError className="mt-2" message={errors.schedules} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="flex flex-col gap-3 border-b px-6 pb-4 sm:flex-row sm:items-center sm:justify-between">
                    <span>Serviço e materiais</span>
                    <AddPartsModal onSubmit={handlePartsModalSubmit} parts={parts} submitLabel="Inserir no agendamento" />
                </CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="service">Serviço solicitado</Label>
                            <Textarea
                                id="service"
                                value={data.service}
                                onChange={(e) => setData('service', e.target.value)}
                                placeholder="Ex.: visita técnica, retirada de equipamento, avaliação no cliente"
                            />
                            <InputError className="mt-2" message={errors.service} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="details">Detalhes do atendimento</Label>
                            <Textarea
                                id="details"
                                value={data.details}
                                onChange={(e) => setData('details', e.target.value)}
                                placeholder="Descreva o que o técnico precisa verificar ou levar em conta"
                            />
                            <InputError className="mt-2" message={errors.details} />
                        </div>
                    </div>

                    <div className="grid gap-3">
                        <Label htmlFor="material_checklist">Inserir manualmente peças ou materiais</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="material_checklist"
                                value={newMaterialItem}
                                onChange={(event) => setNewMaterialItem(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        addMaterialItem();
                                    }
                                }}
                                placeholder="Ex.: fonte universal, multímetro, cabo USB-C"
                            />
                            <Button type="button" variant="outline" onClick={addMaterialItem}>
                                <Plus className="h-4 w-4" />
                                Adicionar
                            </Button>
                        </div>
                        <InputError className="mt-2" message={(errors as any).material_checklist} />

                        {data.material_checklist.length > 0 && (
                            <div className="grid gap-2 md:grid-cols-2">
                                {data.material_checklist.map((item: MaterialChecklistItem, index: number) => (
                                    <div
                                        key={`${item.name}-${index}`}
                                        className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <CheckCircle2 className="h-4 w-4 shrink-0 text-emerald-600" />
                                            <span className="truncate">
                                                {item.quantity > 1 ? `${item.quantity}x ` : ''}
                                                {item.name}
                                            </span>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeMaterialItem(index)}
                                            aria-label={`Remover material ${item.name}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>

                    <div className="grid gap-3 border-t pt-4">
                        <Label htmlFor="technician_checklist">Checklist técnico do atendimento</Label>
                        <div className="flex flex-col gap-2 sm:flex-row">
                            <Input
                                id="technician_checklist"
                                value={newChecklistItem}
                                onChange={(event) => setNewChecklistItem(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter') {
                                        event.preventDefault();
                                        addChecklistItem();
                                    }
                                }}
                                placeholder="Ex.: testar funcionamento, orientar cliente"
                            />
                            <Button type="button" variant="outline" onClick={addChecklistItem}>
                                <Plus className="h-4 w-4" />
                                Adicionar
                            </Button>
                        </div>
                        <InputError className="mt-2" message={(errors as any).technician_checklist} />

                        {data.technician_checklist.length > 0 && (
                            <div className="grid gap-2 md:grid-cols-2">
                                {data.technician_checklist.map((item: string, index: number) => (
                                    <div
                                        key={`${item}-${index}`}
                                        className="flex items-center justify-between gap-3 rounded-md border px-3 py-2 text-sm"
                                    >
                                        <div className="flex min-w-0 items-center gap-2">
                                            <ClipboardCheck className="h-4 w-4 shrink-0 text-emerald-600" />
                                            <span className="truncate">{item}</span>
                                        </div>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            onClick={() => removeChecklistItem(index)}
                                            aria-label={`Remover item ${item}`}
                                        >
                                            <Trash2 className="h-4 w-4" />
                                        </Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Acompanhamento</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="grid gap-4 lg:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="service_status">Técnico responsável</Label>
                            <Select
                                menuPosition="fixed"
                                defaultValue={defaultTechnical}
                                options={optionsTechnical}
                                onChange={changeResponsibleTechnician}
                                placeholder="Selecione o técnico"
                                className="min-w-0"
                                styles={selectStyles}
                            />
                            <InputError className="mt-2" message={errors.user_id} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="status">Status do agendamento</Label>
                            <Select
                                menuPosition="fixed"
                                defaultValue={statusDefault}
                                options={statusAgenda}
                                onChange={changeServiceStatus}
                                placeholder="Selecione o status"
                                className="min-w-0"
                                styles={selectStyles}
                            />
                            <InputError className="mt-2" message={errors.status} />
                        </div>

                        <div className="flex items-center justify-between gap-4 rounded-md border px-4 py-3 lg:mt-6">
                            <Label htmlFor="send_to_technician">Enviar ao técnico</Label>
                            <Switch
                                id="send_to_technician"
                                checked={Boolean(data.send_to_technician)}
                                disabled={!enableTechnicianScheduleNotifications}
                                onCheckedChange={(checked) => setData('send_to_technician', checked)}
                            />
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="observations">Observações</Label>
                        <Textarea id="observations" value={data.observations} onChange={(e) => setData('observations', e.target.value)} />
                    </div>
                </CardContent>
            </Card>

            {isEdit && <TechnicianAttendancePanel schedule={initialData} />}

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    <Save />
                    Salvar
                </Button>
            </div>
        </form>
    );
}
