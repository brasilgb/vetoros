import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuSeparator, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { buildMessage } from '@/components/WhatsAppButtonProps';
import AppLayout from '@/layouts/app-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { isValidWhatsappPhone, maskCpfCnpj, maskPhone, unMask } from '@/Utils/mask';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { Calendar, Edit, Loader2, MoreHorizontal, Plus, Send, Trash2, Upload, Users2, Wrench } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';
import ImportCustomersModal from './import-customers-modal';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Clientes',
        href: '#',
    },
];

type CustomerWhatsappOption = {
    key: string;
    label: string;
    order_id?: number;
    order_number?: string;
    context?: 'default' | 'budget_follow_up' | 'pending_payment';
    status?: number;
    feedback?: boolean;
    amount_due?: number;
    days_pending?: number;
};

const DEFAULT_WHATSAPP_OPTION: CustomerWhatsappOption = { key: 'default', label: 'Mensagem padrão', context: 'default' };

export default function Customers({ customers, search, pending, whats }: any) {
    const { auth } = usePage().props as any;
    const [modalAberto, setModalAberto] = useState(false);
    const [deleteCustomer, setDeleteCustomer] = useState<{ id: number; name: string } | null>(null);
    const [sendingWhatsappId, setSendingWhatsappId] = useState<number | null>(null);
    const [selectedWhatsappOption, setSelectedWhatsappOption] = useState<Record<number, string>>({});
    const canManageCustomers = auth?.permissions?.includes('customers');

    // Mesma regra do backend: usa o WhatsApp do cliente e, se ele não for válido, o telefone.
    const whatsappNumberFor = (customer: any): string => (isValidWhatsappPhone(customer.whatsapp) ? customer.whatsapp : customer.phone);

    const whatsappOptionsFor = (customer: any): CustomerWhatsappOption[] => [
        DEFAULT_WHATSAPP_OPTION,
        ...((customer.whatsapp_options as CustomerWhatsappOption[] | undefined) ?? []),
    ];

    const handleSendWhatsapp = (customer: any) => {
        const options = whatsappOptionsFor(customer);
        const option = options.find((o) => o.key === (selectedWhatsappOption[customer.id] ?? 'default')) ?? DEFAULT_WHATSAPP_OPTION;

        const message = buildMessage({
            customerName: customer.name,
            orderNumber: option.order_number,
            status: option.status,
            feedback: option.feedback,
            context: option.context,
            amountDue: option.amount_due,
            daysPending: option.days_pending,
            whats,
        });

        if (!message.trim()) return;

        router.post(
            route('app.customers.whatsapp.send', customer.id),
            { message },
            {
                preserveScroll: true,
                preserveState: true,
                onStart: () => setSendingWhatsappId(customer.id),
                onFinish: () => setSendingWhatsappId(null),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clientes" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Users2} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Clientes</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Pesquisar por nome, documento, telefone ou e-mail" url="app.customers.index" />
                </div>
                <div className="flex w-full flex-col gap-2 sm:flex-row lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button variant={pending === '1' ? 'default' : 'outline'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.customers.index', { search, pending: pending === '1' ? undefined : 1 })}>
                            {pending === '1' ? 'Mostrar todos' : 'Com saldo pendente'}
                        </Link>
                    </Button>
                    {canManageCustomers && (
                        <Button onClick={() => setModalAberto(true)} className="w-full bg-green-600 text-white hover:bg-green-700 sm:w-auto">
                            <Upload className="h-4 w-4" />
                            <span>CSV</span>
                        </Button>
                    )}
                    {canManageCustomers && (
                        <Button variant={'default'} asChild className="w-full whitespace-nowrap sm:w-auto">
                            <Link href={route('app.customers.create')}>
                                <Plus className="h-4 w-4" />
                                <span>Novo cliente</span>
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <div className="p-4">
                <ImportCustomersModal isOpen={modalAberto} onClose={() => setModalAberto(false)} />
                <PaginationSummary data={customers} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Cliente</TableHead>
                                <TableHead>Contato</TableHead>
                                <TableHead>Saldo pendente</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead className="min-w-[140px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {customers?.data.length > 0 ? (
                                customers?.data?.map((customer: any) => (
                                    <TableRow key={customer.id}>
                                        <TableCell>{customer.customer_number}</TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="font-medium">{customer.name}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {maskCpfCnpj(unMask(customer.cpfcnpj) ?? '') || 'CPF/CNPJ não informado'}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="text-sm">{maskPhone(unMask(customer.phone) ?? '') || 'Telefone não informado'}</div>
                                                <div className="text-muted-foreground text-xs">{customer.email || 'E-mail não informado'}</div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {Number(customer.pending_amount || 0) > 0 ? (
                                                <Badge variant="destructive">{formatBRL(Number(customer.pending_amount || 0))}</Badge>
                                            ) : (
                                                <Badge variant="secondary">Sem pendência</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{moment(customer.created_at).format('DD/MM/YYYY')}</TableCell>
                                        <TableCell className="min-w-[140px]">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                <Popover>
                                                    <PopoverTrigger asChild>
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            className="bg-green-500 text-white hover:bg-green-500 disabled:cursor-not-allowed disabled:opacity-40"
                                                            title="Enviar WhatsApp"
                                                            aria-label={`Enviar WhatsApp para ${customer.name}`}
                                                            disabled={!isValidWhatsappPhone(whatsappNumberFor(customer)) || sendingWhatsappId === customer.id}
                                                        >
                                                            {sendingWhatsappId === customer.id ? (
                                                                <Loader2 className="h-4 w-4 animate-spin" />
                                                            ) : (
                                                                <svg
                                                                    xmlns="http://www.w3.org/2000/svg"
                                                                    width="16"
                                                                    height="16"
                                                                    fill="currentColor"
                                                                    className="bi bi-whatsapp"
                                                                    viewBox="0 0 16 16"
                                                                >
                                                                    <path d="M13.601 2.326A7.85 7.85 0 0 0 7.994 0C3.627 0 .068 3.558.064 7.926c0 1.399.366 2.76 1.057 3.965L0 16l4.204-1.102a7.9 7.9 0 0 0 3.79.965h.004c4.368 0 7.926-3.558 7.93-7.93A7.9 7.9 0 0 0 13.6 2.326zM7.994 14.521a6.6 6.6 0 0 1-3.356-.92l-.24-.144-2.494.654.666-2.433-.156-.251a6.56 6.56 0 0 1-1.007-3.505c0-3.626 2.957-6.584 6.591-6.584a6.56 6.56 0 0 1 4.66 1.931 6.56 6.56 0 0 1 1.928 4.66c-.004 3.639-2.961 6.592-6.592 6.592m3.615-4.934c-.197-.099-1.17-.578-1.353-.646-.182-.065-.315-.099-.445.099-.133.197-.513.646-.627.775-.114.133-.232.148-.43.05-.197-.1-.836-.308-1.592-.985-.59-.525-.985-1.175-1.103-1.372-.114-.198-.011-.304.088-.403.087-.088.197-.232.296-.346.1-.114.133-.198.198-.33.065-.134.034-.248-.015-.347-.05-.099-.445-1.076-.612-1.47-.16-.389-.323-.335-.445-.34-.114-.007-.247-.007-.38-.007a.73.73 0 0 0-.529.247c-.182.198-.691.677-.691 1.654s.71 1.916.81 2.049c.098.133 1.394 2.132 3.383 2.992.47.205.84.326 1.129.418.475.152.904.129 1.246.08.38-.058 1.171-.48 1.338-.943.164-.464.164-.86.114-.943-.049-.084-.182-.133-.38-.232" />
                                                                </svg>
                                                            )}
                                                        </Button>
                                                    </PopoverTrigger>
                                                    <PopoverContent align="end" className="w-72 space-y-3">
                                                        <div className="space-y-1">
                                                            <p className="text-sm font-medium">Enviar WhatsApp</p>
                                                            <p className="text-muted-foreground text-xs">Escolha a mensagem para {customer.name}.</p>
                                                        </div>
                                                        <Select
                                                            value={selectedWhatsappOption[customer.id] ?? 'default'}
                                                            onValueChange={(value) =>
                                                                setSelectedWhatsappOption((prev) => ({ ...prev, [customer.id]: value }))
                                                            }
                                                        >
                                                            <SelectTrigger className="w-full">
                                                                <SelectValue />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                {whatsappOptionsFor(customer).map((option) => (
                                                                    <SelectItem key={option.key} value={option.key}>
                                                                        {option.label}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                        <Button
                                                            type="button"
                                                            className="w-full"
                                                            disabled={sendingWhatsappId === customer.id}
                                                            onClick={() => handleSendWhatsapp(customer)}
                                                        >
                                                            {sendingWhatsappId === customer.id ? (
                                                                <Loader2 className="h-4 w-4 animate-spin" />
                                                            ) : (
                                                                <Send className="h-4 w-4" />
                                                            )}
                                                            Enviar
                                                        </Button>
                                                    </PopoverContent>
                                                </Popover>
                                                {canManageCustomers && (
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        title="Editar cliente"
                                                    >
                                                        <Link
                                                            href={route('app.customers.edit', customer.id)}
                                                            data={{ page: customers.current_page, search: search }}
                                                            aria-label={`Editar cliente ${customer.name}`}
                                                        >
                                                            <Edit className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                )}

                                                <DropdownMenu>
                                                    <DropdownMenuTrigger asChild>
                                                        <Button
                                                            type="button"
                                                            size="icon"
                                                            variant="outline"
                                                            title="Mais ações"
                                                            aria-label={`Mais ações de ${customer.name}`}
                                                        >
                                                            <MoreHorizontal className="h-4 w-4" />
                                                        </Button>
                                                    </DropdownMenuTrigger>
                                                    <DropdownMenuContent align="end" className="w-52">
                                                        <DropdownMenuItem asChild>
                                                            <Link href={route('app.schedules.index', { search: customer.name })}>
                                                                <Calendar />
                                                                Ver agendamentos
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        <DropdownMenuItem asChild>
                                                            <Link href={route('app.orders.index', { search: customer.name })}>
                                                                <Wrench />
                                                                Ver ordens
                                                            </Link>
                                                        </DropdownMenuItem>
                                                        {canManageCustomers && (
                                                            <>
                                                                <DropdownMenuSeparator />
                                                                <DropdownMenuItem
                                                                    variant="destructive"
                                                                    onSelect={() => setDeleteCustomer({ id: customer.id, name: customer.name })}
                                                                >
                                                                    <Trash2 />
                                                                    Excluir cliente
                                                                </DropdownMenuItem>
                                                            </>
                                                        )}
                                                    </DropdownMenuContent>
                                                </DropdownMenu>
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={6} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={6}>
                                    <AppPagination data={customers} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
            <AlertDialog open={deleteCustomer !== null} onOpenChange={(open) => !open && setDeleteCustomer(null)}>
                <AlertDialogContent>
                    <AlertDialogHeader>
                        <AlertDialogTitle>Excluir {deleteCustomer?.name}?</AlertDialogTitle>
                        <AlertDialogDescription>
                            Esta ação não pode ser desfeita e removerá permanentemente os dados associados ao cliente.
                        </AlertDialogDescription>
                    </AlertDialogHeader>
                    <AlertDialogFooter>
                        <AlertDialogCancel>Cancelar</AlertDialogCancel>
                        <AlertDialogAction
                            className="bg-red-600 text-white hover:bg-red-700"
                            onClick={() => {
                                if (!deleteCustomer) return;
                                router.delete(route('app.customers.destroy', deleteCustomer.id), {
                                    preserveScroll: true,
                                    onFinish: () => setDeleteCustomer(null),
                                });
                            }}
                        >
                            Excluir
                        </AlertDialogAction>
                    </AlertDialogFooter>
                </AlertDialogContent>
            </AlertDialog>
        </AppLayout>
    );
}
