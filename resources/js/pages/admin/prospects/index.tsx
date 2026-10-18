import ActionDelete from '@/components/action-delete';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { maskPhone } from '@/Utils/mask';
import { Head, router, useForm } from '@inertiajs/react';
import { Mail, MessageCircle, Pencil, Plus, Search, UserRoundSearch } from 'lucide-react';
import moment from 'moment';
import { FormEvent, useState } from 'react';

type Prospect = {
    id: number;
    name: string;
    whatsapp: string;
    email: string;
    source: 'landing' | 'manual';
    status: string;
    notes?: string | null;
    last_contact_at?: string | null;
    created_at: string;
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: route('admin.dashboard') },
    { title: 'Prospects', href: route('admin.prospects.index') },
];

const statusLabels: Record<string, string> = {
    new: 'Novo',
    contacted: 'Contatado',
    qualified: 'Qualificado',
    converted: 'Convertido',
    lost: 'Perdido',
};

const statusClasses: Record<string, string> = {
    new: 'border-sky-200 bg-sky-50 text-sky-700',
    contacted: 'border-amber-200 bg-amber-50 text-amber-700',
    qualified: 'border-violet-200 bg-violet-50 text-violet-700',
    converted: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    lost: 'border-rose-200 bg-rose-50 text-rose-700',
};

function ProspectForm({ prospect, statuses, trigger }: { prospect?: Prospect; statuses: string[]; trigger: React.ReactNode }) {
    const [open, setOpen] = useState(false);
    const form = useForm({
        name: prospect?.name ?? '',
        whatsapp: prospect?.whatsapp ?? '',
        email: prospect?.email ?? '',
        status: prospect?.status ?? 'new',
        notes: prospect?.notes ?? '',
    });

    const submit = (event: FormEvent) => {
        event.preventDefault();
        const options = { preserveScroll: true, onSuccess: () => setOpen(false) };

        if (prospect) {
            form.patch(route('admin.prospects.update', prospect.id), options);
            return;
        }

        form.post(route('admin.prospects.store'), options);
    };

    return (
        <Dialog open={open} onOpenChange={setOpen}>
            <DialogTrigger asChild>{trigger}</DialogTrigger>
            <DialogContent className="sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>{prospect ? 'Editar prospect' : 'Adicionar prospect'}</DialogTitle>
                    <DialogDescription>Registre os dados e a situação atual do contato comercial.</DialogDescription>
                </DialogHeader>
                <form onSubmit={submit} className="space-y-4">
                    <div className="grid gap-2">
                        <Label htmlFor={`name-${prospect?.id ?? 'new'}`}>Nome</Label>
                        <Input
                            id={`name-${prospect?.id ?? 'new'}`}
                            value={form.data.name}
                            onChange={(event) => form.setData('name', event.target.value)}
                        />
                        <InputError message={form.errors.name} />
                    </div>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor={`whatsapp-${prospect?.id ?? 'new'}`}>WhatsApp</Label>
                            <Input
                                id={`whatsapp-${prospect?.id ?? 'new'}`}
                                value={maskPhone(form.data.whatsapp)}
                                onChange={(event) => form.setData('whatsapp', event.target.value)}
                                maxLength={15}
                            />
                            <InputError message={form.errors.whatsapp} />
                        </div>
                        <div className="grid gap-2">
                            <Label htmlFor={`email-${prospect?.id ?? 'new'}`}>E-mail</Label>
                            <Input
                                id={`email-${prospect?.id ?? 'new'}`}
                                type="email"
                                value={form.data.email}
                                onChange={(event) => form.setData('email', event.target.value)}
                            />
                            <InputError message={form.errors.email} />
                        </div>
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`status-${prospect?.id ?? 'new'}`}>Status</Label>
                        <select
                            id={`status-${prospect?.id ?? 'new'}`}
                            value={form.data.status}
                            onChange={(event) => form.setData('status', event.target.value)}
                            className="border-input bg-background h-10 rounded-md border px-3 text-sm"
                        >
                            {statuses.map((status) => (
                                <option key={status} value={status}>
                                    {statusLabels[status]}
                                </option>
                            ))}
                        </select>
                        <InputError message={form.errors.status} />
                    </div>
                    <div className="grid gap-2">
                        <Label htmlFor={`notes-${prospect?.id ?? 'new'}`}>Observações</Label>
                        <Textarea
                            id={`notes-${prospect?.id ?? 'new'}`}
                            rows={4}
                            value={form.data.notes}
                            onChange={(event) => form.setData('notes', event.target.value)}
                            placeholder="Registre interesses, dúvidas e próximos passos."
                        />
                        <InputError message={form.errors.notes} />
                    </div>
                    <Button type="submit" disabled={form.processing} className="w-full">
                        {form.processing ? 'Salvando...' : 'Salvar prospect'}
                    </Button>
                </form>
            </DialogContent>
        </Dialog>
    );
}

