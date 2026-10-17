import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { CheckCircle2 } from 'lucide-react';

type SubscriptionPaymentSuccessProps = {
    open: boolean;
    onContinue: () => void;
};

export default function SubscriptionPaymentSuccess({ open, onContinue }: SubscriptionPaymentSuccessProps) {
    return (
        <Dialog open={open}>
            <DialogContent showCloseButton={false} className="sm:max-w-md">
                <DialogHeader className="items-center text-center">
                    <div className="mb-2 flex size-14 items-center justify-center rounded-full bg-emerald-100 text-emerald-700">
                        <CheckCircle2 className="size-8" />
                    </div>
                    <DialogTitle>Pagamento confirmado</DialogTitle>
                    <DialogDescription>
                        Obrigado pelo pagamento. Sua conta foi liberada e o VetorOS já está pronto para uso. Bom trabalho!
                    </DialogDescription>
                </DialogHeader>

                <DialogFooter className="sm:justify-center">
                    <Button type="button" onClick={onContinue} className="w-full sm:w-auto" autoFocus>
                        Continuar
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
