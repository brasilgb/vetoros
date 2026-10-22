import type { PageProps } from '@inertiajs/core';

export type ReportScalar = string | number | null | undefined;

export interface ReportPerson {
    id?: number | string;
    name?: string | null;
}

/** Union-shaped row fields shared by the report screens and their PDF exports. */
export interface ReportRow {
    id: number | string;
    name?: string | null;
    companyname?: string | null;
    logo?: string | null;
    order_number?: string | number | null;
    customer_number?: string | number | null;
    sales_number?: string | number | null;
    schedules_number?: string | number | null;
    expense_number?: string | number | null;
    expense_date?: string | null;
    description?: string | null;
    category?: string | null;
    amount?: number | string | null;
    created_at?: string | Date | null;
    updated_at?: string | Date | null;
    issued_at?: string | null;
    number?: string | number | null;
    provider?: string | null;
    type?: string | null;
    status?: string | number | null;
    documentable_type?: string | null;
    documentable_id?: number | null;
    registered_by?: ReportPerson | null;
    registeredBy?: ReportPerson | null;
    created_by?: ReportPerson | null;
    createdBy?: ReportPerson | null;
    customer?: ReportPerson | null;
    user?: ReportPerson | null;
    technician?: ReportPerson | null;
    equipment?: (ReportPerson & { equipment?: string | null }) | null;
    order?: ReportRow | null;
    model?: ReportScalar;
    service_status?: number;
    is_warranty_return?: boolean | number;
    parts_value?: number | string | null;
    service_value?: number | string | null;
    total_amount?: number | string | null;
    items_count?: number | null;
    payment_method?: string | null;
    email?: string | null;
    cpfcnpj?: string | null;
    phone?: string | null;
    reference_number?: string | null;
    manufacturer?: string | null;
    quantity?: number | string | null;
    minimum_stock_level?: number | string | null;
    cost_price?: number | string | null;
    sale_price?: number | string | null;
    schedules?: string | null;
    service?: string | null;
    customer_name?: string | null;
    opened_at?: string | null;
    closed_at?: string | null;
    opened_by?: ReportPerson | null;
    closed_by?: ReportPerson | null;
    opening_balance?: ReportScalar;
    closing_balance?: ReportScalar;
    expected_balance?: ReportScalar;
    difference?: ReportScalar;
    closing_notes?: ReportScalar;
    notes?: ReportScalar;
    manual_entries?: ReportScalar;
    manual_exits?: ReportScalar;
    total_cancelled_sales?: ReportScalar;
    total_completed_sales?: ReportScalar;
    total_order_payments?: ReportScalar;
    order_payments?: ReportRow[];
    sales?: ReportRow[];
    withdrawals?: ReportRow[];
    cancelled_at?: string | null;
    user_name?: ReportScalar;
    warranty_return_alert?: boolean;
    warranty_return_rate?: number;
    warranty_return_threshold?: number;
    expenses_total?: number;
    documents_count?: number;
    manual_count?: number;
    integration_count?: number;
    nfe_count?: number;
    nfse_count?: number;
    error_count?: number;
    sessions_count?: number;
    completed_sales_total?: number;
    difference_total?: number;
    order_payments_total?: number;
    withdrawals_total?: number;
    sales_by_method?: Record<string, number>;
    order_payments_by_method?: Record<string, number>;
    summary?: ReportSummary;
    top_defects?: ReportRankedValue[];
    top_equipments?: ReportRankedValue[];
    top_technicians?: ReportRankedValue[];
    low_feedback_orders?: ReportRow[];
    defect?: ReportScalar;
    totalOrdens?: number;
    totalServicos?: number;
    totalReturns?: number;
    ticketMedio?: number;
    entradas?: number;
    entregues?: number;
    concluidos?: number;
    date?: string;
}

export interface OrderDailyReportRow {
    id?: number | string;
    created_at: string;
    service_status: number;
    service_value?: number | string | null;
    parts_value?: number | string | null;
}

export interface ReportRankedValue {
    label?: string;
    name?: string;
    total?: number;
    count?: number;
}

export interface ReportSummary {
    total?: number;
    count?: number;
    average?: number;
    [key: string]: number | undefined;
}

