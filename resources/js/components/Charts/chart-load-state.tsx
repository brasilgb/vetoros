import { Button } from '@/components/ui/button';

export type ChartLoad = { status: 'loading' } | { status: 'ready' } | { status: 'error'; message: string };

/**
 * Carregando/erro dos gráficos do dashboard (VETOR-STABILIZE-02). Erro da API aparece como erro, com
 * nova tentativa: nunca vira um gráfico vazio ou com zeros como se fossem dados reais.
 */
export function ChartLoadState({ load, onRetry }: { load: Exclude<ChartLoad, { status: 'ready' }>; onRetry: () => void }) {
    if (load.status === 'loading') {
        return <div className="bg-muted/40 h-[200px] w-full animate-pulse rounded-md" role="status" aria-label="Carregando gráfico" />;
    }

    return (
        <div
            role="alert"
            className="flex h-[200px] w-full flex-col items-center justify-center gap-2 rounded-md border border-dashed px-4 text-center"
        >
            <p className="text-sm font-medium">{load.message}</p>
            <Button type="button" size="sm" variant="outline" onClick={onRetry}>
                Tentar novamente
            </Button>
        </div>
    );
}
