import { Button } from '@/components/ui/button';
import { router, usePage } from '@inertiajs/react';
import { LifeBuoy, LogOut } from 'lucide-react';
import moment from 'moment';
import { useState } from 'react';

type SupportProp = { tenant: string | null; started_at: string | null; expires_at: string | null } | null;

/**
 * Faixa do Modo Suporte (VETOR-STABILIZE-03): discreta, sempre visível no topo do painel da empresa,
 * com a empresa, a indicação de acesso administrativo e "Encerrar suporte".
 */
export function SupportModeBanner() {
    const { support } = usePage<{ support?: SupportProp }>().props;
    const [ending, setEnding] = useState(false);

    if (!support) return null;

    const endSupport = () => {
        setEnding(true);
        router.post(route('support.end'), {}, { onFinish: () => setEnding(false) });
    };

    return (
        <div
            role="status"
            aria-label="Modo Suporte ativo"
            className="sticky top-0 z-30 flex flex-wrap items-center justify-between gap-2 border-b border-amber-300 bg-amber-50 px-4 py-2 text-sm text-amber-900 dark:border-amber-800 dark:bg-amber-950 dark:text-amber-100"
        >
            <div className="flex min-w-0 items-center gap-2">
                <LifeBuoy className="size-4 shrink-0" aria-hidden="true" />
                <span className="min-w-0 truncate">
                    <strong>Modo Suporte</strong> — Empresa: <strong>{support.tenant ?? '—'}</strong>
                </span>
                <span className="hidden text-xs text-amber-800/80 sm:inline dark:text-amber-200/80">
                    · acesso administrativo{support.expires_at ? ` até ${moment(support.expires_at).format('HH:mm')}` : ''}
                </span>
            </div>
            <Button
                type="button"
                size="sm"
                variant="outline"
                className="h-7 border-amber-400 bg-transparent text-amber-900 hover:bg-amber-100 dark:border-amber-700 dark:text-amber-100 dark:hover:bg-amber-900"
                disabled={ending}
                onClick={endSupport}
            >
                <LogOut className="size-3.5" />
                Encerrar suporte
            </Button>
        </div>
    );
}
