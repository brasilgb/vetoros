import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type TenantTab = 'general' | 'subscription' | 'finance' | 'modules' | 'fiscal';

const TABS: TenantTab[] = ['general', 'subscription', 'finance', 'modules', 'fiscal'];

const readHash = (fallback: TenantTab): TenantTab => {
    if (typeof window === 'undefined') return fallback;
    const hash = window.location.hash.replace('#', '') as TenantTab;
    return TABS.includes(hash) ? hash : fallback;
};

/**
 * Aba atual da empresa no RootAdmin, guardada no endereço (#aba): recarregar ou voltar mantém a aba.
 * Erros de validação levam à primeira aba com problema.
 */
export function useTenantTabs(initial: TenantTab) {
    const [tab, setTabState] = useState<TenantTab>(() => readHash(initial));
    const current = useRef(tab);
    useEffect(() => {
        current.current = tab;
    }, [tab]);

    // Salvar redireciona (Inertia troca a URL e perde o #aba): reaplica a aba atual depois de cada navegação.
    useEffect(() => {
        const page = window.location.pathname;
        // No fim de cada visita (o Inertia grava a URL nova depois dos eventos de navegação).
        return router.on('finish', () => {
            window.setTimeout(() => {
                if (window.location.pathname === page && window.location.hash !== `#${current.current}`) {
                    window.history.replaceState(window.history.state, '', `#${current.current}`);
                }
            }, 50);
        });
    }, []);

    useEffect(() => {
        const onHash = () => setTabState(readHash(initial));
        window.addEventListener('hashchange', onHash);
        return () => window.removeEventListener('hashchange', onHash);
    }, [initial]);

    const setTab = (next: TenantTab) => {
        setTabState(next);
        window.history.replaceState(window.history.state, '', `#${next}`);
    };

    /** Quantidade de erros por aba, a partir do mapa campo → aba. */
    const errorTabs = (errors: Partial<Record<string, string>>, map: Record<string, TenantTab>) =>
        Object.keys(errors).reduce<Partial<Record<TenantTab, number>>>((acc, field) => {
            const target = map[field];
            if (target) acc[target] = (acc[target] ?? 0) + 1;
            return acc;
        }, {});

    const goToErrors = (errors: Partial<Record<string, string>>, map: Record<string, TenantTab>) => {
        if (Object.keys(errors).some((field) => map[field] === tab)) return;
        const first = TABS.find((candidate) => Object.keys(errors).some((field) => map[field] === candidate));
        if (first) setTab(first);
    };

    return { tab, setTab, errorTabs, goToErrors };
}

export function TabBadge({ count }: { count?: number }) {
    if (!count) return null;
    return (
        <span
            className="bg-destructive ml-1 inline-flex size-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
            aria-label={`${count} erro(s)`}
        >
            {count}
        </span>
    );
}

/** Rótulos longos quebram em duas linhas em vez de cortar o selo de erro. */
export const tabTriggerClass = 'h-auto min-h-8 whitespace-normal text-center leading-tight';
