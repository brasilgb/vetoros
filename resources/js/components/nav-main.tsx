import { Badge } from '@/components/ui/badge';
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from '@/components/ui/collapsible';
import { DropdownMenu, DropdownMenuContent, DropdownMenuItem, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    SidebarGroupLabel,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
    SidebarMenuSub,
    SidebarMenuSubButton,
    SidebarMenuSubItem,
    useSidebar,
} from '@/components/ui/sidebar';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { type NavItem } from '@/types';
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight } from 'lucide-react';

type NavMainPageProps = {
    othersetting?: {
        enable_finance?: boolean;
        enablesales?: boolean;
        enable_purchases?: boolean;
        show_follow_ups_menu?: boolean;
        show_quality_menu?: boolean;
        enable_technician_schedule_notifications?: boolean;
    };
    auth: {
        role?: string;
        permissions?: string[];
    };
    performanceAlert?: {
        hasAlert?: boolean;
        budgetTotal?: number;
        paymentTotal?: number;
    } | null;
    fiscalSetting?: {
        enabled?: boolean;
        nfe_enabled?: boolean;
        nfse_enabled?: boolean;
    } | null;
};

function TruncatedNavText({ children, className = 'min-w-0 flex-1 truncate' }: { children: string; className?: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className={className}>{children}</span>
            </TooltipTrigger>
            <TooltipContent side="right" align="center">
                {children}
            </TooltipContent>
        </Tooltip>
    );
}

export function NavMain({
    items = [],
    label,
    groupIcon,
    collapsible = false,
}: {
    items: NavItem[];
    label?: string;
    groupIcon?: NavItem['icon'];
    collapsible?: boolean;
}) {
    const { othersetting, auth, performanceAlert, fiscalSetting } = usePage<NavMainPageProps>().props;
    const { state } = useSidebar();
    const isCollapsed = state === 'collapsed';
    const disableSales = !othersetting?.enablesales ? 'sales' : '';
    const disableFinance = !othersetting?.enable_finance ? 'finance' : '';
    const permissions = auth?.permissions ?? [];
    const canAccessSalesModules =
        auth?.role === 'administrator' || auth?.role === 'operator' || auth?.role === 'root_app' || auth?.role === 'root_system';
    const visibleItems = items.filter(
        (item) =>
            (item.visibilitySetting ? (othersetting?.[item.visibilitySetting as keyof typeof othersetting] ?? false) : true) &&
            (item.enabled === 'sales' || item.enabled === 'finance' ? canAccessSalesModules : true) &&
            (!item.fiscalSetting || Boolean(fiscalSetting?.[item.fiscalSetting])) &&
            item.enabled !== disableSales &&
            item.enabled !== disableFinance &&
            (!item.permission || permissions.includes(item.permission)),
    );

    if (visibleItems.length === 0) {
        return null;
    }

    const hasPerformanceAlert =
        Boolean(performanceAlert?.hasAlert) && (Number(performanceAlert?.budgetTotal ?? 0) > 0 || Number(performanceAlert?.paymentTotal ?? 0) > 0);
    const renderItemBadge = (item: NavItem) => (
        <>
            {item.active === 'app.follow-ups.performance' && hasPerformanceAlert && (
                <Badge variant="destructive" className="ml-auto h-5 px-1.5 text-[10px]">
                    Alerta
                </Badge>
            )}
        </>
    );

    if (collapsible && label) {
        const ParentIcon = groupIcon ?? visibleItems[0]?.icon;
        const isActive = visibleItems.some((item) => route().current(item.active ?? ''));

        if (isCollapsed) {
            return (
                <SidebarMenu>
                    <SidebarMenuItem>
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <SidebarMenuButton isActive={isActive} aria-label={label}>
                                    {ParentIcon && <ParentIcon />}
                                    <span>{label}</span>
                                </SidebarMenuButton>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent side="right" align="start" className="w-56">
                                {visibleItems.map((item) => (
                                    <DropdownMenuItem key={item.title} asChild>
                                        <Link
                                            href={item.href}
                                            className={route().current(item.active ?? '') ? 'bg-accent text-accent-foreground' : ''}
                                        >
                                            {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                            <TruncatedNavText className="min-w-0 flex-1 truncate">{item.title}</TruncatedNavText>
                                            {renderItemBadge(item)}
                                        </Link>
                                    </DropdownMenuItem>
                                ))}
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </SidebarMenuItem>
                </SidebarMenu>
            );
        }

        return (
            <SidebarMenu>
                <Collapsible asChild defaultOpen={isActive} className="group/collapsible">
                    <SidebarMenuItem>
                        <CollapsibleTrigger asChild>
                            <SidebarMenuButton isActive={isActive} aria-label={label}>
                                {ParentIcon && <ParentIcon />}
                                <span>{label}</span>
                                <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                            </SidebarMenuButton>
                        </CollapsibleTrigger>
                        <CollapsibleContent>
                            <SidebarMenuSub>
                                {visibleItems.map((item) => (
                                    <SidebarMenuSubItem key={item.title}>
                                        <SidebarMenuSubButton asChild isActive={route().current(item.active ?? '')}>
                                            <Link href={item.href}>
                                                {item.icon && <item.icon className="h-4 w-4 shrink-0" />}
                                                <TruncatedNavText>{item.title}</TruncatedNavText>
                                                {renderItemBadge(item)}
                                            </Link>
                                        </SidebarMenuSubButton>
                                    </SidebarMenuSubItem>
                                ))}
                            </SidebarMenuSub>
                        </CollapsibleContent>
                    </SidebarMenuItem>
                </Collapsible>
            </SidebarMenu>
        );
    }

    return (
        <>
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarMenu>
                {visibleItems.map((item) => (
                    <SidebarMenuItem key={item.title}>
                        <SidebarMenuButton asChild isActive={route().current(item.active ?? '')} tooltip={{ children: item.title }}>
                            <Link href={item.href} prefetch>
                                {item.icon && <item.icon />}
                                <TruncatedNavText>{item.title}</TruncatedNavText>
                                {renderItemBadge(item)}
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                ))}
            </SidebarMenu>
        </>
    );
}
