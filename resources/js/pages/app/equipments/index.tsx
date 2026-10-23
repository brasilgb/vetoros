import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, usePage } from '@inertiajs/react';
import { Monitor } from 'lucide-react';
import moment from 'moment';
import CreateEquipment from './create-equipment';
import EditEquipment from './edit-equipment';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Tipos de equipamento',
        href: '#',
    },
];

export default function Equipment({ equipments, search }: any) {
    const { auth } = usePage<{ auth?: { permissions?: string[] } }>().props;
    const canManageEquipments = auth?.permissions?.includes('register_equipments');

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Tipos de equipamento" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Monitor} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Tipos de equipamento</h2>
                </div>
            </div>
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                    <InputSearch placeholder="Buscar tipo de equipamento" url="app.register-equipments.index" />
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    {canManageEquipments && <CreateEquipment />}
                </div>
            </div>

            <div className="p-4">
                <PaginationSummary data={equipments} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-[100px]">#</TableHead>
                                <TableHead>Tipo de equipamento</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead className="min-w-[120px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {equipments?.data.length ? (
                                equipments?.data?.map((equipment: any) => (
                                    <TableRow key={equipment.id}>
                                        <TableCell>{equipment.equipment_number}</TableCell>
                                        <TableCell className="font-medium">{equipment.equipment}</TableCell>
                                        <TableCell>{moment(equipment.created_at).format('DD/MM/YYYY')}</TableCell>
                                        <TableCell className="min-w-[120px]">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {canManageEquipments && <EditEquipment equipment={equipment} />}
                                                {canManageEquipments && (
                                                    <ActionDelete
                                                        title={'este tipo de equipamento'}
                                                        url={'app.register-equipments.destroy'}
                                                        param={equipment.id}
                                                    />
                                                )}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={5}>
                                    <AppPagination data={equipments} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
