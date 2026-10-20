import { toastSuccess, toastWarning } from '@/components/app-toast-messages';
import HeadingSmall from '@/components/heading-small';
import { Icon } from '@/components/icon';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/react';
import { ArrowLeft, Expand, Save, UploadCloud, Wrench } from 'lucide-react';
import { ChangeEvent, DragEvent, FormEvent, useEffect, useRef, useState } from 'react';

interface ImageData {
    file: File;
    preview: string;
    id: string;
}

interface SavedImage {
    id: number;
    filename: string;
    url: string;
}

interface ImageUploadProps {
    savedimages: SavedImage[];
    orderid: string | number;
    ordernumber?: string | number | null;
    errors?: Record<string, string>;
}

const MAX_IMAGES = 8;

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Ordens',
        href: route('app.orders.index'),
    },
    {
        title: 'Imagens',
        href: '#',
    },
];
const ImageUpload = ({ savedimages, orderid, ordernumber, errors }: ImageUploadProps) => {
    const [fileKey, setFileKey] = useState(0);
    const [selectedImage, setSelectedImage] = useState<{ src: string; alt: string } | null>(null);
    const [isDragging, setIsDragging] = useState(false);
    const fileInputRef = useRef<HTMLInputElement>(null);

    const {
        data,
        post,
        delete: destroy,
        processing,
        setData,
        reset,
    } = useForm({
        images: [] as File[],
        order_id: orderid as string,
    });

    const [imagePreviews, setImagePreviews] = useState<ImageData[]>([]);
    const imagePreviewsRef = useRef<ImageData[]>([]);

    useEffect(() => {
        imagePreviewsRef.current = imagePreviews;
    }, [imagePreviews]);

    const addFiles = (files: File[]) => {
        if (!files.length) return;

        if (files.some((file) => !file.type.startsWith('image/'))) {
            toastWarning('Erro', 'Apenas imagens são permitidas.');
            return;
        }

        const savedCount = savedimages.length;
        const currentCount = data.images.length;
        const total = savedCount + currentCount + files.length;

        if (total > MAX_IMAGES) {
            toastWarning('Erro', `Esta ordem pode ter no máximo ${MAX_IMAGES} imagens no total.`);
            return;
        }

        const previews = files.map((file) => {
            const preview = URL.createObjectURL(file);
            return { file, preview, id: preview };
        });
        const nextImages = [...data.images, ...files];

        setImagePreviews((prev) => [...prev, ...previews]);
        setData('images', nextImages);
    };

    /* =========================
       HANDLE FILE CHANGE
    ========================= */
    const handleFileChange = (e: ChangeEvent<HTMLInputElement>) => {
        addFiles(Array.from(e.target.files ?? []));
        e.target.value = '';
    };

    const handleDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDragging(false);
        addFiles(Array.from(e.dataTransfer.files ?? []));
    };

    /* =========================
       REMOVE PREVIEW IMAGE
    ========================= */
    const handleRemoveImage = (id: string) => {
        const imageToRemove = imagePreviews.find((img) => img.id === id);
        if (imageToRemove) {
            URL.revokeObjectURL(imageToRemove.preview);
        }

        const nextPreviews = imagePreviews.filter((img) => img.id !== id);
        setImagePreviews(nextPreviews);
        setData(
            'images',
            nextPreviews.map((img) => img.file),
        );
    };

    /* =========================
       SUBMIT
    ========================= */
    const handleSubmit = (e: FormEvent) => {
        e.preventDefault();

        if (data.images.length === 0) {
            toastWarning('Erro', 'Selecione ao menos uma imagem.');
            return;
        }

        post(route('app.images.store'), {
            forceFormData: true,
            onSuccess: () => {
                toastSuccess('Sucesso', 'Imagens enviadas com sucesso.');
                imagePreviews.forEach((img) => URL.revokeObjectURL(img.preview));
                reset();
                setImagePreviews([]);
                setFileKey((prev) => prev + 1);
            },
            onError: (errs) => {
                Object.values(errs).forEach((msg) => {
                    toastWarning('Erro', String(msg));
                });
            },
        });
    };

    /* =========================
       DELETE IMAGE FROM DB
    ========================= */
    const handleDeleteImageBanco = (id: number) => {
        destroy(route('app.images.destroy', id));
    };

    /* =========================
       CLEAN OBJECT URLS
    ========================= */
    useEffect(() => {
        return () => {
            imagePreviewsRef.current.forEach((img) => URL.revokeObjectURL(img.preview));
        };
    }, []);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Imagens da Ordem" />

            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={Wrench} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Imagens</h2>
                </div>
            </div>
            <div className="flex items-center justify-between p-4">
                <div>
                    <Button variant={'default'} asChild>
                        <Link href={route('app.orders.index')}>
                            <ArrowLeft className="h-4 w-4" />
                            <span>Voltar</span>
                        </Link>
                    </Button>
                </div>
                <div></div>
            </div>
            <div className="p-4">
                <div className="rounded-lg border p-4">
                    <HeadingSmall title="Imagens da Ordem de Serviço" description={`Envie até ${MAX_IMAGES} imagens.`} />

                    <form onSubmit={handleSubmit} className="mt-4">
                        <div
                            onDragEnter={(e) => {
                                e.preventDefault();
                                setIsDragging(true);
                            }}
                            onDragOver={(e) => {
                                e.preventDefault();
                                setIsDragging(true);
                            }}
                            onDragLeave={(e) => {
                                e.preventDefault();
                                if (e.currentTarget.contains(e.relatedTarget as Node)) return;
                                setIsDragging(false);
                            }}
                            onDrop={handleDrop}
                            className={cn(
                                'border-muted-foreground/30 bg-muted/20 flex min-h-44 cursor-pointer flex-col items-center justify-center rounded-lg border-2 border-dashed p-6 text-center transition-colors',
                                isDragging && 'border-primary bg-primary/5',
                            )}
                            role="button"
                            tabIndex={0}
                            onClick={() => fileInputRef.current?.click()}
                            onKeyDown={(e) => {
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    fileInputRef.current?.click();
                                }
                            }}
                        >
                            <UploadCloud className="text-muted-foreground h-10 w-10" />
                            <p className="mt-3 text-sm font-medium">Arraste as imagens aqui ou clique para selecionar</p>
                            <p className="text-muted-foreground mt-1 text-xs">
                                JPG, PNG, GIF ou SVG. Restam {Math.max(0, MAX_IMAGES - savedimages.length - data.images.length)} imagem(ns).
                            </p>
                            <input
                                ref={fileInputRef}
                                key={fileKey}
                                type="file"
                                name="images[]"
                                multiple
                                accept="image/*"
                                onChange={handleFileChange}
                                className="sr-only"
                            />
                        </div>

                        {errors?.images && <p className="mt-1 text-sm text-red-500">{errors.images}</p>}

                        {/* PREVIEWS */}
                        {imagePreviews.length > 0 && (
                            <div className="mt-4 grid grid-cols-2 gap-4 md:grid-cols-4">
                                {imagePreviews.map((img, index) => (
                                    <div key={img.id} className="relative">
                                        <button
                                            type="button"
                                            onClick={() => setSelectedImage({ src: img.preview, alt: `Prévia da imagem ${index + 1}` })}
                                            className="group focus-visible:ring-ring relative block w-full cursor-zoom-in overflow-hidden rounded focus-visible:ring-2 focus-visible:outline-none"
                                            aria-label={`Visualizar prévia da imagem ${index + 1}`}
                                        >
                                            <img
                                                src={img.preview}
                                                alt=""
                                                className="h-32 w-full object-cover transition-transform group-hover:scale-105"
                                            />
                                            <span className="absolute inset-0 flex items-center justify-center rounded bg-black/0 transition-colors group-hover:bg-black/30">
                                                <Expand className="h-6 w-6 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleRemoveImage(img.id)}
                                            className="absolute top-1 right-1 z-10 h-5 w-5 rounded-full bg-red-500 text-xs text-white"
                                            aria-label="Remover imagem selecionada"
                                        >
                                            ×
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}

                        {/* IMAGENS SALVAS */}
                        {savedimages?.length > 0 && (
                            <div className="mt-6 grid grid-cols-2 gap-4 md:grid-cols-4">
                                {savedimages.map((img, index) => (
                                    <div key={img.id} className="relative">
                                        <button
                                            type="button"
                                            onClick={() =>
                                                setSelectedImage({
                                                    src: img.url,
                                                    alt: `Imagem ${index + 1} da ordem ${ordernumber ?? orderid}`,
                                                })
                                            }
                                            className="group focus-visible:ring-ring relative block w-full cursor-zoom-in overflow-hidden rounded focus-visible:ring-2 focus-visible:outline-none"
                                            aria-label={`Visualizar imagem ${index + 1}`}
                                        >
                                            <img
                                                src={img.url}
                                                alt=""
                                                className="h-32 w-full object-cover transition-transform group-hover:scale-105"
                                            />
                                            <span className="absolute inset-0 flex items-center justify-center rounded bg-black/0 transition-colors group-hover:bg-black/30">
                                                <Expand className="h-6 w-6 text-white opacity-0 transition-opacity group-hover:opacity-100" />
                                            </span>
                                        </button>
                                        <button
                                            type="button"
                                            onClick={() => handleDeleteImageBanco(img.id)}
                                            className="absolute top-1 right-1 z-10 h-5 w-5 rounded-full bg-red-600 text-xs text-white"
                                            aria-label="Excluir imagem da ordem"
                                        >
                                            ×
                                        </button>
                                    </div>
                                ))}
                            </div>
                        )}

                        <div className="mt-6 flex justify-end">
                            <Button type="submit" disabled={processing}>
                                <Save className="mr-2" />
                                Salvar
                            </Button>
                        </div>
                    </form>
                </div>
            </div>

            <Dialog open={selectedImage !== null} onOpenChange={(open) => !open && setSelectedImage(null)}>
                <DialogContent className="max-h-[95vh] max-w-5xl overflow-hidden p-4 sm:p-6">
                    <DialogHeader>
                        <DialogTitle>Visualizar imagem</DialogTitle>
                        <DialogDescription>Imagem da ordem de serviço {ordernumber ?? orderid}.</DialogDescription>
                    </DialogHeader>
                    {selectedImage && (
                        <div className="flex max-h-[78vh] items-center justify-center overflow-auto rounded-md bg-black/95 p-2">
                            <img src={selectedImage.src} alt={selectedImage.alt} className="max-h-[75vh] max-w-full object-contain" />
                        </div>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
};

export default ImageUpload;
