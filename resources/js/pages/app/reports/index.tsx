import { DatePicker } from '@/components/date-picker';
import { Icon } from '@/components/icon';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { Head, usePage, useRemember } from '@inertiajs/react';
import { FileTextIcon } from 'lucide-react';
import CashierReport from './cashier-report';
import CustomersReport from './customers-report';
import ExpensesReport from './expenses-report';
import FiscalReport from './fiscal-report';
import OrdersStatistics from './order-statistics';
import OrdersDaily from './orders-daily';
import OrdersReport from './orders-report';
import PartsReport from './parts-report';
import QualityReport from './quality-report';
import SalesReport from './sales-report';
import SchedulesReport from './schedules-report';
import TechnicianProductivity from './technician-productivity';

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Relatórios',
        href: '#',
    },
];

export default function Reports() {
    const { company, auth, othersetting, fiscalSetting } = usePage().props as any;
    const [dateRange, setDateRange] = useRemember<any>(
        {
            from: new Date(),
            to: new Date(),
        },
        'reports-date-range',
    );
    const permissions = auth?.permissions ?? [];
    const canViewOrders = permissions.includes('orders');
    const canViewCustomers = permissions.includes('customers');
    const canViewSchedules = permissions.includes('schedules');
    const canViewSales = Boolean(permissions.includes('sales') && othersetting?.enablesales);
    const canViewExpenses = Boolean(permissions.includes('finance') && othersetting?.enable_finance);
    const canViewCashier = Boolean(permissions.includes('finance') && othersetting?.enable_finance);
    const canViewParts = permissions.includes('parts');
    const canViewFiscal = Boolean(permissions.includes('fiscal_documents') && fiscalSetting?.enabled);
    const canViewQuality = Boolean(permissions.includes('reports') && othersetting?.show_quality_menu);
    const hasOperationalReports = canViewOrders || canViewCustomers || canViewSchedules;
    const hasStockReports = canViewParts;
    const hasCommercialReports = canViewSales;
    const hasFinancialReports = canViewExpenses || canViewCashier;
    const hasFiscalReports = canViewFiscal;
    const hasManagementReports = canViewQuality;
    const hasAnyReport =
        hasOperationalReports || hasStockReports || hasCommercialReports || hasFinancialReports || hasFiscalReports || hasManagementReports;

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Relatórios" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={FileTextIcon} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Relatórios</h2>
                </div>
            </div>

            <div className="p-4">
                <Card>
                    <CardHeader>
                        <CardTitle>Relatórios por período</CardTitle>
                        <CardDescription>Escolha o intervalo e gere relatórios por área da operação.</CardDescription>
                    </CardHeader>
                    <CardContent className="flex justify-start">
                        <div className="w-full max-w-md">
                            <DatePicker mode={'range'} setDate={setDateRange} date={dateRange} />
                        </div>
                    </CardContent>
                    <CardContent className="space-y-8">
                        {!hasAnyReport && (
                            <div className="text-muted-foreground rounded-md border border-dashed p-4 text-sm">
                                Nenhum relatório disponível para o seu perfil.
                            </div>
                        )}

                        {hasOperationalReports && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold">Operação</h3>
                                    <p className="text-muted-foreground text-sm">Ordens, agenda, clientes e técnicos.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {canViewOrders && <TechnicianProductivity dateRange={dateRange} company={company} />}
                                    {canViewOrders && <OrdersReport dateRange={dateRange} company={company} />}
                                    {canViewOrders && <OrdersStatistics dateRange={dateRange} company={company} />}
                                    {canViewOrders && <OrdersDaily dateRange={dateRange} company={company} />}
                                    {canViewCustomers && <CustomersReport dateRange={dateRange} company={company} />}
                                    {canViewSchedules && <SchedulesReport dateRange={dateRange} company={company} />}
                                </div>
                            </section>
                        )}

                        {hasStockReports && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold">Estoque</h3>
                                    <p className="text-muted-foreground text-sm">Produtos, peças e movimentações de estoque.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {canViewParts && <PartsReport dateRange={dateRange} company={company} />}
                                </div>
                            </section>
                        )}

                        {hasCommercialReports && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold">Comercial</h3>
                                    <p className="text-muted-foreground text-sm">Vendas e desempenho do PDV.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {canViewSales && <SalesReport dateRange={dateRange} company={company} />}
                                </div>
                            </section>
                        )}

                        {hasFinancialReports && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold">Financeiro</h3>
                                    <p className="text-muted-foreground text-sm">Despesas e conferência de caixa da operação.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {canViewExpenses && <ExpensesReport dateRange={dateRange} company={company} />}
                                    {canViewCashier && <CashierReport dateRange={dateRange} company={company} />}
                                </div>
                            </section>
                        )}

                        {hasFiscalReports && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold">Fiscal</h3>
                                    <p className="text-muted-foreground text-sm">NF-e, NFS-e, registros manuais e integrações fiscais.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {canViewFiscal && <FiscalReport dateRange={dateRange} company={company} />}
                                </div>
                            </section>
                        )}

                        {hasManagementReports && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold">Gestão</h3>
                                    <p className="text-muted-foreground text-sm">Qualidade percebida pelo cliente e retornos em garantia.</p>
                                </div>
                                <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                                    {canViewQuality && <QualityReport dateRange={dateRange} company={company} />}
                                </div>
                            </section>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
