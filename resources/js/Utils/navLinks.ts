import { type NavGroup, type NavItem } from '@/types';
import {
    BanknoteArrowDownIcon,
    Barcode,
    BookOpenText,
    Building,
    Building2,
    Calendar,
    CalendarClock,
    Cog,
    CogIcon,
    Copyright,
    BadgePercent,
    FileChartColumn,
    FileTextIcon,
    HandCoins,
    LayoutGrid,
    Lightbulb,
    MessageCircle,
    MessageCircleCode,
    MessageSquareMore,
    PackageCheck,
    Printer,
    ReceiptText,
    ScrollText,
    ShieldAlert,
    ShoppingCartIcon,
    Smartphone,
    Truck,
    UserCog,
    Users2,
    WalletCards,
    Wrench,
} from 'lucide-react';

const mainNavGroups: NavGroup[] = [
    {
        title: 'Geral',
        icon: LayoutGrid,
        items: [
            {
                title: 'Painel',
                href: route('app.dashboard'),
                icon: LayoutGrid,
                active: 'app.dashboard',
                enabled: 'dashboard',
                permission: 'dashboard',
            },
        ],
    },
    {
        title: 'Atendimento',
        icon: Wrench,
        items: [
            {
                title: 'Clientes',
                href: route('app.customers.index'),
                icon: Users2,
                active: 'app.customers.*',
                enabled: 'customers',
                permission: 'customers',
            },
            {
                title: 'Ordens de serviço',
                href: route('app.orders.index'),
                icon: Wrench,
                active: 'app.orders.*',
                enabled: 'orders',
                permission: 'orders',
            },
            {
                title: 'Agendamentos',
                href: route('app.schedules.index'),
                icon: Calendar,
                active: 'app.schedules.*',
                enabled: 'schedules',
                permission: 'schedules',
            },
            {
                title: 'Mensagens',
                href: route('app.messages.index'),
                icon: MessageSquareMore,
                active: 'app.messages.*',
                enabled: 'messages',
                permission: 'messages',
            },
        ],
    },
    {
        title: 'Contratos/orçamentos',
        icon: CalendarClock,
        collapsible: true,
        items: [
            {
                title: 'Contratos de manutenção',
                href: route('app.maintenance-contracts.index'),
                icon: CalendarClock,
                active: 'app.maintenance-contracts.*',
                enabled: 'finance',
                permission: 'finance',
            },
            {
                title: 'Orçamentos',
                href: route('app.budgets.index'),
                icon: ScrollText,
                active: 'app.budgets.*',
                enabled: 'budgets',
                permission: 'budgets',
            },
        ],
    },
    {
        title: 'Estoque',
        icon: PackageCheck,
        items: [
            {
                title: 'Peças e produtos',
                href: route('app.parts.index'),
                icon: PackageCheck,
                active: 'app.parts.*',
                enabled: 'parts',
                permission: 'parts',
            },
        ],
    },
    {
        title: 'Compras',
        icon: Truck,
        collapsible: true,
        items: [
            {
                title: 'Ordens de compra',
                href: route('app.purchase-orders.index'),
                icon: Truck,
                active: 'app.purchase-orders.*',
                permission: 'purchase_orders',
                visibilitySetting: 'enable_purchases',
            },
            {
                title: 'Fornecedores',
                href: route('app.suppliers.index'),
                icon: Building2,
                active: 'app.suppliers.*',
                permission: 'suppliers',
                visibilitySetting: 'enable_purchases',
            },
        ],
    },
    {
        title: 'Relacionamento',
        icon: MessageCircle,
        collapsible: true,
        items: [
            {
                title: 'Retornos ao cliente',
                href: route('app.follow-ups.index'),
                icon: MessageCircle,
                active: 'app.follow-ups.*',
                enabled: 'orders',
                permission: 'orders',
                visibilitySetting: 'show_follow_ups_menu',
            },
            {
                title: 'Garantias e avaliações',
                href: route('app.quality.index'),
                icon: ShieldAlert,
                active: 'app.quality.*',
                enabled: 'reports',
                permission: 'reports',
                visibilitySetting: 'show_quality_menu',
            },
        ],
    },
    {
        title: 'Financeiro',
        icon: WalletCards,
        collapsible: true,
        items: [
            {
                title: 'Caixa',
                href: route('app.cashier.index'),
                icon: WalletCards,
                active: 'app.cashier.*',
                enabled: 'finance',
                permission: 'finance',
            },
            {
                title: 'Despesas',
                href: route('app.expenses.index'),
                icon: BanknoteArrowDownIcon,
                active: 'app.expenses.*',
                enabled: 'finance',
                permission: 'finance',
            },
            {
                title: 'Contas a pagar',
                href: route('app.accounts-payable.index'),
                icon: HandCoins,
                active: 'app.accounts-payable.*',
                enabled: 'finance',
                permission: 'finance',
            },
            {
                title: 'Comissão de técnicos',
                href: route('app.technician-commissions.index'),
                icon: BadgePercent,
                active: 'app.technician-commissions.*',
                enabled: 'finance',
                permission: 'finance',
            },
            {
                title: 'PDV e vendas',
                href: route('app.sales.index'),
                icon: ShoppingCartIcon,
                active: 'app.sales.*',
                enabled: 'sales',
                permission: 'sales',
            },
        ],
    },
    {
        title: 'Fiscal',
        icon: ReceiptText,
        items: [
            {
                title: 'Notas fiscais',
                href: route('app.fiscal-documents.index'),
                icon: ReceiptText,
                active: 'app.fiscal-documents.index',
                permission: 'fiscal_documents',
                fiscalSetting: 'enabled',
            },
        ],
    },
];

