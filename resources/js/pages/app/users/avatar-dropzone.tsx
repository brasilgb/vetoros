import { toastWarning } from '@/components/app-toast-messages';
import InputError from '@/components/input-error';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { useInitials } from '@/hooks/use-initials';
import { cn } from '@/lib/utils';
import { UploadCloud, X } from 'lucide-react';
import { DragEvent, useEffect, useRef, useState } from 'react';

const MAX_FILE_SIZE = 2 * 1024 * 1024;
const ACCEPTED_TYPES = ['image/jpeg', 'image/png', 'image/webp'];

interface AvatarDropzoneProps {
    file: File | null;
    name: string;
    existingUrl?: string | null;
    error?: string;
    onChange: (file: File | null) => void;
}

export default function AvatarDropzone({ file, name, existingUrl, error, onChange }: AvatarDropzoneProps) {
    const inputRef = useRef<HTMLInputElement>(null);
    const getInitials = useInitials();
    const [isDragging, setIsDragging] = useState(false);
    const [localError, setLocalError] = useState('');
    const [previewUrl, setPreviewUrl] = useState<string | null>(null);

    useEffect(() => {
        if (!file) {
            setPreviewUrl(null);
            return;
        }

        const objectUrl = URL.createObjectURL(file);
        setPreviewUrl(objectUrl);

        return () => URL.revokeObjectURL(objectUrl);
    }, [file]);

    const selectFile = (selectedFile?: File) => {
        if (!selectedFile) return;

        if (!ACCEPTED_TYPES.includes(selectedFile.type)) {
            const message = 'Selecione uma imagem JPG, PNG ou WebP.';
            setLocalError(message);
            toastWarning('Erro', message);
            return;
        }

        if (selectedFile.size > MAX_FILE_SIZE) {
            const message = 'A imagem deve ter no máximo 2 MB.';
            setLocalError(message);
            toastWarning('Erro', message);
            return;
        }

        setLocalError('');
        onChange(selectedFile);
    };

    const handleDrop = (event: DragEvent<HTMLDivElement>) => {
        event.preventDefault();
        setIsDragging(false);
        selectFile(event.dataTransfer.files?.[0]);
    };

    const clearFile = () => {
        setLocalError('');
        onChange(null);
        if (inputRef.current) inputRef.current.value = '';
    };

    return (
        <div className="grid gap-2">
            <div
                role="button"
                tabIndex={0}
                onClick={() => inputRef.current?.click()}
                onKeyDown={(event) => {
                    if (event.key === 'Enter' || event.key === ' ') {
                        event.preventDefault();
                        inputRef.current?.click();
                    }
                }}
                onDragEnter={(event) => {
                    event.preventDefault();
                    setIsDragging(true);
                }}
                onDragOver={(event) => event.preventDefault()}
                onDragLeave={(event) => {
                    event.preventDefault();
                    if (!event.currentTarget.contains(event.relatedTarget as Node)) setIsDragging(false);
                }}
                onDrop={handleDrop}
                className={cn(
                    'border-muted-foreground/30 bg-muted/20 flex min-h-36 cursor-pointer items-center gap-4 rounded-lg border-2 border-dashed p-4 transition-colors',
                    isDragging && 'border-primary bg-primary/5',
                )}
            >
                <div className="bg-background flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-md border p-2">
                    <Avatar className="size-20">
                        <AvatarImage src={previewUrl ?? existingUrl ?? undefined} alt={`Foto de ${name || 'usuário'}`} />
                        <AvatarFallback>{getInitials(name || 'Usuário')}</AvatarFallback>
                    </Avatar>
                </div>

                <div className="min-w-0 flex-1">
                    <UploadCloud className="text-muted-foreground mb-2 h-6 w-6" />
                    <p className="text-sm font-medium">{isDragging ? 'Solte a imagem aqui' : 'Arraste a imagem aqui ou clique para selecionar'}</p>
                    <p className="text-muted-foreground mt-1 text-xs">JPG, PNG ou WebP até 2 MB.</p>
                    {file && <p className="text-muted-foreground mt-2 truncate text-xs">{file.name}</p>}
                </div>

                {file && (
                    <button
                        type="button"
                        className="text-muted-foreground hover:text-foreground rounded-md p-2"
                        onClick={(event) => {
                            event.stopPropagation();
                            clearFile();
                        }}
                        aria-label="Remover imagem selecionada"
                        title="Remover imagem selecionada"
                    >
                        <X className="h-5 w-5" />
                    </button>
                )}
            </div>

            <input
                ref={inputRef}
                id="avatar"
                type="file"
                className="sr-only"
                accept="image/png,image/jpeg,image/webp"
                onChange={(event) => selectFile(event.target.files?.[0])}
            />
            <InputError className="mt-1" message={localError || error} />
        </div>
    );
}
