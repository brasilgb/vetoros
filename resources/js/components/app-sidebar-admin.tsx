import { NavMain } from '@/components/nav-main';
import {
    Sidebar,
    SidebarContent,
    SidebarGroup,
    SidebarGroupLabel,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { mainAdminAdjustmentItems, mainAdminItems, mainAdminSettingsItems, mainPlansItems } from '@/Utils/navLinks';
import { Link } from '@inertiajs/react';
import { Cog, MessageSquareMore } from 'lucide-react';
import { BrandPanelLogo } from './brand-logo';

export function AppSidebarAdmin() {
    return (
        <Sidebar collapsible="icon" variant="inset">
            <SidebarHeader>
                <SidebarMenu>
                    <SidebarMenuItem>
                        <SidebarMenuButton size="lg" asChild>
                            <Link href="/" prefetch>
                                <BrandPanelLogo />
                            </Link>
                        </SidebarMenuButton>
                    </SidebarMenuItem>
                </SidebarMenu>
            </SidebarHeader>
            <SidebarContent>
                <SidebarGroup>
                    <SidebarGroupLabel>Operações Administrativas</SidebarGroupLabel>

                    <NavMain items={mainAdminItems} />
                    <div className="mt-1">
                        <NavMain items={mainAdminSettingsItems} label="Configurações" groupIcon={Cog} collapsible />
                    </div>
                    <div className="mt-1">
                        <NavMain items={mainAdminAdjustmentItems} label="Ajustes/Avaliações" groupIcon={MessageSquareMore} collapsible />
                    </div>
                    <div className="mt-1">
                        <NavMain items={mainPlansItems} />
                    </div>
                </SidebarGroup>
            </SidebarContent>
        </Sidebar>
    );
}