const mainNavItems: NavItem[] = mainNavGroups.flatMap((group) => group.items);

const mainUserItems: NavItem[] = [
    {
        title: 'Relatórios',
        href: route('app.reports.index'),
        icon: FileTextIcon,
        active: 'app.reports.*',
        enabled: 'reports',
        permission: 'reports',
    },
    {
        title: 'Usuários',
        href: route('app.users.index'),
        icon: UserCog,
        active: 'app.users.*',
        permission: 'users',
    },
];

const mainConfItems = [
    {
        title: 'Configurações',
        url: '#',
        icon: Cog,
        items: [
            {
                title: 'Dados da empresa',
                url: route('app.company.index'),
                icon: Building,
                active: 'app.company.*',
                permission: 'company',
            },
            {
                title: 'Sistema e módulos',
                url: route('app.other-settings.index'),
                icon: CogIcon,
                active: 'app.other-settings.*',
                permission: 'other_settings',
            },
            {
                title: 'Mensagens do WhatsApp',
                url: route('app.whatsapp-message.index'),
                icon: MessageCircleCode,
                active: 'app.whatsapp-message.*',
                permission: 'whatsapp_messages',
            },
            {
                title: 'Recibos / Checklist',
                url: route('app.receipts.index'),
                icon: Printer,
                active: 'app.receipts.*',
                permission: 'receipts',
            },
            {
                title: 'Etiquetas',
                url: route('app.label-printing.index'),
                icon: Barcode,
                active: 'app.label-printing.*',
                permission: 'label_printing',
            },
            {
                title: 'Aplicativos auxiliares',
                url: route('app.auxiliary-apps.index'),
                icon: Smartphone,
                active: 'app.auxiliary-apps.*',
                permission: 'settings',
            },
            {
                title: 'Ajustes e avaliações',
                url: route('app.improvement-requests.index'),
                icon: MessageSquareMore,
                active: 'app.improvement-requests.*',
                permission: 'dashboard',
            },
        ],
    },
];

const mainAdminItems = [
    {
        title: 'Dashboard',
        href: route('admin.dashboard'),
        icon: LayoutGrid,
        active: 'admin.dashboard',
    },
    {
        title: 'Empresas',
        href: route('admin.tenants.index'),
        icon: Building,
        active: 'admin.tenants.*',
    },
    {
        title: 'Relatórios',
        href: route('admin.reports.index'),
        icon: FileChartColumn,
        active: 'admin.reports.*',
    },
    {
        title: 'Fiscal',
        href: route('admin.fiscal.integration'),
        icon: ReceiptText,
        active: 'admin.fiscal.*',
    },
    {
        title: 'Usuários',
        href: route('admin.users.index'),
        icon: UserCog,
        active: 'admin.users.*',
    },
    {
        title: 'Configurações',
        href: route('admin.settings.index'),
        icon: Cog,
        active: 'admin.settings.*',
    },
    {
        title: 'Manual de ajuda',
        href: route('admin.help-topics.index'),
        icon: BookOpenText,
        active: 'admin.help-topics.*',
    },
];

const mainAdminAdjustmentItems: NavItem[] = [
    {
        title: 'Avaliações',
        href: route('admin.tenant-feedbacks.index'),
        icon: MessageSquareMore,
        active: 'admin.tenant-feedbacks.*',
    },
    {
        title: 'Ajustes e solicitações',
        href: route('admin.tenant-improvement-requests.index'),
        icon: Lightbulb,
        active: 'admin.tenant-improvement-requests.*',
    },
];

const mainPlansItems: NavItem[] = [
    {
        title: 'Cadastrar plano',
        href: route('admin.plans.index'),
        icon: Copyright,
        active: 'admin.plans.*',
    },
];

const footerNavItems: NavItem[] = [
    {
        title: 'Documentação',
        href: import.meta.env.VITE_APP_URL + '/documentation/doc-vetoros.html',
        icon: BookOpenText,
        external: true,
    },
    {
        title: 'Ajustes/Avaliações',
        href: route('app.improvement-requests.index'),
        icon: MessageSquareMore,
        active: 'app.improvement-requests.*',
        permission: 'dashboard',
    },
];

export { footerNavItems, mainAdminAdjustmentItems, mainAdminItems, mainConfItems, mainNavGroups, mainNavItems, mainPlansItems, mainUserItems };
