import ActionDelete from '@/components/action-delete';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Button } from '@/components/ui/button';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { BreadcrumbItem } from '@/types';
import { maskCnpj, maskPhone } from '@/Utils/mask';
import { Head, Link } from '@inertiajs/react';
import { Building, Edit, Plus } from 'lucide-react';
import moment from 'moment';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Filiais',
        href: '#',
    },
];

export default function BranchesIndex({ branches }: any) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Filiais" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Building} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Filiais</h2>
                </div>
            </div>
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[360px] lg:flex-1">
                    <InputSearch placeholder="Buscar filial" url="admin.branches.index" />
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button variant="default" asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('admin.branches.create')}>
                            <Plus className="h-4 w-4" />
                            Empresa
                        </Link>
                    </Button>
                </div>
            </div>

            <div className="p-4">
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Nome</TableHead>
                                <TableHead>CNPJ</TableHead>
                                <TableHead>E-mail</TableHead>
                                <TableHead>Telefone</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {branches?.data.length > 0 ? (
                                branches?.data?.map((branche: any) => (
                                    <TableRow key={branche.id}>
                                        <TableCell>{branche.id}</TableCell>
                                        <TableCell>{branche.name}</TableCell>
                                        <TableCell>{maskCnpj(branche.branch_cnpj) || '-'}</TableCell>
                                        <TableCell>{branche.contact_email}</TableCell>
                                        <TableCell>{maskPhone(branche.contact_phone)}</TableCell>
                                        <TableCell>{moment(branche.created_at).format('DD/MM/YYYY')}</TableCell>
                                        <TableCell className="flex justify-end gap-2">
                                            <Button
                                                asChild
                                                size="icon"
                                                className="bg-orange-500 text-white hover:bg-orange-600"
                                                title="Editar filial"
                                            >
                                                <Link href={route('admin.branches.edit', branche.id)}>
                                                    <Edit />
                                                </Link>
                                            </Button>

                                            <ActionDelete title={'esta empresa'} url={'admin.branches.destroy'} param={branche.id} />
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
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={8}>
                                    <AppPagination data={branches} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AdminLayout>
    );
}
