import { toastWarning } from '@/components/app-toast-messages';
import { generateEan13 } from '@/components/ean13-barcode';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { MoneyInput } from '@/components/ui/money-input';
import { Switch } from '@/components/ui/switch';
import { Tooltip, TooltipContent, TooltipTrigger } from '@/components/ui/tooltip';
import { cn } from '@/lib/utils';
import { apios } from '@/Utils/connectApi';
import { partsType } from '@/Utils/dataSelect';
import { useForm } from '@inertiajs/react';
import { Barcode, Info, Save, UploadCloud } from 'lucide-react';
import { DragEvent, useEffect, useRef, useState } from 'react';

interface PartFormProps {
    categories: any;
    manufacturers: any;
    initialData?: any;
    fiscalNfeEnabled?: boolean;
}

export default function PartForm({ initialData }: PartFormProps) {
    const isEdit = !!initialData;
    const [disableInput, setDisableInput] = useState(false);
    const [isDraggingImage, setIsDraggingImage] = useState(false);
    const [imagePreview, setImagePreview] = useState<string | null>(null);
    const imageInputRef = useRef<HTMLInputElement>(null);

    const generateReferenceNumber = () => generateEan13(String(Date.now()).slice(-12));

    const { data, setData, post, progress, processing, reset, errors } = useForm({
        _method: isEdit ? 'put' : '',
        category: initialData?.category ?? '',
        type: initialData?.type ?? '',
        is_sellable: Boolean(initialData?.is_sellable ?? false),
        reference_number: initialData?.reference_number ?? '',
        name: initialData?.name ?? '',
        description: initialData?.description ?? '',
        image: null as File | null,
        ncm: initialData?.ncm ?? '',
        cfop: initialData?.cfop ?? '',
        manufacturer: initialData?.manufacturer ?? '',
        model_compatibility: initialData?.model_compatibility ?? '',
        cost_price: initialData?.cost_price ?? '',
        sale_price: initialData?.sale_price ?? '',
        quantity: initialData?.quantity ?? '',
        minimum_stock_level: initialData?.minimum_stock_level ?? '',
        location: initialData?.location ?? '',
        status: Boolean(initialData?.status ?? true),
    });

    const defaultPartsType = partsType?.filter((part: any) => Number(part.value) === initialData?.type);
    const [selectedPartsType, setSelectedPartsType] = useState<any>(defaultPartsType);
    const [insertStock, setInsertStock] = useState<any>(false);
    const currentImageSrc = initialData?.image ? `/storage/parts/${initialData.image}` : '/images/default.png';

    const handleImageFile = (file?: File) => {
        if (!file) return;

        if (!file.type.startsWith('image/')) {
            toastWarning('Erro', 'Apenas imagens são permitidas.');
            return;
        }

        if (file.size > 2 * 1024 * 1024) {
            toastWarning('Erro', 'A imagem deve ter no máximo 2 MB.');
            return;
        }

        if (imagePreview) {
            URL.revokeObjectURL(imagePreview);
        }

        setData('image', file);
        setImagePreview(URL.createObjectURL(file));
    };

    const handleImageDrop = (e: DragEvent<HTMLDivElement>) => {
        e.preventDefault();
        setIsDraggingImage(false);
        handleImageFile(e.dataTransfer.files?.[0]);
    };

    const handleSubmit = (e: any) => {
        e.preventDefault();

        if (isEdit) {
            post(route('app.parts.update', initialData.id), {
                forceFormData: true,
            });
        } else {
            post(route('app.parts.store'), {
                forceFormData: true,
            });
        }
    };

    const partNumberDataSelected = async (e: any) => {
        e.preventDefault();
        const valuePartNumber = e.target.value;

        try {
            const { data: response } = await apios.get(`getparts/${valuePartNumber}`);
            const { success, parts } = response;

            if (success && parts) {
                setDisableInput(true);
                setInsertStock(true);

                setData((data) => ({
                    ...data,
                    category: parts.category,
                    type: parts.type,
                    name: parts.name,
                    description: parts.description,
                    image: null,
                    ncm: parts.ncm ?? '',
                    cfop: parts.cfop ?? '',
                    manufacturer: parts.manufacturer,
                    model_compatibility: parts.model_compatibility,
                    cost_price: parts.cost_price,
                    sale_price: parts.sale_price,
                    quantity: '0',
                    minimum_stock_level: parts.minimum_stock_level,
                    location: parts.location,
                    status: parts.status,
                    is_sellable: Boolean(parts.is_sellable),
                }));
            } else {
                setDisableInput(false);

                reset('name', 'description', 'manufacturer', 'model_compatibility', 'quantity', 'location', 'type');
                setData((data) => ({ ...data, image: null, is_sellable: false, status: true }));
            }
        } catch (error) {
            console.error(error);
        }
    };

    useEffect(() => {
        if (!isEdit && !data.reference_number) {
            setData('reference_number', generateReferenceNumber());
        }
    }, [isEdit]);

    useEffect(() => {
        return () => {
            if (imagePreview) {
                URL.revokeObjectURL(imagePreview);
            }
        };
    }, [imagePreview]);

    return (
        <form onSubmit={handleSubmit} autoComplete="off" className="space-y-8">
            <Card>
                <CardTitle className="border-b px-6 pb-4">Identificação</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="mt-4 grid gap-4 md:grid-cols-4">
                        <div className="grid gap-2">
                            <div className="flex items-center gap-2">
                                <Label htmlFor="name">Número da Peça/Produto</Label>
                                {!isEdit && (
                                    <Tooltip>
                                        <TooltipTrigger asChild>
                                            <button
                                                type="button"
                                                className="text-muted-foreground hover:text-foreground"
                                                aria-label="Informação sobre código gerado"
                                            >
                                                <Info className="h-4 w-4" />
                                            </button>
                                        </TooltipTrigger>
                                        <TooltipContent>
                                            <p>Código gerado automaticamente para uso como código de barras.</p>
                                        </TooltipContent>
                                    </Tooltip>
                                )}
                            </div>
                            <div className="flex gap-2">
                                <Input
                                    type="text"
                                    id="reference_number"
                                    value={data.reference_number}
                                    onChange={(e) => setData('reference_number', e.target.value)}
                                    onBlur={(e) => partNumberDataSelected(e)}
                                    maxLength={18}
                                />
                                {!isEdit && (
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() => setData('reference_number', generateReferenceNumber())}
                                        title="Gerar código de barras"
                                    >
                                        <Barcode className="h-4 w-4" />
                                    </Button>
                                )}
                            </div>
                            {errors.reference_number && <div className="text-sm text-red-500">{errors.reference_number}</div>}
                        </div>

                        <div className="grid gap-2">
                            <div className="flex items-center justify-between">
                                <Label>Tipo</Label>

                                <span className={`text-xs ${!data.type ? 'text-amber-600' : 'text-muted-foreground'}`}>
                                    {!data.type && 'Selecione o tipo do item'}
                                    {data.type === 'part' && 'Item técnico (uso interno ou venda)'}
                                    {data.type === 'product' && 'Produto final para venda'}
                                </span>
                            </div>

                            {/* Tipo (identidade) */}
                            <div className="flex items-center justify-between gap-6 rounded-md border p-2.5">
                                <div className="flex items-center justify-between gap-4">
                                    <label className="flex cursor-pointer items-center gap-2">
                                        <input type="radio" name="type" checked={data.type === 'part'} onChange={() => setData('type', 'part')} />
                                        <span className="text-sm">Peça</span>
                                    </label>

                                    <label className="flex cursor-pointer items-center gap-2">
                                        <input
                                            type="radio"
                                            name="type"
                                            checked={data.type === 'product'}
                                            onChange={() => setData('type', 'product')}
                                        />
                                        <span className="text-sm">Produto</span>
                                    </label>
                                </div>

                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-muted-foreground text-sm">Para venda</span>

                                    <Switch checked={Boolean(data.is_sellable)} onCheckedChange={(checked) => setData('is_sellable', checked)} />
                                </div>
                            </div>

                            {/* Pode ser vendido */}

                            {errors.type && <div className="text-sm text-red-500">{errors.type}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="category">Categoria</Label>
                            <Input
                                type="text"
                                id="category"
                                value={data.category}
                                onChange={(e) => setData('category', e.target.value)}
                                placeholder="Digite a categoria"
                                readOnly={disableInput}
                            />
                            <InputError message={errors.category} />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="manufacturer">Fabricante</Label>
                            <Input
                                type="text"
                                id="manufacturer"
                                value={data.manufacturer}
                                onChange={(e) => setData('manufacturer', e.target.value)}
                                placeholder="Digite o fabricante"
                                readOnly={disableInput}
                            />
                            <InputError message={errors.manufacturer} />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Descrição e compatibilidade</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="mt-4 grid gap-4 md:grid-cols-3">
                        <div className="grid gap-2">
                            <Label htmlFor="name">Nome da Peça/Produto</Label>
                            <Input
                                type="text"
                                id="name"
                                value={data.name}
                                onChange={(e) => setData('name', e.target.value)}
                                readOnly={disableInput}
                            />
                            {errors.name && <div className="text-sm text-red-500">{errors.name}</div>}
                        </div>

                        <div className="grid gap-2 md:col-span-2">
                            <Label htmlFor="description">Descrição</Label>
                            <Input
                                type="text"
                                id="description"
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                readOnly={disableInput}
                            />
                            {errors.description && <div className="text-sm text-red-500">{errors.description}</div>}
                        </div>
                    </div>

                    <div className="mt-4 grid gap-2">
                        <Label htmlFor="image">Imagem do produto</Label>
                        <div
                            onDragEnter={(e) => {
                                e.preventDefault();
                                if (!disableInput) setIsDraggingImage(true);
                            }}
                            onDragOver={(e) => {
                                e.preventDefault();
                                if (!disableInput) setIsDraggingImage(true);
                            }}
                            onDragLeave={(e) => {
                                e.preventDefault();
                                if (e.currentTarget.contains(e.relatedTarget as Node)) return;
                                setIsDraggingImage(false);
                            }}
                            onDrop={handleImageDrop}
                            onClick={() => !disableInput && imageInputRef.current?.click()}
                            onKeyDown={(e) => {
                                if (disableInput) return;
                                if (e.key === 'Enter' || e.key === ' ') {
                                    e.preventDefault();
                                    imageInputRef.current?.click();
                                }
                            }}
                            role="button"
                            tabIndex={disableInput ? -1 : 0}
                            aria-disabled={disableInput}
                            className={cn(
                                'border-muted-foreground/30 bg-muted/20 flex min-h-36 items-center gap-4 rounded-lg border-2 border-dashed p-4 transition-colors',
                                disableInput ? 'cursor-not-allowed opacity-70' : 'cursor-pointer',
                                isDraggingImage && 'border-primary bg-primary/5',
                            )}
                        >
                            <div className="bg-background flex h-24 w-24 shrink-0 items-center justify-center overflow-hidden rounded-md border p-2">
                                <img
                                    src={imagePreview ?? currentImageSrc}
                                    alt="Imagem do produto"
                                    className="max-h-full max-w-full object-contain"
                                    onError={(event) => {
                                        event.currentTarget.src = '/images/default.png';
                                    }}
                                />
                            </div>
                            <div className="min-w-0">
                                <UploadCloud className="text-muted-foreground mb-2 h-6 w-6" />
                                <p className="text-sm font-medium">Arraste a imagem aqui ou clique para selecionar</p>
                                <p className="text-muted-foreground mt-1 text-xs">PNG, JPG, WEBP, GIF ou SVG até 2 MB.</p>
                                {data.image && <p className="text-muted-foreground mt-2 truncate text-xs">{data.image.name}</p>}
                            </div>
                            <Input
                                ref={imageInputRef}
                                type="file"
                                id="image"
                                accept="image/*"
                                onChange={(e) => handleImageFile(e.target.files?.[0])}
                                className="sr-only"
                                disabled={disableInput}
                            />
                        </div>
                        <InputError message={errors.image} />
                    </div>

                    <div className="mt-4 grid gap-4 md:grid-cols-2">
                        <div className="grid gap-2">
                            <Label htmlFor="model_compatibility">Modelos compatíveis</Label>
                            <Input
                                type="text"
                                id="model_compatibility"
                                value={data.model_compatibility}
                                onChange={(e) => setData('model_compatibility', e.target.value)}
                                readOnly={disableInput}
                            />
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="location">Local de Armazenamento</Label>
                            <Input
                                type="text"
                                id="location"
                                value={data.location}
                                onChange={(e) => setData('location', e.target.value)}
                                readOnly={disableInput}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card>
                <CardTitle className="border-b px-6 pb-4">Valores e estoque</CardTitle>
                <CardContent className="space-y-4 pt-6">
                    <div className="mt-4 grid gap-4 md:grid-cols-4">
                        <div className="grid gap-2">
                            <Label htmlFor="cost_price">Preço de Custo</Label>
                            <MoneyInput
                                id="cost_price"
                                value={data.cost_price}
                                onValueChange={(value) => setData('cost_price', value)}
                                readOnly={disableInput}
                                placeholder="0,00"
                                error={errors.cost_price}
                            />
                            {errors.cost_price && <div className="text-sm text-red-500">{errors.cost_price}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="sale_price">Preço de Venda</Label>
                            <MoneyInput
                                id="sale_price"
                                value={data.sale_price}
                                onValueChange={(value) => setData('sale_price', value)}
                                readOnly={disableInput}
                                placeholder="0,00"
                                error={errors.sale_price}
                            />
                            {errors.sale_price && <div className="text-sm text-red-500">{errors.sale_price}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="quantity">
                                {insertStock ? 'Inserir ao Estoque' : isEdit ? 'Alterar Estoque' : 'Quantidade do Estoque'}
                            </Label>
                            <Input
                                type="text"
                                id="quantity"
                                value={data.quantity}
                                onChange={(e) => setData('quantity', e.target.value)}
                                placeholder="0"
                            />
                            {errors.quantity && <div className="text-sm text-red-500">{errors.quantity}</div>}
                        </div>

                        <div className="grid gap-2">
                            <Label htmlFor="minimum_stock_level">Estoque Mínimo</Label>
                            <Input
                                type="text"
                                id="minimum_stock_level"
                                value={data.minimum_stock_level}
                                onChange={(e) => setData('minimum_stock_level', e.target.value)}
                                readOnly={disableInput}
                                placeholder="0"
                            />
                            {errors.minimum_stock_level && <div className="text-sm text-red-500">{errors.minimum_stock_level}</div>}
                        </div>
                    </div>

                    <div className="grid gap-2">
                        <Label htmlFor="status">Status da Peça</Label>

                        <div className="flex items-center gap-3">
                            <Switch id="status" checked={data.status} onCheckedChange={(checked) => setData('status', checked)} />

                            <span className="text-muted-foreground text-sm">{data.status ? 'Ativa' : 'Inativa'}</span>
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div className="flex justify-end">
                <Button type="submit" disabled={processing}>
                    <Save />
                    Salvar
                </Button>
            </div>
        </form>
    );
}
