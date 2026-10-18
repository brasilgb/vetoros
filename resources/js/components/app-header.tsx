import { Breadcrumbs } from '@/components/breadcrumbs';
import { Icon } from '@/components/icon';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { DropdownMenu, DropdownMenuContent, DropdownMenuTrigger } from '@/components/ui/dropdown-menu';
import {
    NavigationMenu,
    NavigationMenuContent,
    NavigationMenuItem,
    NavigationMenuList,
    NavigationMenuTrigger,
    navigationMenuTriggerStyle,
} from '@/components/ui/navigation-menu';
import { Sheet, SheetContent, SheetHeader, SheetTitle, SheetTrigger } from '@/components/ui/sheet';
import { UserMenuContent } from '@/components/user-menu-content';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem, type NavItem, type SharedData } from '@/types';
import { mainConfItems, mainNavGroups, mainNavItems, mainPlansItems, mainUserItems } from '@/Utils/navLinks';
import { Link, usePage } from '@inertiajs/react';
import { BookOpen, Folder, Menu } from 'lucide-react';
import AppLogo from './app-logo';
import AppLogoIcon from './app-logo-icon';

const rightNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/laravel/react-starter-kit',
        icon: Folder,
    },
    {
        title: 'Documentation',
        href: 'https://laravel.com/docs/starter-kits#react',
        icon: BookOpen,
    },
];

const activeItemStyles = 'text-neutral-900 dark:bg-neutral-800 dark:text-neutral-100';

interface AppHeaderProps {
    breadcrumbs?: BreadcrumbItem[];
}

type AppHeaderPageProps = SharedData & {
    othersetting?: {
        budget?: boolean;
        enableparts?: boolean;
        enable_finance?: boolean;
        enablesales?: boolean;
        show_follow_ups_menu?: boolean;
        show_tasks_menu?: boolean;
        show_commercial_performance_menu?: boolean;
        show_quality_menu?: boolean;
        enable_technician_schedule_notifications?: boolean;
    };
    auth: {
        user: SharedData['auth']['user'];
        permissions?: string[];
    };
    fiscalSetting?: {
        enabled?: boolean;
        nfe_enabled?: boolean;
        nfse_enabled?: boolean;
    } | null;
};

