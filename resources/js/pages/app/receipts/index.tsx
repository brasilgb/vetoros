import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { toastSuccess } from '@/components/app-toast-messages';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import { ClipboardCheck, FileText, Printer, Save } from 'lucide-react';
import moment from 'moment';
import type { FormEvent } from 'react';
import CreateChecklist from '../checklists/create-checklist';
import EditChecklist from '../checklists/edit-checklist';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Recibos / Checklist',
        href: '#',
    },
];

const previewValues = {
    cliente: 'João da Silva',
    cpf_cnpj: '000.000.000-00',
    ordem: '1024',
    equipamento: 'Notebook Dell Inspiron',
    modelo: 'Inspiron 15',
    defeito: 'Não liga',
    valor_orcamento: 'R$ 350,00',
    prazo: '3 dias úteis',
    empresa: 'Minha Empresa LTDA',
    cnpj_empresa: '00.000.000/0001-00',
    descricao: 'Manutenção preventiva mensal - Ar-condicionado',
    valor_mensalidade: 'R$ 150,00',
    dia_cobranca: '5',
    data_inicio: '01/01/2026',
    vigencia: '12 meses',
};

const normalizePlaceholderKey = (key: string) =>
    key
        .trim()
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '')
        .replace(/\s+/g, '_')
        .replace(/-/g, '_');

const renderPreview = (template?: string) => {
    if (!template) return '';

    return template.replace(/\{\{\s*([^}]+?)\s*\}\}/g, (_, key) => {
        const normalizedKey = normalizePlaceholderKey(String(key));

        if (normalizedKey in previewValues) {
            return previewValues[normalizedKey as keyof typeof previewValues];
        }

        return '';
    });
};

