import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

export type ChartLoad = { status: 'loading' } | { status: 'ready' } | { status: 'error'; message: string };

/**
 * Carregando/erro dos gráficos e indicadores do dashboard (VETOR-STABILIZE-02/03). Erro da API aparece
 * como erro, com nova tentativa: nunca vira um gráfico vazio ou valores zerados como se fossem dados
 * reais. `className` ajusta a altura ao bloco substituído (padrão: a altura dos gráficos, 200px).
 */
export function ChartLoadState({
    load,
    onRetry,
    className,
    label = 'gráfico',
}: {
    load: Exclude<ChartLoad, { status: 'ready' }>;
    onRetry: () => void;
    className?: string;
    label?: string;
}) {
    if (load.status === 'loading') {
        return (
            <div
                className={cn('bg-muted/40 h-[200px] w-full animate-pulse rounded-md', className)}
                role="status"
                aria-label={`Carregando ${label}`}
            />
        );
    }

    return (
        <div
            role="alert"
            className={cn(
                'flex h-[200px] w-full flex-col items-center justify-center gap-2 rounded-md border border-dashed px-4 text-center',
                className,
            )}
        >
            <p className="text-sm font-medium">{load.message}</p>
            <Button type="button" size="sm" variant="outline" onClick={onRetry}>
                Tentar novamente
            </Button>
        </div>
    );
}
