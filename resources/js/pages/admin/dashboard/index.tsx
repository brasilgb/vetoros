import { KpiDashboard } from '@/components/kpi-dashboard';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AdminLayout from '@/layouts/admin/admin-layout';
import { Head, Link } from '@inertiajs/react';
import { AlertTriangle, Building, Clock3, ShieldAlert, User } from 'lucide-react';
import moment from 'moment';

interface DashboardMetrics {
    users?: Array<unknown>;
    companies?: Array<unknown>;
    subscription_kpis?: {
        active?: number;
        grace?: number;
        blocked?: number;
        expires_today?: number;
        expires_next_7_days?: number;
        without_plan?: number;
        without_expiration?: number;
    };
    attention_tenants?: Array<{
        id: number;
        company: string;
        contact: string;
        plan: string;
        expires_at: string | null;
        attention_label: string;
    }>;
}

export default function Dashboard({ metrics }: { metrics: DashboardMetrics }) {
    const tenantsRoute = (subscriptionFilter?: string) =>
        route('admin.tenants.index', subscriptionFilter ? { subscription_filter: subscriptionFilter } : {});

    const getAttentionBadgeClassName = (attentionLabel: string) => {
        if (attentionLabel === 'Bloqueada' || attentionLabel === 'Sem plano') {
            return 'border-rose-200 bg-rose-50 text-rose-700';
        }

        if (attentionLabel === 'Em carencia' || attentionLabel === 'Vence hoje') {
            return 'border-amber-200 bg-amber-50 text-amber-700';
        }

        return 'border-sky-200 bg-sky-50 text-sky-700';
    };

    return (
        <AdminLayout>
            <Head title="Dashboard" />
            <div className="p-4">
                <div className="grid gap-4 rounded-xl md:grid-cols-2 xl:grid-cols-5">
                    <KpiDashboard
                        link={route('admin.users.index')}
                        title="Usuários"
                        value={metrics?.users?.length}
                        icon={<User className="h-10 w-10" />}
                        description="Usários administradores"
                    />
                    <KpiDashboard
                        link={tenantsRoute()}
                        title="Empresas"
                        value={metrics?.companies?.length}
                        icon={<Building className="h-10 w-10" />}
                        description="Todas as empresas cadastradas"
                    />
                    <KpiDashboard
                        link={tenantsRoute('active')}
                        title="Assinaturas Ativas"
                        value={metrics?.subscription_kpis?.active}
                        icon={<Clock3 className="h-10 w-10" />}
                        description="Empresas com assinatura vigente"
                    />
                    <KpiDashboard
                        link={tenantsRoute('grace')}
                        title="Em Carência"
                        value={metrics?.subscription_kpis?.grace}
                        icon={<AlertTriangle className="h-10 w-10" />}
                        description="Empresas vencidas dentro de 3 dias"
                    />
                    <KpiDashboard
                        link={tenantsRoute('blocked')}
                        title="Bloqueadas"
                        value={metrics?.subscription_kpis?.blocked}
                        icon={<ShieldAlert className="h-10 w-10" />}
                        description="Assinaturas bloqueadas ou vencidas"
                    />
                </div>

                <div className="mt-4 grid gap-4 rounded-xl md:grid-cols-2 xl:grid-cols-4">
                    <KpiDashboard
                        link={tenantsRoute('expires_today')}
                        title="Vencem Hoje"
                        value={metrics?.subscription_kpis?.expires_today}
                        icon={<AlertTriangle className="h-10 w-10" />}
                        description="Empresas com vencimento no dia"
                    />
                    <KpiDashboard
                        link={tenantsRoute('expires_next_7_days')}
                        title="Próximos 7 Dias"
                        value={metrics?.subscription_kpis?.expires_next_7_days}
                        icon={<Clock3 className="h-10 w-10" />}
                        description="Assinaturas que vencem em breve"
                    />
                    <KpiDashboard
                        link={tenantsRoute('without_plan')}
                        title="Sem Plano"
                        value={metrics?.subscription_kpis?.without_plan}
                        icon={<ShieldAlert className="h-10 w-10" />}
                        description="Empresas sem plano definido"
                    />
                    <KpiDashboard
                        link={tenantsRoute('without_expiration')}
                        title="Sem Vencimento"
                        value={metrics?.subscription_kpis?.without_expiration}
                        icon={<AlertTriangle className="h-10 w-10" />}
                        description="Empresas sem data de expiracao"
                    />
                </div>

                <div className="mt-6 rounded-xl border">
                    <div className="flex items-center justify-between border-b px-4 py-3">
                        <div>
                            <h2 className="text-base font-semibold">Empresas Com Atenção</h2>
                            <p className="text-muted-foreground text-sm">Vencimentos próximos, carência, bloqueio ou cadastro incompleto.</p>
                        </div>
                        <Link href={route('admin.tenants.index')} className="text-primary text-sm hover:underline">
                            Ver empresas
                        </Link>
                    </div>

                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>Empresa</TableHead>
                                <TableHead>Contato</TableHead>
                                <TableHead>Plano</TableHead>
                                <TableHead>Vencimento</TableHead>
                                <TableHead>Situação</TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {metrics?.attention_tenants?.length ? (
                                metrics.attention_tenants.map((tenant) => (
                                    <TableRow key={tenant.id}>
                                        <TableCell>
                                            <Link href={route('admin.tenants.edit', tenant.id)} className="font-medium hover:underline">
                                                {tenant.company}
                                            </Link>
                                        </TableCell>
                                        <TableCell>{tenant.contact}</TableCell>
                                        <TableCell>{tenant.plan}</TableCell>
                                        <TableCell>{tenant.expires_at ? moment(tenant.expires_at).format('DD/MM/YYYY') : 'Sem vencimento'}</TableCell>
                                        <TableCell>
                                            <Badge variant="outline" className={getAttentionBadgeClassName(tenant.attention_label)}>
                                                {tenant.attention_label}
                                            </Badge>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={5} className="text-muted-foreground h-16 text-center">
                                        Nenhuma empresa exige atenção no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                    </Table>
                </div>
            </div>
        </AdminLayout>
    );
}