const checklistItems = (value?: string) =>
    String(value ?? '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean);

export default function Receipts({ receipt, equipments, checklists, activeTab = 'receipts', defaultReceiptMessages }: any) {
    const { auth, ziggy } = usePage<{ auth?: { permissions?: string[] }; ziggy?: { query?: Record<string, string> } }>().props;
    const canManageChecklists = auth?.permissions?.includes('register_checklists');
    const currentQuery = ziggy?.query ?? {};

    const { data, setData, patch, processing } = useForm({
        receivingequipment: receipt?.receivingequipment ?? defaultReceiptMessages?.receivingequipment ?? '',
        equipmentdelivery: receipt?.equipmentdelivery ?? defaultReceiptMessages?.equipmentdelivery ?? '',
        budgetissuance: receipt?.budgetissuance ?? defaultReceiptMessages?.budgetissuance ?? '',
        maintenance_contract_template: receipt?.maintenance_contract_template ?? defaultReceiptMessages?.maintenance_contract_template ?? '',
    });

    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();
        patch(route('app.receipts.update', receipt?.id), {
            onSuccess: () => {
                toastSuccess('Sucesso', 'Mensagens para recibos ajustadas com sucesso');
            },
        });
    };

    const handleResetDefaults = () => {
        setData('receivingequipment', defaultReceiptMessages?.receivingequipment ?? '');
        setData('equipmentdelivery', defaultReceiptMessages?.equipmentdelivery ?? '');
        setData('budgetissuance', defaultReceiptMessages?.budgetissuance ?? '');
    };

    const handleResetContractTemplate = () => {
        setData('maintenance_contract_template', defaultReceiptMessages?.maintenance_contract_template ?? '');
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Recibos / Checklist" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Printer} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Recibos / Checklist</h2>
                </div>
            </div>

            <div className="p-4">
                <Tabs
                    value={activeTab}
                    onValueChange={(tab) => {
                        router.get(
                            route('app.receipts.index'),
                            {
                                ...currentQuery,
                                tab,
                                page: undefined,
                            },
                            {
                                preserveState: true,
                                replace: true,
                            },
                        );
                    }}
                    className="space-y-4"
                >
                    <TabsList>
                        <TabsTrigger value="receipts">Recibos</TabsTrigger>
                        <TabsTrigger value="checklists">Checklists</TabsTrigger>
                        <TabsTrigger value="maintenance_contracts">Contrato de manutenção</TabsTrigger>
                    </TabsList>

                    <TabsContent value="receipts">
                        <div className="rounded-lg border p-4">
                            <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                                <div className="grid gap-5">
                                    <div className="flex flex-col gap-3 border-b pb-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div>
                                            <h3 className="text-base font-semibold">Textos dos recibos</h3>
                                            <p className="text-muted-foreground mt-1 text-sm">
                                                Configure as mensagens que serão impressas nos recibos de entrada, entrega e orçamento.
                                            </p>
                                        </div>
                                        <div className="text-muted-foreground flex flex-wrap gap-1 text-xs">
                                            {[
                                                '{{ cliente }}',
                                                '{{ cpf_cnpj }}',
                                                '{{ ordem }}',
                                                '{{ equipamento }}',
                                                '{{ modelo }}',
                                                '{{ defeito }}',
                                                '{{ valor_orcamento }}',
                                                '{{ prazo }}',
                                            ].map((placeholder) => (
                                                <Badge key={placeholder} variant="outline" className="font-mono">
                                                    {placeholder}
                                                </Badge>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 rounded-md border p-3 md:col-span-2">
                                        <Label htmlFor="receivingequipment" className="font-semibold">
                                            Recebimento de equipamento
                                        </Label>
                                        <Textarea
                                            id="receivingequipment"
                                            value={data.receivingequipment}
                                            onChange={(e) => setData('receivingequipment', e.target.value)}
                                            className="min-h-28"
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.receivingequipment)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 rounded-md border p-3 md:col-span-2">
                                        <Label htmlFor="equipmentdelivery" className="font-semibold">
                                            Entrega de equipamento
                                        </Label>
                                        <Textarea
                                            id="equipmentdelivery"
                                            value={data.equipmentdelivery}
                                            onChange={(e) => setData('equipmentdelivery', e.target.value)}
                                            className="min-h-28"
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.equipmentdelivery)}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 rounded-md border p-3 md:col-span-2">
                                        <Label htmlFor="budgetissuance" className="font-semibold">
                                            Emissão de orçamento inicial em ordens
                                        </Label>
                                        <Textarea
                                            id="budgetissuance"
                                            value={data.budgetissuance}
                                            onChange={(e) => setData('budgetissuance', e.target.value)}
                                            className="min-h-28"
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.budgetissuance)}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="outline" onClick={handleResetDefaults} disabled={processing}>
                                        Restaurar modelos
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        <Save />
                                        Salvar
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </TabsContent>

                    <TabsContent value="maintenance_contracts">
                        <div className="rounded-lg border p-4">
                            <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
                                <div className="grid gap-5">
                                    <div className="flex flex-col gap-3 border-b pb-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div className="flex items-start gap-3">
                                            <FileText className="mt-0.5 h-5 w-5" />
                                            <div>
                                                <h3 className="text-base font-semibold">Modelo de contrato de manutenção</h3>
                                                <p className="text-muted-foreground mt-1 text-sm">
                                                    Texto usado ao gerar o contrato para impressão a partir de um contrato de manutenção recorrente
                                                    cadastrado.
                                                </p>
                                            </div>
                                        </div>
                                        <div className="text-muted-foreground flex flex-wrap gap-1 text-xs">
                                            {[
                                                '{{ empresa }}',
                                                '{{ cnpj_empresa }}',
                                                '{{ cliente }}',
                                                '{{ cpf_cnpj }}',
                                                '{{ descricao }}',
                                                '{{ valor_mensalidade }}',
                                                '{{ dia_cobranca }}',
                                                '{{ data_inicio }}',
                                                '{{ vigencia }}',
                                            ].map((placeholder) => (
                                                <Badge key={placeholder} variant="outline" className="font-mono">
                                                    {placeholder}
                                                </Badge>
                                            ))}
                                        </div>
                                    </div>

                                    <div className="grid gap-2 rounded-md border p-3">
                                        <Label htmlFor="maintenance_contract_template" className="font-semibold">
                                            Cláusulas do contrato
                                        </Label>
                                        <Textarea
                                            id="maintenance_contract_template"
                                            value={data.maintenance_contract_template}
                                            onChange={(e) => setData('maintenance_contract_template', e.target.value)}
                                            className="min-h-56"
                                        />
                                        <div className="text-muted-foreground bg-muted/40 rounded-md p-2 text-xs whitespace-pre-wrap">
                                            Prévia: {renderPreview(data.maintenance_contract_template)}
                                        </div>
                                    </div>
                                </div>

                                <div className="flex justify-end gap-2">
                                    <Button type="button" variant="outline" onClick={handleResetContractTemplate} disabled={processing}>
                                        Restaurar modelo
                                    </Button>
                                    <Button type="submit" disabled={processing}>
                                        <Save />
                                        Salvar
                                    </Button>
                                </div>
                            </form>
                        </div>
                    </TabsContent>

                    <TabsContent value="checklists" className="space-y-4">
                        <div className="rounded-lg border p-4">
                            <div className="flex items-start gap-3">
                                <ClipboardCheck className="mt-0.5 h-5 w-5" />
                                <div>
                                    <h3 className="text-base font-semibold">Modelos de checklist</h3>
                                    <p className="text-muted-foreground mt-1 text-sm">Impressão de checklist para conferência.</p>
                                </div>
                            </div>
                        </div>

                        <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                                <InputSearch placeholder="Buscar checklist ou equipamento" url="app.receipts.index" />
                            </div>
                            <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                                {canManageChecklists && <CreateChecklist equipments={equipments} returnTo="receipts" />}
                            </div>
                        </div>

                        <PaginationSummary data={checklists} />
                        <div className="rounded-lg border">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead className="w-[100px]">#</TableHead>
                                        <TableHead>Equipamento</TableHead>
                                        <TableHead>Checklist</TableHead>
                                        <TableHead>Cadastro</TableHead>
                                        <TableHead className="min-w-[120px]"></TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {checklists?.data.length ? (
                                        checklists?.data?.map((checklist: any) => (
                                            <TableRow key={checklist.checklist_number}>
                                                <TableCell>{checklist.id}</TableCell>
                                                <TableCell className="font-medium">{checklist.equipment.equipment}</TableCell>
                                                <TableCell>
                                                    <div className="flex max-w-3xl flex-wrap gap-1.5">
                                                        {checklistItems(checklist.checklist).map((item) => (
                                                            <Badge key={item} variant="secondary" className="font-normal">
                                                                {item}
                                                            </Badge>
                                                        ))}
                                                    </div>
                                                </TableCell>
                                                <TableCell>{moment(checklist.created_at).format('DD/MM/YYYY')}</TableCell>
                                                <TableCell className="min-w-[120px]">
                                                    <div className="flex flex-wrap justify-end gap-2">
                                                        {canManageChecklists && (
                                                            <EditChecklist checklist={checklist} equipments={equipments} returnTo="receipts" />
                                                        )}
                                                        {canManageChecklists && (
                                                            <ActionDelete
                                                                title={'este checklist'}
                                                                url={'app.register-checklists.destroy'}
                                                                param={{ checklist: checklist.id, return_to: 'receipts' }}
                                                            />
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))
                                    ) : (
                                        <TableRow>
                                            <TableCell colSpan={5} className="h-16 text-center">
                                                Não há dados a serem mostrados no momento.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                </TableBody>
                                <TableFooter>
                                    <TableRow>
                                        <TableCell colSpan={5}>
                                            <AppPagination data={checklists} />
                                        </TableCell>
                                    </TableRow>
                                </TableFooter>
                            </Table>
                        </div>
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
