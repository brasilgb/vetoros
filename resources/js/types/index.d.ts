import { LucideIcon } from 'lucide-react';
import type { Config } from 'ziggy-js';

export interface OptionType {
    value: string | number;
    label: string;
}

export interface Auth {
    user: User;
    role?: string;
    permissions?: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavGroup {
    title: string;
    icon?: LucideIcon | null;
    items: NavItem[];
    collapsible?: boolean;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon | null;
    active?: string;
    enabled?: string;
    permission?: string;
    visibilitySetting?: string;
    fiscalSetting?: 'enabled' | 'nfe_enabled' | 'nfse_enabled';
    external?: boolean;
}

export interface SharedData {
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    // Só o necessário a cada navegação; as rotas vêm do @routes (window.Ziggy).
    ziggy: Pick<Config, 'url'> & { location: string; query: Record<string, string> };
    sidebarOpen: boolean;
    othersettings: {
        budget: boolean;
        navigation: boolean;
        enable_finance?: boolean;
        enablesales?: boolean;
        show_follow_ups_menu?: boolean;
        show_tasks_menu?: boolean;
        show_commercial_performance_menu?: boolean;
        show_quality_menu?: boolean;
        enable_technician_schedule_notifications?: boolean;
    };
    [key: string]: unknown;
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown; // This allows for additional properties...
}

export interface Customer {
    id: number;
    name: string;
    cpfcnpj: string;
    birth: string;
    email: string;
    zipcode: string;
    state: string;
    city: string;
    district: string;
    street: string;
    complement: string;
    number: string | number;
    phone: string;
    contactname: string;
    whatsapp: string;
    contactphone: string;
    observations: string;
}

export type PageProps<T extends Record<string, unknown> = Record<string, unknown>> = T & {
    auth: {
        user: User;
    };
    errors: Record<string, string>;
    success?: string; // Optional success message from Laravel
    error?: string; // Optional error message from Laravel
};
export interface Order {
    id: number;
    order_type?: 'equipment' | 'external_service' | string;
    customer_id: number;
    equipment_id: number;
    model: string;
    service_type?: string | null;
    service_details?: string | null;
    materials_used?: string | null;
    service_status: number;
    created_at: string;
    updated_at: string;
    customer: Customer;
    equipment: Equipment;
    delivery_date: string;
}

export interface ScheduleMaterialChecklistItem {
    name: string;
    quantity: number;
    part_id?: number | null;
    used?: boolean;
}

export interface Scheduler {
    id: number;
    user_id: number;
    customer_id: number;
    order_id?: number | null;
    service?: string | null;
    details?: string | null;
    material_checklist?: Array<string | ScheduleMaterialChecklistItem> | null;
    technician_checklist?: string[] | null;
    technician_checklist_items?: string[] | null;
    technician_checklist_completed_at?: string | null;
    status: number;
    schedules: string;
    created_at: string;
    updated_at: string;
    customer: Customer;
    order: Order;
    user: User;
    observations: string;
    send_to_technician: boolean;
    local_payment_received?: boolean;
    local_payment_amount?: number | string | null;
    local_payment_received_at?: string | null;
}

export interface Message {
    id: number;
    sender_id: number;
    recipient_id: number;
    title: string;
    message: string;
    status: string | number;
    sender: User;
    recipient: User;
}

export interface Budget {
    id: number;
    equipment_id: string;
    service: string;
    model: string;
    description: string;
    estimated_time: number;
    part_value: number;
    labor_value: number;
    total_value: number;
    warranty: string;
    validity: number;
    obs: string;
    created_at: string;
}
