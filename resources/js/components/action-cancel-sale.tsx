import InputError from '@/components/input-error';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { useForm } from '@inertiajs/react';
import { Ban } from 'lucide-react';

export default function ActionCancelSale({ saleId, disabled }: { saleId: number; disabled: boolean }) {
    const { post, processing, data, setData, errors, reset } = useForm({
        cancel_reason: '',
    });

    const handleCancel = () => {
        post(route('app.sales.cancel', saleId), {
            preserveScroll: true,
            onSuccess: () => reset(),
        });
    };

    return (
        <AlertDialog>
            <AlertDialogTrigger asChild>
                <Button variant="destructive" size="icon" title="Cancelar Venda" disabled={!disabled}>
                    <Ban className="h-4 w-4" />
                </Button>
            </AlertDialogTrigger>

            <AlertDialogContent>
                <AlertDialogHeader>
                    <AlertDialogTitle>Cancelar venda?</AlertDialogTitle>
                    <AlertDialogDescription>
                        Esta ação irá cancelar a venda e devolver os itens ao estoque. Essa operação não poderá ser desfeita.
                    </AlertDialogDescription>
                    <div className="space-y-2">
                        <Textarea
                            placeholder="Informe o motivo do cancelamento (mínimo 8 caracteres)"
                            value={data.cancel_reason}
                            onChange={(e) => setData('cancel_reason', e.target.value)}
                        />
                        <InputError message={errors.cancel_reason} />
                    </div>
                </AlertDialogHeader>

                <AlertDialogFooter>
                    <AlertDialogCancel>Voltar</AlertDialogCancel>
                    <AlertDialogAction
                        onClick={handleCancel}
                        disabled={processing || String(data.cancel_reason || '').trim().length < 8}
                        className="bg-red-600 hover:bg-red-700"
                    >
                        Confirmar Cancelamento
                    </AlertDialogAction>
                </AlertDialogFooter>
            </AlertDialogContent>
        </AlertDialog>
    );
}