export function AppHeader({ breadcrumbs = [] }: AppHeaderProps) {
    const page = usePage<AppHeaderPageProps>();
    const { auth, othersetting, fiscalSetting } = page.props;
    const disableParts = !othersetting?.enableparts ? 'parts' : '';
    const disableSales = !othersetting?.enablesales ? 'sales' : '';
    const disableFinance = !othersetting?.enable_finance ? 'finance' : '';
    const permissions = auth?.permissions ?? [];
    const getInitials = useInitials();

    return (
        <>
            <div className="border-sidebar-border/80 border-b">
                <div className="mx-auto flex h-16 items-center px-4 md:container">
                    {/* Mobile Menu */}
                    <div className="lg:hidden">
                        <Sheet>
                            <SheetTrigger asChild>
                                <Button variant="ghost" size="icon" className="mr-2 h-[34px] w-[34px]">
                                    <Menu className="h-5 w-5" />
                                </Button>
                            </SheetTrigger>
                            <SheetContent side="left" className="bg-sidebar flex h-full w-64 flex-col items-stretch justify-between">
                                <SheetTitle className="sr-only">Navigation Menu</SheetTitle>
                                <SheetHeader className="flex justify-start text-left">
                                    <AppLogoIcon className="h-6 w-6 fill-current text-black dark:text-white" />
                                </SheetHeader>
                                <div className="flex h-full flex-1 flex-col space-y-4 overflow-y-auto p-4">
                                    <div className="flex h-full flex-col justify-between text-sm">
                                        <div className="flex flex-col space-y-5">
                                            {mainNavGroups.map((group) => {
                                                const visibleItems = group.items.filter(
                                                    (item) =>
                                                        (item.visibilitySetting
                                                            ? (othersetting?.[item.visibilitySetting as keyof NonNullable<typeof othersetting>] ??
                                                              false)
                                                            : true) &&
                                                        item.enabled !== disableParts &&
                                                        item.enabled !== disableSales &&
                                                        item.enabled !== disableFinance &&
                                                        (!item.fiscalSetting || Boolean(fiscalSetting?.[item.fiscalSetting])) &&
                                                        (!item.permission || permissions.includes(item.permission)),
                                                );

                                                if (visibleItems.length === 0) {
                                                    return null;
                                                }

                                                return (
                                                    <div key={group.title} className="flex flex-col space-y-3">
                                                        <span className="text-muted-foreground px-1 text-xs font-medium uppercase">
                                                            {group.title}
                                                        </span>
                                                        {visibleItems.map((item) => (
                                                            <Link
                                                                key={item.title}
                                                                href={item.href}
                                                                className="flex items-center space-x-2 font-medium"
                                                            >
                                                                {item.icon && <Icon iconNode={item.icon} className="h-5 w-5" />}
                                                                <span>{item.title}</span>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                );
                                            })}
                                            {mainConfItems.map((group) => {
                                                const visibleItems = group.items.filter(
                                                    (item) => !item.permission || permissions.includes(item.permission),
                                                );

                                                if (visibleItems.length === 0) return null;

                                                return (
                                                    <div key={group.title} className="flex flex-col space-y-3">
                                                        <span className="text-muted-foreground px-1 text-xs font-medium uppercase">
                                                            {group.title}
                                                        </span>
                                                        {visibleItems.map((item) => (
                                                            <div key={item.title}>
                                                                <Link href={item.url} className="flex items-center space-x-2 font-medium">
                                                                    {item.icon && <Icon iconNode={item.icon} className="h-5 w-5" />}
                                                                    <span>{item.title}</span>
                                                                </Link>
                                                            </div>
                                                        ))}
                                                    </div>
                                                );
                                            })}
                                        </div>
                                        <div className="flex flex-col space-y-4">
                                            {mainUserItems.map(
                                                (item) =>
                                                    (!item.permission || permissions.includes(item.permission)) && (
                                                        <Link key={item.title} href={item.href} className="flex items-center space-x-2 font-medium">
                                                            {item.icon && <Icon iconNode={item.icon} className="h-5 w-5" />}
                                                            <span>{item.title}</span>
                                                        </Link>
                                                    ),
                                            )}
                                        </div>

                                        <div className="flex flex-col space-y-4">
                                            {rightNavItems.map((item) => (
                                                <a
                                                    key={item.title}
                                                    href={item.href}
                                                    target="_blank"
                                                    rel="noopener noreferrer"
                                                    className="flex items-center space-x-2 font-medium"
                                                >
                                                    {item.icon && <Icon iconNode={item.icon} className="h-5 w-5" />}
                                                    <span>{item.title}</span>
                                                </a>
                                            ))}
                                        </div>
                                    </div>
                                </div>
                            </SheetContent>
                        </Sheet>
                    </div>

                    <Link href="/" prefetch className="flex items-center space-x-2">
                        <AppLogo />
                    </Link>

                    {/* Desktop Navigation */}
                    <div className="ml-6 hidden h-full items-center space-x-6 lg:flex">
                        <NavigationMenu viewport={false} className="flex h-full items-stretch">
                            <NavigationMenuList className="flex h-full items-stretch space-x-2">
                                {mainNavItems.map(
                                    (item, index) =>
                                        (item.visibilitySetting
                                            ? (othersetting?.[item.visibilitySetting as keyof NonNullable<typeof othersetting>] ?? false)
                                            : true) &&
                                        item.enabled !== disableParts &&
                                        item.enabled !== disableSales &&
                                        item.enabled !== disableFinance &&
                                        (!item.fiscalSetting || Boolean(fiscalSetting?.[item.fiscalSetting])) &&
                                        (!item.permission || permissions.includes(item.permission)) && (
                                            <NavigationMenuItem key={index} className="relative flex h-full items-center">
                                                <Link
                                                    href={item.href}
                                                    className={cn(
                                                        navigationMenuTriggerStyle(),
                                                        page.url === item.href && activeItemStyles,
                                                        'h-9 cursor-pointer px-3',
                                                    )}
                                                >
                                                    {item.icon && <Icon iconNode={item.icon} className="mr-2 h-4 w-4" />}
                                                    {item.title}
                                                </Link>
                                                {page.url === item.href && (
                                                    <div className="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"></div>
                                                )}
                                            </NavigationMenuItem>
                                        ),
                                )}
                            </NavigationMenuList>

                            <NavigationMenuList className="flex h-full items-stretch space-x-2">
                                <NavigationMenuItem className="relative z-50 flex h-full items-center">
                                    {mainConfItems.map((item, index) => {
                                        const visibleItems = item.items.filter(
                                            (subItems) => !subItems.permission || permissions.includes(subItems.permission),
                                        );

                                        if (visibleItems.length === 0) {
                                            return null;
                                        }

                                        return (
                                            <div key={`${item.title}-${index}`}>
                                                <NavigationMenuTrigger className="flex items-center space-x-2 font-medium">
                                                    {item.icon && <Icon iconNode={item.icon} className="h-5 w-5" />}
                                                    <span>{item.title}</span>
                                                </NavigationMenuTrigger>
                                                <NavigationMenuContent key={index}>
                                                    <ul className="grid w-[220px] gap-4">
                                                        {visibleItems.map((subItems, index) => (
                                                            <li key={`${subItems.title}-${index}`}>
                                                                <NavigationMenuItem key={index} className="relative flex h-full items-center">
                                                                    <Link
                                                                        href={subItems.url}
                                                                        className={cn(
                                                                            navigationMenuTriggerStyle(),
                                                                            page.url === subItems.url && activeItemStyles,
                                                                            'h-9 cursor-pointer px-3',
                                                                        )}
                                                                    >
                                                                        {subItems.icon && <Icon iconNode={subItems.icon} className="mr-2 h-4 w-4" />}
                                                                        {subItems.title}
                                                                    </Link>
                                                                    {page.url === subItems.url && (
                                                                        <div className="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"></div>
                                                                    )}
                                                                </NavigationMenuItem>
                                                            </li>
                                                        ))}
                                                    </ul>
                                                </NavigationMenuContent>
                                            </div>
                                        );
                                    })}
                                </NavigationMenuItem>
                            </NavigationMenuList>

                            {othersetting?.budget ? (
                                <NavigationMenuList className="flex h-full items-stretch space-x-2">
                                    {mainPlansItems.map((item, index) => (
                                        <NavigationMenuItem key={index} className="relative flex h-full items-center">
                                            <Link
                                                href={item.href}
                                                className={cn(
                                                    navigationMenuTriggerStyle(),
                                                    page.url === item.href && activeItemStyles,
                                                    'h-9 cursor-pointer px-3',
                                                )}
                                            >
                                                {item.icon && <Icon iconNode={item.icon} className="mr-2 h-4 w-4" />}
                                                {item.title}
                                            </Link>
                                            {page.url === item.href && (
                                                <div className="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"></div>
                                            )}
                                        </NavigationMenuItem>
                                    ))}
                                </NavigationMenuList>
                            ) : (
                                ''
                            )}

                            <NavigationMenuList className="flex h-full items-stretch space-x-2">
                                {mainUserItems.map(
                                    (item, index) =>
                                        (!item.permission || permissions.includes(item.permission)) && (
                                            <NavigationMenuItem key={index} className="relative flex h-full items-center">
                                                <Link
                                                    href={item.href}
                                                    className={cn(
                                                        navigationMenuTriggerStyle(),
                                                        page.url === item.href && activeItemStyles,
                                                        'h-9 cursor-pointer px-3',
                                                    )}
                                                >
                                                    {item.icon && <Icon iconNode={item.icon} className="mr-2 h-4 w-4" />}
                                                    {item.title}
                                                </Link>
                                                {page.url === item.href && (
                                                    <div className="absolute bottom-0 left-0 h-0.5 w-full translate-y-px bg-black dark:bg-white"></div>
                                                )}
                                            </NavigationMenuItem>
                                        ),
                                )}
                            </NavigationMenuList>
                        </NavigationMenu>
                    </div>

                    <div className="ml-auto flex items-center space-x-2">
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
                </div>
            </div>
            {breadcrumbs.length > 1 && (
                <div className="border-sidebar-border/70 flex w-full border-b">
                    <div className="mx-auto flex h-12 w-full items-center justify-start px-4 text-neutral-500 md:container">
                        <Breadcrumbs breadcrumbs={breadcrumbs} />
                    </div>
                </div>
            )}
        </>
    );
}
