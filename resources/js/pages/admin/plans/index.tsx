import ActionDelete from '@/components/action-delete';
import AppPagination from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { formatBRL } from '@/lib/money';
import { BreadcrumbItem } from '@/types';
import { Head } from '@inertiajs/react';
import { Copyright } from 'lucide-react';
import moment from 'moment';
import CreatePlan from './create-plan';
import EditPlan from './edit-plan';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
    },
    {
        title: 'Planos',
        href: '#',
    },
];

function formatBillingCycle(months?: number | string) {
    const value = Number(months || 1);

    if (value === 14) {
        return 'Trial 14 dias';
    }

    return `${value} ${value === 1 ? 'mês' : 'meses'}`;
}

export default function PlansIndex({ plans }: any) {
    return (
        <AdminLayout breadcrumbs={breadcrumbs}>
            <Head title="Planos" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Copyright} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Planos</h2>
                </div>
            </div>
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="w-full min-w-0 lg:max-w-[360px] lg:flex-1">
                    <InputSearch placeholder="Buscar plano" url="admin.plans.index" />
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    <CreatePlan />
                </div>
            </div>

            <div className="p-4">
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>#</TableHead>
                                <TableHead>Nome</TableHead>
                                <TableHead>Slug</TableHead>
                                <TableHead>Descrição</TableHead>
                                <TableHead>Valor</TableHead>
                                <TableHead>Ciclo</TableHead>
                                <TableHead>Contratação</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {plans?.data.length > 0 ? (
                                plans?.data?.map((plan: any) => (
                                    <TableRow key={plan.id}>
                                        <TableCell>{plan.id}</TableCell>
                                        <TableCell>{plan.name}</TableCell>
                                        <TableCell>{plan.slug}</TableCell>
                                        <TableCell>{plan.description}</TableCell>
                                        <TableCell>{formatBRL(plan.value)}</TableCell>
                                        <TableCell>{formatBillingCycle(plan.billing_months)}</TableCell>
                                        <TableCell>{!plan.is_active ? 'Inativo' : plan.is_public ? 'Público' : 'Privado'}</TableCell>
                                        <TableCell>{moment(plan.created_at).format('DD/MM/YYYY')}</TableCell>
                                        <TableCell className="flex justify-end gap-2">
                                            <EditPlan plan={plan} />
                                            <ActionDelete title={'este plano'} url={'admin.plans.destroy'} param={plan.id} />
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={9} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={9}>
                                    <AppPagination data={plans} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AdminLayout>
    );
}
