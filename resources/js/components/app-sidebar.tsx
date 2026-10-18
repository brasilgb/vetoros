import { NavFooter } from '@/components/nav-footer';
import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarGroup,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { footerNavItems, mainConfItems, mainNavGroups, mainUserItems } from '@/Utils/navLinks';
import { Link } from '@inertiajs/react';
import AppLogo from './app-logo';
import NavMainCollapsible from './nav-main-collapsible';

export function AppSidebar() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <AppLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent className="gap-0">
                <SidebarGroup className="gap-0 p-2 [&_[data-slot=sidebar-menu-item]]:my-0.5 [&_[data-slot=sidebar-menu-sub-item]]:my-0.5 [&_[data-slot=sidebar-menu-sub]]:gap-0 [&_[data-slot=sidebar-menu-sub]]:py-0 [&_[data-slot=sidebar-menu]]:gap-0">
                    {mainNavGroups.map((group) => (
                        <div key={group.title}>
                            <NavMain
                                label={group.collapsible ? group.title : undefined}
                                groupIcon={group.icon}
                                items={group.items}
                                collapsible={Boolean(group.collapsible)}
                            />
                        </div>
                    ))}
                    <div>
                        <NavMainCollapsible items={mainConfItems} />
                    </div>
                    <div>
                        <NavMain items={mainUserItems} />
                    </div>
                </SidebarGroup>
            </SidebarContent>

            <SidebarFooter>
                <NavFooter items={footerNavItems} className="mt-auto" />
            </SidebarFooter>
        </Sidebar>
    );
}
