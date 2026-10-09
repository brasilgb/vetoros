import type { PaginationData } from '@/components/app-pagination';
import { Icon } from '@/components/icon';
import { Link } from '@inertiajs/react';
import { Landmark } from 'lucide-react';

const tabs = [
    { label: 'Integração Spedy', route: 'admin.fiscal.integration' },
    { label: 'Empresas emissoras', route: 'admin.fiscal.companies.index' },
    { label: 'Monitoramento', route: 'admin.fiscal.monitoring' },
    { label: 'Notas do SaaS', route: 'admin.fiscal.saas.index' },
];

export function FiscalHeader({ current }: { current: string }) {
    return (
        <div className="space-y-3 px-4 pt-3">
            <div className="flex items-center gap-2">
                <Icon iconNode={Landmark} className="h-8 w-8" />
                <h2 className="text-xl font-semibold tracking-tight">Fiscal</h2>
            </div>
            <nav className="flex flex-wrap gap-2 border-b pb-2">
                {tabs.map((tab) => (
                    <Link
                        key={tab.route}
                        href={route(tab.route)}
                        className={`rounded-md px-3 py-1.5 text-sm font-medium ${
                            current === tab.route ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'
                        }`}
                    >
                        {tab.label}
                    </Link>
                ))}
            </nav>
        </div>
    );
}

export const statusLabels: Record<string, string> = {
    processing: 'Em processamento',
    contingency: 'Em contingência',
    authorized: 'Autorizada',
    rejected: 'Rejeitada',
    denied: 'Denegada',
    cancelled: 'Cancelada',
    failed: 'Falhou',
    pending: 'Pendente',
    registered: 'Cadastrada',
    error: 'Erro',
};

export function formatDate(value?: string | null) {
    return value ? new Date(value).toLocaleDateString('pt-BR') : '-';
}

export function money(value: number | string) {
    return new Intl.NumberFormat('pt-BR', { style: 'currency', currency: 'BRL' }).format(Number(value || 0));
}

export type Paginated<T> = PaginationData & { data: T[] };

export type TenantOption = { id: number; company: string | null; name?: string | null; cnpj?: string | null };

export type AuditRow = {
    id: number;
    action: string;
    created_at: string;
    user?: { name: string } | null;
    tenant?: { company: string | null } | null;
};

export type MonitoringProps = {
    filters: { from: string; to: string; tenant_id: number | null; model: string | null };
    tenants: TenantOption[];
    summary: Record<'total' | 'authorized' | 'rejected' | 'failed' | 'cancelled' | 'pending', number>;
    byTenant: { tenant_id: number; tenant: string; type: string; total: number; authorized: number; failures: number }[];
    usage: { month: string; type: string; total: number }[];
    failures: { id: number; tenant: string; type: string; status: string; error_message: string | null; created_at: string | null }[];
    pending: { id: number; tenant: string; type: string; status: string; confirmed_by_provider: boolean; submitted_at: string | null }[];
    history: AuditRow[];
    webhookEvents: { total: number | null; unprocessed: number | null } | null;
};

export type SaasDocumentRef = { id: number; status: string; number: string | null };

export type SaasPayment = {
    id: number;
    amount: number;
    paid_at: string;
    plan_name: string | null;
    suggested_start: string;
    suggested_end: string | null;
    documents: SaasDocumentRef[];
};

export type SaasDocument = {
    id: number;
    tenant: string | null;
    status: string;
    number: string | null;
    environment: string | null;
    error_message: string | null;
    amount: number;
    reference_start: string | null;
    reference_end: string | null;
    can_cancel: boolean;
    has_file: boolean;
    deliveries: { email: string; status: string; error: string | null; created_at: string | null }[];
};

export type SaasIssuer = Record<string, string | number | boolean | null | string[]> & {
    problems: string[];
    blocker: string | null;
    registration_status: string | null;
    certificate_expires_at: string | null;
    tax_settings_confirmed_at: string | null;
    production_released_at: string | null;
};

/** Tomador da NFS-e do SaaS exatamente como vai para a Spedy. */
export type SaasReceiver = {
    name: string;
    federal_tax_number: string;
    email: string | null;
    address: {
        street?: string;
        number?: string;
        district?: string;
        postalCode?: string;
        city?: { name?: string; state?: string };
    } | null;
    problems: string[];
    identity_changed_at: string | null;
    identity_changed_by: string | null;
    identity_changes: string[];
};

export type SaasProps = {
    issuer: SaasIssuer;
    tenants: TenantOption[];
    selectedTenant: {
        id: number;
        name: string;
        plan: string | null;
        period: string | null;
        subscription_status: string | null;
        expires_at: string | null;
        receiver_problems: string[];
        receiver: SaasReceiver;
    } | null;
    payments: SaasPayment[];
    documents: SaasDocument[];
};
