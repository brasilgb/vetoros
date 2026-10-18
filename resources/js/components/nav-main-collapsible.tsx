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
import { Link, usePage } from '@inertiajs/react';
import { ChevronRight, type LucideIcon } from 'lucide-react';

function TruncatedNavText({ children }: { children: string }) {
    return (
        <Tooltip>
            <TooltipTrigger asChild>
                <span className="min-w-0 flex-1 truncate">{children}</span>
            </TooltipTrigger>
            <TooltipContent side="right" align="center">
                {children}
            </TooltipContent>
        </Tooltip>
    );
}

export default function NavMainCollapsible({
    items,
    label,
}: {
    label?: string;
    items: {
        title: string;
        url: string;
        icon?: LucideIcon;
        isActive?: boolean;
        items?: {
            title: string;
            url: string;
            icon?: LucideIcon;
            active?: string;
            permission?: string;
        }[];
    }[];
}) {
    const { state } = useSidebar();
    const isCollapsed = state === 'collapsed';
    const { auth } = usePage<{ auth?: { permissions?: string[] } }>().props;
    const permissions = auth?.permissions ?? [];

    return (
        <>
            {label && <SidebarGroupLabel>{label}</SidebarGroupLabel>}
            <SidebarMenu>
                {items.map((item) => {
                    const visibleSubItems = item.items?.filter((subItem) => !subItem.permission || permissions.includes(subItem.permission)) ?? [];
                    const isActive = item.isActive ?? visibleSubItems.some((subItem) => route().current(subItem.active ?? ''));

                    if (visibleSubItems.length === 0) {
                        return null;
                    }

                    // Se estiver colapsado e tiver sub-itens, usamos DropdownMenu (flutuante)
                    if (isCollapsed && visibleSubItems.length) {
                        return (
                            <SidebarMenuItem key={item.title}>
                                <DropdownMenu>
                                    <DropdownMenuTrigger asChild>
                                        <SidebarMenuButton isActive={isActive} aria-label={item.title}>
                                            {item.icon && <item.icon />}
                                            <span>{item.title}</span>
                                        </SidebarMenuButton>
                                    </DropdownMenuTrigger>
                                    <DropdownMenuContent side="right" align="start" className="w-48">
                                        {visibleSubItems.map((subItem) => (
                                            <DropdownMenuItem key={subItem.title} asChild>
                                                <Link
                                                    href={subItem.url}
                                                    className={route().current(subItem.active ?? '') ? 'bg-accent text-accent-foreground' : ''}
                                                >
                                                    {subItem.icon && <subItem.icon className="h-4 w-4 shrink-0" />}
                                                    <TruncatedNavText>{subItem.title}</TruncatedNavText>
                                                </Link>
                                            </DropdownMenuItem>
                                        ))}
                                    </DropdownMenuContent>
                                </DropdownMenu>
                            </SidebarMenuItem>
                        );
                    }

                    // Se estiver aberto, mantemos o comportamento de Collapsible (sanfona)
                    return (
                        <Collapsible key={item.title} asChild defaultOpen={isActive} className="group/collapsible">
                            <SidebarMenuItem>
                                <CollapsibleTrigger asChild>
                                    <SidebarMenuButton isActive={isActive} aria-label={item.title}>
                                        {item.icon && <item.icon />}
                                        <span>{item.title}</span>
                                        <ChevronRight className="ml-auto transition-transform duration-200 group-data-[state=open]/collapsible:rotate-90" />
                                    </SidebarMenuButton>
                                </CollapsibleTrigger>
                                <CollapsibleContent>
                                    <SidebarMenuSub>
                                        {visibleSubItems.map((subItem) => (
                                            <SidebarMenuSubItem key={subItem.title}>
                                                <SidebarMenuSubButton asChild isActive={route().current(subItem.active ?? '')}>
                                                    <Link href={subItem.url}>
                                                        {subItem.icon && <subItem.icon className="h-4 w-4 shrink-0" />}
                                                        <TruncatedNavText>{subItem.title}</TruncatedNavText>
                                                    </Link>
                                                </SidebarMenuSubButton>
                                            </SidebarMenuSubItem>
                                        ))}
                                    </SidebarMenuSub>
                                </CollapsibleContent>
                            </SidebarMenuItem>
                        </Collapsible>
                    );
                })}
            </SidebarMenu>
        </>
    );
}
