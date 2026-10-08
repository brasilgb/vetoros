import { Button } from '@/components/ui/button';
import { router } from '@inertiajs/react';
import axios, { AxiosError } from 'axios';
import { X } from 'lucide-react';
import { ChangeEvent, FormEvent, useState } from 'react';

interface Props {
    isOpen: boolean;
    onClose: () => void;
}

type RowStatus = 'new' | 'duplicate' | 'error';

interface PreviewRow {
    line: number;
    status: RowStatus;
    codigo: string;
    nome: string;
    messages: string[];
}

interface ImportResult {
    rows: PreviewRow[];
    summary: { found: number; new: number; duplicates: number; errors: number; ignored: number; imported?: number };
}

const STATUS_LABEL: Record<RowStatus, string> = { new: 'Novo', duplicate: 'Duplicado (ignorado)', error: 'Erro' };

/**
 * Mesmo layout da importação de clientes (import-customers-modal), com uma etapa de
 * validação: o arquivo é conferido no servidor antes de gravar, e enviado de novo na
 * confirmação (o servidor revalida tudo).
 */
export default function ImportPartsModal({ isOpen, onClose }: Props) {
    const [arquivo, setArquivo] = useState<File | null>(null);
    const [preview, setPreview] = useState<ImportResult | null>(null);
    const [result, setResult] = useState<ImportResult | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [processing, setProcessing] = useState(false);
    const [progress, setProgress] = useState<number | null>(null);

    if (!isOpen) return null;

    const close = () => {
        setArquivo(null);
        setPreview(null);
        setResult(null);
        setError(null);
        setProgress(null);
        onClose();
        if (result?.summary.imported) {
            router.reload({ only: ['parts'] });
        }
    };

    const send = async (url: string): Promise<ImportResult | null> => {
        if (!arquivo) return null;

        const form = new FormData();
        form.append('arquivo', arquivo);
        setProcessing(true);
        setError(null);
        setProgress(0);

        try {
            const response = await axios.post<ImportResult>(url, form, {
                headers: { Accept: 'application/json' },
                onUploadProgress: (event) => setProgress(event.total ? Math.round((event.loaded / event.total) * 100) : null),
            });

            return response.data;
        } catch (exception) {
            const response = (exception as AxiosError<{ message?: string; errors?: Record<string, string[]> }>).response;
            setError(response?.data?.errors?.arquivo?.[0] ?? response?.data?.message ?? 'Não foi possível processar o arquivo.');

            return null;
        } finally {
            setProcessing(false);
            setProgress(null);
        }
    };

    const validate = async (e: FormEvent) => {
        e.preventDefault();
        setResult(null);
        setPreview(await send(route('app.parts.import.preview')));
    };

    const confirm = async () => {
        const imported = await send(route('app.parts.import.store'));
        if (imported) {
            setPreview(null);
            setResult(imported);
        }
    };

    const summary = result?.summary ?? preview?.summary;
    const problems = (result ?? preview)?.rows.filter((row) => row.status !== 'new') ?? [];

    return (
        <div
            className="bg-opacity-50 fixed inset-0 z-50 flex items-center justify-center bg-black"
            role="dialog"
            aria-modal="true"
            aria-labelledby="import-parts-title"
        >
            <div className="max-h-[90vh] w-full max-w-lg overflow-y-auto rounded-lg bg-white p-6 shadow-xl">
                <div className="mb-4 flex items-center justify-between">
                    <h3 id="import-parts-title" className="text-secondary text-lg font-bold">
                        Importar CSV de Produtos
                    </h3>
                    <Button variant={'link'} onClick={close} aria-label="Fechar">
                        <X className="h-4 w-4" />
                    </Button>
                </div>

                <form onSubmit={validate}>
                    <div className="mb-4">
                        <p className="pb-1 text-sm text-red-400 italic">
                            Use o modelo: separador ponto e vírgula ( ; ), valores como 1.234,56. Produtos com código já cadastrado são ignorados.
                        </p>
                        <label htmlFor="import-parts-file" className="mb-2 block text-sm font-medium text-gray-700">
                            Selecione o arquivo .csv
                        </label>
                        <input
                            id="import-parts-file"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e: ChangeEvent<HTMLInputElement>) => {
                                setArquivo(e.target.files?.[0] ?? null);
                                setPreview(null);
                                setResult(null);
                                setError(null);
                            }}
                            className="block w-full rounded-md border border-gray-300 p-2 text-sm text-gray-800"
                        />
                        {error && <p className="mt-1 text-xs text-red-500">{error}</p>}
                        {processing && (
                            <p className="mt-2 text-xs text-gray-600" aria-live="polite">
                                {progress !== null && progress < 100 ? `Enviando arquivo... ${progress}%` : 'Processando...'}
                            </p>
                        )}
                    </div>

                    {summary && (
                        <div className="mb-4 rounded-md border border-gray-200 p-3 text-sm text-gray-800" aria-live="polite">
                            {result ? (
                                <p className="font-semibold text-green-700">{summary.imported} produto(s) importado(s).</p>
                            ) : (
                                <p className="font-semibold">Prévia: nada foi gravado ainda.</p>
                            )}
                            <ul className="mt-1 grid grid-cols-2 gap-x-4 text-xs">
                                <li>Encontrados: {summary.found}</li>
                                <li>Novos: {summary.new}</li>
                                <li>Duplicados: {summary.duplicates}</li>
                                <li>Com erro: {summary.errors}</li>
                            </ul>
                            {problems.length > 0 && (
                                <ul className="mt-2 max-h-48 space-y-1 overflow-y-auto border-t pt-2 text-xs">
                                    {problems.map((row) => (
                                        <li key={row.line}>
                                            <span className={row.status === 'error' ? 'font-medium text-red-600' : 'font-medium text-amber-700'}>
                                                Linha {row.line} · {STATUS_LABEL[row.status]}
                                            </span>
                                            {row.codigo ? ` · ${row.codigo}` : ''}: {row.messages.join(' ')}
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                    )}

                    <div className="flex flex-wrap justify-end gap-2">
                        <Button variant={'destructive'} type="button" asChild>
                            <a href={route('app.parts.import.template')}>Baixar modelo .csv</a>
                        </Button>
                        <Button variant={'secondary'} type="button" onClick={close} className="px-4 py-2 text-sm">
                            {result ? 'Fechar' : 'Cancelar'}
                        </Button>
                        {preview && preview.summary.new > 0 ? (
                            <Button variant={'default'} type="button" onClick={confirm} disabled={processing} className="px-4 py-2 text-sm">
                                {processing ? 'Importando...' : `Confirmar Importação (${preview.summary.new})`}
                            </Button>
                        ) : (
                            !result && (
                                <Button variant={'default'} type="submit" disabled={processing || !arquivo} className="px-4 py-2 text-sm">
                                    {processing ? 'Validando...' : 'Validar arquivo'}
                                </Button>
                            )
                        )}
                    </div>
                </form>
            </div>
        </div>
    );
}
