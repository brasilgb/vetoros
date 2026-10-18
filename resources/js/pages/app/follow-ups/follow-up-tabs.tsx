import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';

const TABS = [
    { label: 'Lista', route: 'app.follow-ups.index' },
    { label: 'Central de pendências', route: 'app.follow-ups.tasks' },
    { label: 'Resultados', route: 'app.follow-ups.performance' },
] as const;

/**
 * Navegação em abas entre as 3 telas de retorno ao cliente, que antes eram 3 itens
 * separados no menu principal. Cada aba é uma página/rota Inertia diferente
 * (dados e filtros distintos), não uma troca de conteúdo no client — por
 * isso usa <Link> em vez do componente Tabs baseado em Radix.
 */
export default function FollowUpTabs() {
    return (
        <div className="bg-muted text-muted-foreground mx-4 mt-3 inline-flex h-9 w-fit items-center justify-center gap-1 rounded-lg p-[3px]">
            {TABS.map((tab) => {
                const isActive = route().current(tab.route);

                return (
                    <Link
                        key={tab.route}
                        href={route(tab.route)}
                        className={cn(
                            'inline-flex h-[calc(100%-1px)] items-center justify-center rounded-md border border-transparent px-3 py-1 text-sm font-medium whitespace-nowrap transition-[color,box-shadow]',
                            isActive
                                ? 'bg-background text-foreground dark:border-input dark:bg-input/30 shadow-sm'
                                : 'text-foreground hover:text-foreground dark:text-muted-foreground',
                        )}
                    >
                        {tab.label}
                    </Link>
                );
            })}
        </div>
    );
}