export interface ReportMeta {
    warranty_return_alert?: boolean;
    warranty_return_rate?: number;
    warranty_return_threshold?: number;
    warranty_returns?: number;
    expenses_total?: number;
    documents_count?: number;
    manual_count?: number;
    integration_count?: number;
    nfe_count?: number;
    nfse_count?: number;
    error_count?: number;
    sessions_count?: number;
    opening_total?: number;
    completed_sales_total?: number;
    difference_total?: number;
    order_payments_total?: number;
    withdrawals_total?: number;
    expected_total?: number;
    closing_total?: number;
    manual_entries_total?: number;
    manual_exits_total?: number;
    sales_by_method?: Record<string, number>;
    order_payments_by_method?: Record<string, number>;
    low_feedback_orders?: ReportRow[];
    top_defects?: ReportRankedValue[];
    top_equipments?: ReportRankedValue[];
    top_technicians?: ReportRankedValue[];
    summary?: ReportSummary;
}

export interface ReportCompany {
    companyname?: string | null;
    logo?: string | null;
}

export interface ReportDateRange {
    from?: Date;
    to?: Date;
}

export interface ReportPDFProps {
    data: ReportRow[];
    reportMeta: ReportMeta;
    dateRange: ReportDateRange;
    company: ReportCompany | null;
}

export interface QualityReportPDFProps {
    data: ReportRow[];
    reportMeta: ReportMeta;
    dateRange: ReportDateRange;
    company: ReportCompany | null;
}

export interface CashierDailyReportProps {
    session: CashierReportSession;
    company: ReportCompany | null;
}

export interface CashierReportSession extends Omit<ReportRow, 'sales' | 'order_payments' | 'withdrawals'> {
    order_payments?: ReportPayment[];
    sales?: ReportSale[];
    withdrawals?: ReportWithdrawal[];
}

export interface ReportPayment extends ReportRow {
    amount?: number | string | null;
    payment_method?: string | null;
}

export interface ReportSale extends Omit<ReportRow, 'status'> {
    payment_method?: string | null;
    status?: string | null;
    total_amount?: number | string | null;
}

export interface ReportWithdrawal extends ReportRow {
    amount?: number | string | null;
    cancelled_at?: string | null;
    created_at?: string | null;
    description?: string | null;
    user?: ReportPerson | null;
}

export interface TechnicianReportStats {
    technician: string;
    totalOrdens: number;
    totalServicos: number;
    ticketMedio?: number;
}

export interface ReportsPageProps extends PageProps {
    company: ReportCompany | null;
    auth?: { permissions?: string[] };
    othersetting?: { enablesales?: boolean; enable_finance?: boolean; show_quality_menu?: boolean };
    fiscalSetting?: { enabled?: boolean };
}

export function readReportRows(value: unknown): ReportRow[] {
    if (!Array.isArray(value)) return [];

    return value.filter((row): row is ReportRow => {
        if (typeof row !== 'object' || row === null || !('id' in row)) return false;
        return typeof row.id === 'number' || typeof row.id === 'string';
    });
}

export function readReportMeta(value: unknown): ReportMeta {
    if (typeof value !== 'object' || value === null || Array.isArray(value)) return {};

    const meta = value as Record<string, unknown>;
    const numericKeys = [
        'warranty_return_rate', 'warranty_return_threshold', 'warranty_returns', 'expenses_total',
        'documents_count', 'manual_count', 'integration_count', 'nfe_count', 'nfse_count', 'error_count',
        'sessions_count', 'opening_total', 'completed_sales_total', 'difference_total',
        'order_payments_total', 'withdrawals_total', 'expected_total', 'closing_total',
        'manual_entries_total', 'manual_exits_total',
    ];
    if (numericKeys.some((key) => key in meta && typeof meta[key] !== 'number')) return {};
    if ('warranty_return_alert' in meta && typeof meta.warranty_return_alert !== 'boolean') return {};

    return value as ReportMeta;
}

export function readOrderDailyReportRows(value: unknown): OrderDailyReportRow[] {
    if (!Array.isArray(value)) return [];

    return value.filter((row): row is OrderDailyReportRow =>
        typeof row === 'object' && row !== null && typeof row.created_at === 'string' && typeof row.service_status === 'number',
    );
}
