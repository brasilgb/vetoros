import ActionDelete from '@/components/action-delete';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { StatusBadge } from '@/components/StatusBadge';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { Pencil, Plus, UserCog } from 'lucide-react';
import moment from 'moment';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Usuários',
        href: '#',
    },
];

export default function Users({ users }: any) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Usuários" />
            <div className="mb-4 flex h-16 items-center justify-between px-4">
                <div className="flex items-center gap-2">
                    <Icon iconNode={UserCog} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Usuários</h2>
                </div>
            </div>

            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[360px] lg:flex-1">
                    <InputSearch placeholder="Buscar usuário" url="admin.users.index" />
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button variant={'default'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('admin.users.create')}>
                            <Plus className="h-4 w-4" />
                            <span>Usuário</span>
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="p-4">
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[100px]">#</TableHead>
                                <TableHead>Empresa</TableHead>
                                <TableHead>Nome</TableHead>
                                <TableHead>Telefone</TableHead>
                                <TableHead>E-mail</TableHead>
                                <TableHead>Função</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {users?.data.length > 0 ? (
                                users?.data?.map((user: any) => (
                                    <TableRow key={user.id}>
                                        <TableCell>{user.id}</TableCell>
                                        <TableCell className="font-medium">{user?.tenant?.company}</TableCell>
                                        <TableCell className="font-medium">{user.name}</TableCell>
                                        <TableCell className="font-medium">{user.telephone}</TableCell>
                                        <TableCell className="font-medium">{user.email}</TableCell>
                                        <TableCell>{<StatusBadge category="role" value={user.roles} />}</TableCell>
                                        <TableCell>{<StatusBadge category="userStatus" value={user.status} />}</TableCell>
                                        <TableCell>{moment(user.created_at).format('DD/MM/YYYY')}</TableCell>
                                        <TableCell className="flex justify-end gap-2">
                                            <Button
                                                asChild
                                                size="icon"
                                                className="bg-orange-500 text-white hover:bg-orange-600"
                                                title="Editar usuário"
                                            >
                                                <Link href={route('admin.users.edit', user.id)}>
                                                    <Pencil className="h-4 w-4" />
                                                </Link>
                                            </Button>
                                            <ActionDelete title={'este usuário'} url={'admin.users.destroy'} param={user.id} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={7} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        {users?.data.length > users?.total && (
                            <TableFooter>
                                <TableRow>
                                    <TableCell colSpan={7}>
                                        <AppPagination data={users} />
                                    </TableCell>
                                </TableRow>
                            </TableFooter>
                        )}
                    </Table>
                </div>
            </div>
        </AdminLayout>
    );
}
