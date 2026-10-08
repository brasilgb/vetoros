import ActionDelete from '@/components/action-delete';
import AppPagination, { PaginationSummary } from '@/components/app-pagination';
import { normalizeScannedEan13 } from '@/components/ean13-barcode';
import { Icon } from '@/components/icon';
import InputSearch from '@/components/inputSearch';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Table, TableBody, TableCell, TableFooter, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { BreadcrumbItem } from '@/types';
import { maskMoney } from '@/Utils/mask';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Barcode, Camera, Edit, PackageCheck, Plus, Printer, ShoppingCart, Upload } from 'lucide-react';
import moment from 'moment';
import { useCallback, useEffect, useRef, useState } from 'react';
import ImportPartsModal from './import-parts-modal';

declare global {
    interface Window {
        BarcodeDetector?: new (options?: { formats?: string[] }) => {
            detect: (source: CanvasImageSource) => Promise<Array<{ rawValue?: string }>>;
        };
    }
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Painel',
        href: route('app.dashboard'),
    },
    {
        title: 'Peças e produtos',
        href: '#',
    },
];

export default function Parts({ parts, search, filter }: any) {
    const { auth } = usePage<{ auth?: { permissions?: string[] } }>().props;
    const canManageParts = auth?.permissions?.includes('parts');
    const canPurchase = auth?.permissions?.includes('purchase_orders');
    const barcodeForm = useForm({
        barcode: '',
    });
    const [cameraOpen, setCameraOpen] = useState(false);
    const [importOpen, setImportOpen] = useState(false);
    const [cameraError, setCameraError] = useState<string | null>(null);
    const [cameraSupported, setCameraSupported] = useState(false);
    const [isMobileDevice, setIsMobileDevice] = useState(false);
    const videoRef = useRef<HTMLVideoElement | null>(null);
    const streamRef = useRef<MediaStream | null>(null);

    const submitBarcodeSearch = useCallback((barcode: string) => {
        const rawCode = String(barcode || '').trim();
        const code = normalizeScannedEan13(rawCode) ?? rawCode;
        if (!code) return;

        router.get(
            route('app.parts.index'),
            {
                search: code,
            },
            {
                preserveState: false,
                replace: true,
            },
        );
    }, []);

    const handleBarcodeSubmit = (e: React.FormEvent<HTMLFormElement>) => {
        e.preventDefault();
        submitBarcodeSearch(barcodeForm.data.barcode);
    };

    const stopCamera = () => {
        streamRef.current?.getTracks().forEach((track) => track.stop());
        streamRef.current = null;
    };

    useEffect(() => {
        if (typeof window === 'undefined') return;

        const hasCameraApi = 'BarcodeDetector' in window && !!navigator.mediaDevices?.getUserMedia;
        const mobileByViewport = window.matchMedia('(max-width: 768px)').matches;
        const mobileByPointer = window.matchMedia('(pointer: coarse)').matches;
        const mobileByUserAgent = /Android|iPhone|iPad|iPod|Mobile/i.test(window.navigator.userAgent);

        setCameraSupported(hasCameraApi);
        setIsMobileDevice(mobileByViewport || mobileByPointer || mobileByUserAgent);
    }, []);

    useEffect(() => {
        if (!cameraOpen || !cameraSupported) return;

        let animationFrameId = 0;
        let cancelled = false;
        const detector = window.BarcodeDetector
            ? new window.BarcodeDetector({
                  formats: ['code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a', 'upc_e'],
              })
            : null;

        const scan = async () => {
            if (cancelled || !detector || !videoRef.current) return;

            try {
                const barcodes = await detector.detect(videoRef.current);
                const value = barcodes.find((item) => item.rawValue)?.rawValue?.trim();

                if (value) {
                    barcodeForm.setData('barcode', value);
                    setCameraOpen(false);
                    stopCamera();
                    submitBarcodeSearch(value);
                    return;
                }
            } catch {
                // Ignora leituras intermitentes enquanto a câmera está aberta.
            }

            animationFrameId = window.requestAnimationFrame(scan);
        };

        navigator.mediaDevices
            .getUserMedia({
                video: {
                    facingMode: { ideal: 'environment' },
                },
            })
            .then((stream) => {
                if (cancelled) {
                    stream.getTracks().forEach((track) => track.stop());
                    return;
                }

                streamRef.current = stream;

                if (videoRef.current) {
                    videoRef.current.srcObject = stream;
                    videoRef.current.play().catch(() => null);
                }

                animationFrameId = window.requestAnimationFrame(scan);
            })
            .catch(() => {
                setCameraError('Não foi possível acessar a câmera deste dispositivo.');
            });

        return () => {
            cancelled = true;
            if (animationFrameId) {
                window.cancelAnimationFrame(animationFrameId);
            }
            stopCamera();
        };
    }, [cameraOpen, cameraSupported, submitBarcodeSearch]);

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Peças" />
            <div className="flex min-h-16 flex-col justify-center gap-1 px-4 py-3">
                <div className="flex items-center gap-2">
                    <Icon iconNode={PackageCheck} className="h-8 w-8" />
                    <h2 className="text-xl font-semibold tracking-tight">Peças e produtos</h2>
                </div>
            </div>
            <div className="flex flex-col gap-3 p-4 lg:flex-row lg:items-center lg:justify-between">
                <div className="flex w-full min-w-0 flex-col gap-2 lg:max-w-[790px] lg:flex-1 lg:flex-row">
                    <div className="w-full min-w-0 lg:max-w-[420px] lg:flex-1">
                        <InputSearch placeholder="Buscar peça/produto por nome, número ou código" url="app.parts.index" />
                    </div>
                    <form onSubmit={handleBarcodeSubmit} className="w-full min-w-0 lg:max-w-[360px] lg:flex-1">
                        <div className="flex gap-2">
                            {isMobileDevice ? (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setCameraError(null);
                                        setCameraOpen(true);
                                    }}
                                    disabled={!cameraSupported}
                                    title={
                                        cameraSupported
                                            ? 'Ler código de barras com a câmera do celular'
                                            : 'Leitura por câmera indisponível neste navegador'
                                    }
                                    className="w-full"
                                >
                                    <Camera className="mr-1 h-4 w-4" />
                                    Ler com celular
                                </Button>
                            ) : (
                                <div className="relative flex-1">
                                    <Input
                                        value={barcodeForm.data.barcode}
                                        onChange={(e) => barcodeForm.setData('barcode', e.target.value)}
                                        placeholder="Ler código de barras"
                                        autoComplete="off"
                                        className="pr-12"
                                    />
                                    <Button
                                        type="submit"
                                        size="icon"
                                        className="absolute top-0 right-0 h-full rounded-l-none"
                                        title="Buscar pelo código de barras"
                                        aria-label="Buscar pelo código de barras"
                                    >
                                        <Barcode className="h-4 w-4" />
                                    </Button>
                                </div>
                            )}
                        </div>
                    </form>
                </div>
                <div className="flex w-full flex-wrap items-center gap-2 lg:w-auto lg:shrink-0 lg:justify-end">
                    <Button variant={filter === 'low_stock' ? 'default' : 'outline'} asChild className="w-full whitespace-nowrap sm:w-auto">
                        <Link href={route('app.parts.index', { search, filter: filter === 'low_stock' ? undefined : 'low_stock' })}>
                            {filter === 'low_stock' ? 'Mostrar todos' : 'Estoque baixo'}
                        </Link>
                    </Button>
                    {canManageParts && (
                        <Button onClick={() => setImportOpen(true)} className="w-full bg-green-600 text-white hover:bg-green-700 sm:w-auto">
                            <Upload className="h-4 w-4" />
                            <span>CSV</span>
                        </Button>
                    )}
                    {canManageParts && (
                        <Button variant={'default'} asChild className="w-full whitespace-nowrap sm:w-auto">
                            <Link href={route('app.parts.create')}>
                                <Plus className="h-4 w-4" />
                                <span>Nova peça ou produto</span>
                            </Link>
                        </Button>
                    )}
                </div>
            </div>

            <ImportPartsModal isOpen={importOpen} onClose={() => setImportOpen(false)} />

            {cameraError && <div className="px-4 text-sm text-red-600">{cameraError}</div>}
            {cameraOpen && (
                <div className="fixed inset-0 z-50 flex items-center justify-center bg-black/80 p-4">
                    <div className="bg-background w-full max-w-md rounded-lg p-4 shadow-xl">
                        <div className="mb-3 flex items-center justify-between gap-3">
                            <h3 className="font-semibold">Ler código de barras</h3>
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setCameraOpen(false);
                                    stopCamera();
                                }}
                            >
                                Fechar
                            </Button>
                        </div>
                        <video ref={videoRef} className="aspect-video w-full rounded-md bg-black" muted playsInline />
                    </div>
                </div>
            )}

            <div className="p-4">
                <PaginationSummary data={parts} />
                <div className="rounded-lg border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="min-w-max">#</TableHead>
                                <TableHead className="w-[72px]">Imagem</TableHead>
                                <TableHead>Item</TableHead>
                                <TableHead>Classificação</TableHead>
                                <TableHead>Valores</TableHead>
                                <TableHead>Estoque</TableHead>
                                <TableHead>Cadastro</TableHead>
                                <TableHead className="min-w-[140px]"></TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {parts?.data.length ? (
                                parts?.data?.map((part: any) => (
                                    <TableRow key={part.id}>
                                        <TableCell>{part.part_number}</TableCell>
                                        <TableCell>
                                            <div className="bg-muted flex h-12 w-12 items-center justify-center overflow-hidden rounded-md border">
                                                <img
                                                    src={part.image ? `/storage/parts/${part.image}` : '/images/default.png'}
                                                    alt={part.image ? `Imagem de ${part.name}` : 'Imagem padrão do produto'}
                                                    className="h-full w-full object-contain p-1"
                                                    onError={(event) => {
                                                        event.currentTarget.src = '/images/default.png';
                                                    }}
                                                />
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div className="font-medium">{part.name}</div>
                                                <div className="text-muted-foreground text-xs">Ref: {part.reference_number || '-'}</div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-1">
                                                <div>{part.type === 'part' ? 'Peça' : 'Produto'}</div>
                                                <div className="text-muted-foreground text-xs">
                                                    {part.category || 'Sem categoria'} · {part.is_sellable ? 'Vendável' : 'Uso interno'}
                                                </div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            <div className="space-y-1 text-sm">
                                                <div>Venda: {maskMoney(part.sale_price)}</div>
                                                <div className="text-muted-foreground text-xs">Mínimo: {part.minimum_stock_level}</div>
                                            </div>
                                        </TableCell>
                                        <TableCell>
                                            {part.quantity <= part.minimum_stock_level ? (
                                                <div className="space-y-1">
                                                    <Badge variant={'destructive'}>{part.quantity}</Badge>
                                                    <div className="text-muted-foreground text-xs">
                                                        Repor pelo menos {Math.max(1, Number(part.minimum_stock_level) - Number(part.quantity) + 1)}
                                                    </div>
                                                </div>
                                            ) : (
                                                <Badge variant={'default'}>{part.quantity}</Badge>
                                            )}
                                        </TableCell>
                                        <TableCell>{moment(part.created_at).format('DD/MM/YYYY')}</TableCell>

                                        <TableCell className="min-w-[140px]">
                                            <div className="flex flex-wrap justify-end gap-2">
                                                {canPurchase && part.quantity <= part.minimum_stock_level && (
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        className="bg-amber-500 text-white hover:bg-amber-600"
                                                        title="Comprar esta peça (estoque baixo)"
                                                    >
                                                        <Link
                                                            href={route('app.purchase-orders.create', { part_id: part.id })}
                                                            aria-label={`Comprar ${part.name}`}
                                                        >
                                                            <ShoppingCart className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                )}
                                                {canManageParts && (
                                                    <Button asChild size="icon" variant="outline" title="Imprimir etiqueta do produto">
                                                        <a
                                                            href={route('app.parts.print-label', part.id)}
                                                            target="_blank"
                                                            rel="noopener noreferrer"
                                                            aria-label={`Imprimir etiqueta de ${part.name}`}
                                                        >
                                                            <Printer className="h-4 w-4" />
                                                        </a>
                                                    </Button>
                                                )}
                                                {canManageParts && (
                                                    <Button
                                                        asChild
                                                        size="icon"
                                                        className="bg-orange-500 text-white hover:bg-orange-600"
                                                        title="Editar peça ou produto"
                                                    >
                                                        <Link
                                                            href={route('app.parts.edit', part.id)}
                                                            data={{ page: parts.current_page, search: search }}
                                                            aria-label={`Editar ${part.name}`}
                                                        >
                                                            <Edit className="h-4 w-4" />
                                                        </Link>
                                                    </Button>
                                                )}

                                                {canManageParts && <ActionDelete title={'esta peça'} url={'app.parts.destroy'} param={part.id} />}
                                            </div>
                                        </TableCell>
                                    </TableRow>
                                ))
                            ) : (
                                <TableRow>
                                    <TableCell colSpan={8} className="flex h-16 w-full items-center justify-center">
                                        Não há dados a serem mostrados no momento.
                                    </TableCell>
                                </TableRow>
                            )}
                        </TableBody>
                        <TableFooter>
                            <TableRow>
                                <TableCell colSpan={8}>
                                    <AppPagination data={parts} />
                                </TableCell>
                            </TableRow>
                        </TableFooter>
                    </Table>
                </div>
            </div>
        </AppLayout>
    );
}
