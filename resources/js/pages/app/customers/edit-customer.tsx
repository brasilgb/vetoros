import { Icon } from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Tabs, TabsContent, TabsList, TabsTrigger } from '@/components/ui/tabs';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem, Customer } from '@/types';
import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Users2 } from 'lucide-react';
import { CustomerEquipment } from './customer-equipment-form';
import CustomerEquipmentsTab from './customer-equipments-tab';
import CustomerForm from './customer-form';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Clientes',
        href: route('app.customers.index'),
    },
    {
        title: 'Editar',
        href: '#',
    },
];

export default function EditCustomer({
    customer,
    equipments,
    equipmentTypes,
    page,
    search,
}: {
    customer: Customer;
    equipments?: CustomerEquipment[];
    equipmentTypes: { id: number; equipment: string }[];
    page?: string;
    search?: string;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Clientes" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Users2} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Clientes</h2>
                </div>
            </div>

            <div className="flex items-center justify-between p-4">
                <div>
                    <Button variant={'default'} asChild>
                        <Link href={route('app.customers.index', { page: page, search: search })}>
                            <ArrowLeft className="h-4 w-4" />
                            <span>Voltar</span>
                        </Link>
                    </Button>
                </div>
                <div></div>
            </div>

            <div className="p-4">
                <Tabs defaultValue="details" className="space-y-4">
                    <TabsList>
                        <TabsTrigger value="details">Dados</TabsTrigger>
                        <TabsTrigger value="equipments">Equipamentos</TabsTrigger>
                    </TabsList>
                    <TabsContent value="details">
                        <div className="rounded-lg border p-2">
                            <CustomerForm initialData={customer} />
                        </div>
                    </TabsContent>
                    <TabsContent value="equipments">
                        <CustomerEquipmentsTab customerId={customer.id} equipments={equipments ?? []} equipmentTypes={equipmentTypes} />
                    </TabsContent>
                </Tabs>
            </div>
        </AppLayout>
    );
}
