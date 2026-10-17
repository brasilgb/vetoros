import { type ChartLoad } from '@/components/Charts/chart-load-state';
import { connectBackend } from '@/Utils/connectApi';
import { useEffect, useState } from 'react';

/**
 * Busca um recurso do dashboard (VETOR-STABILIZE-03): carregando → pronto ou erro, com nova tentativa.
 * `parse` valida a resposta; `null` vira erro ("Resposta inesperada do servidor."), nunca dado inventado.
 * Respostas de um período anterior que cheguem depois da troca de período são descartadas.
 * `parse` deve ser estável (função de módulo): uma função nova a cada render refaria a busca.
 */
export function useDashboardResource<T>(path: string, parse: (payload: unknown) => T | null) {
    const [load, setLoad] = useState<ChartLoad>({ status: 'loading' });
    const [data, setData] = useState<T | null>(null);
    const [attempt, setAttempt] = useState(0);

    useEffect(() => {
        let current = true;
        setLoad({ status: 'loading' });

        connectBackend
            .get(path)
            .then((response) => {
                if (!current) return;
                const parsed = parse(response.data);
                if (parsed === null) {
                    setLoad({ status: 'error', message: 'Resposta inesperada do servidor.' });
                    return;
                }
                setData(parsed);
                setLoad({ status: 'ready' });
            })
            .catch((error) => {
                console.error(`Erro ao carregar ${path}`, error);
                if (current) setLoad({ status: 'error', message: 'Não foi possível carregar os dados.' });
            });

        return () => {
            current = false;
        };
    }, [path, parse, attempt]);

    return { load, data, retry: () => setAttempt((value) => value + 1) };
}