export default function ProspectsIndex({
    prospects,
    statuses,
    filters,
}: {
    prospects: any;
    statuses: string[];
    filters: { search?: string; status?: string };
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [status, setStatus] = useState(filters.status ?? '');

    const filter = (event: FormEvent) => {
        event.preventDefault();
        router.get(route('admin.prospects.index'), { search, status }, { preserveState: true, replace: true });
    };

    const registerContact = (prospect: Prospect, url: string) => {
        window.open(url, '_blank', 'noopener,noreferrer');
        router.patch(route('admin.prospects.contact', prospect.id), {}, { preserveScroll: true });
    };

    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Prospects" />
            <div className="flex min-h-16 items-center gap-2 px-4 py-3">
                <Icon iconNode={UserRoundSearch} className="h-8 w-8" />
                <div>
                    <h2 className="text-xl font-semibold tracking-tight">Prospects</h2>
                    <p className="text-muted-foreground text-sm">Gerencie oportunidades e contatos comerciais.</p>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <form onSubmit={filter} className="flex w-full flex-col gap-2 sm:flex-row lg:max-w-2xl">
                    <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Buscar por nome, WhatsApp ou e-mail" />
                    <select
                        value={status}
                        onChange={(event) => setStatus(event.target.value)}
                        className="border-input bg-background h-10 rounded-md border px-3 text-sm sm:w-44"
                    >
                        <option value="">Todos os status</option>
                        {statuses.map((item) => (
                            <option key={item} value={item}>
                                {statusLabels[item]}
                            </option>
                        ))}
                    </select>
                    <Button type="submit" variant="outline">
                        <Search className="h-4 w-4" /> Filtrar
                    </Button>
                </form>
                <ProspectForm
                    statuses={statuses}
                    trigger={
                        <Button>
                            <Plus className="h-4 w-4" /> Prospect
                        </Button>
                    }
                />
            </div>

            <div className="p-4 pt-0">
                <div className="overflow-hidden rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Prospect</TableHead>
                                <TableHead>Contato</TableHead>
                                <TableHead>Origem</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Último contato</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead className="text-right">Ações</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {prospects.data.length ? (
                                prospects.data.map((prospect: Prospect) => {
                                    const phone = prospect.whatsapp.replace(/\D/g, '');
                                    const whatsapp = phone.length <= 11 ? `55${phone}` : phone;
                                    return (
                                        <TableRow key={prospect.id}>
                                            <TableCell>
                                                <div className="font-medium">{prospect.name}</div>
                                                {prospect.notes && (
                                                    <div className="text-muted-foreground max-w-52 truncate text-xs" title={prospect.notes}>
                                                        {prospect.notes}
                                                    </div>
                                                )}
                                            </TableCell>
                                            <TableCell>
                                                <div>{maskPhone(prospect.whatsapp)}</div>
                                                <div className="text-muted-foreground text-xs">{prospect.email}</div>
                                            </TableCell>
                                            <TableCell>{prospect.source === 'manual' ? 'Manual' : 'Landing page'}</TableCell>
                                            <TableCell>
                                                <Badge variant="outline" className={statusClasses[prospect.status]}>
                                                    {statusLabels[prospect.status]}
                                                </Badge>
                                            </TableCell>
                                            <TableCell>
                                                {prospect.last_contact_at
                                                    ? moment(prospect.last_contact_at).format('DD/MM/YYYY HH:mm')
                                                    : 'Ainda não contatado'}
                                            </TableCell>
                                            <TableCell>{moment(prospect.created_at).format('DD/MM/YYYY')}</TableCell>
                                            <TableCell>
                                                <div className="flex justify-end gap-1">
                                                    <Button
                                                        size="icon"
                                                        variant="outline"
                                                        title="Conversar pelo WhatsApp"
                                                        onClick={() =>
                                                            registerContact(
                                                                prospect,
                                                                `https://wa.me/${whatsapp}?text=${encodeURIComponent(`Olá, ${prospect.name}! Gostaria de conversar sobre o VetorOS.`)}`,
                                                            )
                                                        }
                                                    >
                                                        <MessageCircle className="h-4 w-4 text-emerald-600" />
                                                    </Button>
                                                    <Button
                                                        size="icon"
                                                        variant="outline"
                                                        title="Enviar e-mail"
                                                        onClick={() =>
                                                            registerContact(
                                                                prospect,
                                                                `mailto:${prospect.email}?subject=${encodeURIComponent('Conheça o VetorOS')}`,
                                                            )
                                                        }
                                                    >
                                                        <Mail className="h-4 w-4" />
                                                    </Button>
                                                    <ProspectForm
                                                        prospect={prospect}
                                                        statuses={statuses}
                                                        trigger={
                                                            <Button size="icon" variant="outline" title="Editar">
                                                                <Pencil className="h-4 w-4" />
                                                            </Button>
                                                        }
                                                    />
                                                    <ActionDelete title="este prospect" url="admin.prospects.destroy" param={prospect.id} />
                                                </div>
                                            </TableCell>
                                        </TableRow>
                                    );
                                })
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={7} className="h-24 text-center">
                                        Nenhum prospect encontrado.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={7}>
                                    <AppPagination data={prospects} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AdminLayout>
    );
}
