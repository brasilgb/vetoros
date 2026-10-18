import { Breadcrumbs } from '@/components/breadcrumbs';
import HelpSearch from '@/components/help-search';
import { SidebarTrigger } from '@/components/ui/sidebar';
import { useInitials } from '@/hooks/use-initials';
import { SharedData, type BreadcrumbItem as BreadcrumbItemType } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import { MessageSquareMoreIcon } from 'lucide-react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import BudgetsApproved from './budgets-aproved';
import { Avatar, AvatarFallback, AvatarImage } from './ui/avatar';
import { Badge } from './ui/badge';
import { Button } from './ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from './ui/dropdown-menu';
import { UserMenuContent } from './user-menu-content';

type AppSidebarHeaderPageProps = SharedData & {
    notifications?: number;
    orderStatus?: unknown[];
    auth: SharedData['auth'] & {
        role?: string;
    };
};

export function AppSidebarHeader({ breadcrumbs = [] }: { breadcrumbs?: BreadcrumbItemType[] }) {
    const page = usePage<AppSidebarHeaderPageProps>();
    const { auth, notifications = 0, orderStatus = [] } = page.props;
    const unreadMessages = Number(notifications || 0);

    const getInitials = useInitials();

    useEffect(() => {
        if (!auth.user?.id || auth.user.tenant_id === null) {
            return;
        }

        const notifyUnreadMessages = () => {
            if (unreadMessages <= 0) {
                return;
            }

            toast.message('Nova mensagem interna', {
                id: 'vetoros:internal-messages:unread',
                description: unreadMessages === 1 ? 'Você tem 1 mensagem não lida.' : `Você tem ${unreadMessages} mensagens não lidas.`,
                duration: 9000,
                action: {
                    label: 'Abrir',
                    onClick: () => {
                        router.visit(route('app.messages.index'));
                    },
                },
            });
        };

        notifyUnreadMessages();
        const notificationInterval = window.setInterval(notifyUnreadMessages, 60000);

        return () => window.clearInterval(notificationInterval);
    }, [auth.user?.id, auth.user.tenant_id, unreadMessages]);

    useEffect(() => {
        if (!auth.user?.id || auth.user.tenant_id === null) {
            return;
        }

        const refreshInterval = window.setInterval(() => {
            router.reload({
                only: ['notifications'],
            });
        }, 60000);

        return () => window.clearInterval(refreshInterval);
    }, [auth.user?.id, auth.user.tenant_id]);

    return (
        <header className="border-sidebar-border/50 flex h-16 min-w-0 shrink-0 items-center justify-between gap-2 border-b px-3 transition-[width,height] ease-linear group-has-data-[collapsible=icon]/sidebar-wrapper:h-12 sm:px-4">
            <div className="flex min-w-0 items-center gap-2">
                <SidebarTrigger className="-ml-1" />
                {breadcrumbs.length > 0 && (
                    <div className="border-sidebar-border/60 min-w-0 border-l pl-3">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                )}
            </div>
            <div className="flex min-w-0 shrink-0 items-center gap-2">
                {auth.user.tenant_id !== null && <HelpSearch />}

                {auth.user.tenant_id !== null && auth.role !== 'technician' && orderStatus.length > 0 ? (
                    <BudgetsApproved count={orderStatus.length} />
                ) : (
                    ''
                )}

                {auth.user.tenant_id !== null && (
                    <Button variant="ghost" size="icon" asChild className="relative">
                        <Link href={route('app.messages.index')}>
                            {unreadMessages > 0 && (
                                <Badge variant="default" className="absolute -top-2 -right-2">
                                    {String(unreadMessages)}
                                </Badge>
                            )}
                            <MessageSquareMoreIcon />
                        </Link>
                    </Button>
                )}
                <DropdownMenu>
                    <DropdownMenuTrigger asChild>
                        <Button variant="ghost" className="group/avatar relative size-10 rounded-full p-1">
                            <Avatar className="size-8 overflow-hidden rounded-full">
                                <AvatarImage src={auth.user.avatar} alt={auth.user.name} />
                                <AvatarFallback className="rounded-lg bg-neutral-200 text-black dark:bg-neutral-700 dark:text-white">
                                    {getInitials(auth.user.name)}
                                </AvatarFallback>
                            </Avatar>
                            <span
                                aria-hidden="true"
                                className="bg-popover pointer-events-none invisible absolute top-1/2 right-full z-50 mr-2 -translate-y-1/2 rounded-lg border p-2 opacity-0 shadow-lg transition-opacity group-hover/avatar:visible group-hover/avatar:opacity-100 group-focus-visible/avatar:visible group-focus-visible/avatar:opacity-100"
                            >
                                <Avatar className="size-24 overflow-hidden rounded-lg">
                                    <AvatarImage src={auth.user.avatar} alt="" className="object-cover" />
                                    <AvatarFallback className="rounded-lg bg-neutral-200 text-xl text-black dark:bg-neutral-700 dark:text-white">
                                        {getInitials(auth.user.name)}
                                    </AvatarFallback>
                                </Avatar>
                            </span>
                        </Button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent className="w-56" align="end">
                        <UserMenuContent user={auth.user} />
                    </DropdownMenuContent>
                </DropdownMenu>
            </div>
        </header>
    );
}
