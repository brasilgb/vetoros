import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type SubscriptionAlert = {
    status?: string;
    expires_at?: string;
    days_overdue?: number;
    grace_days_left?: number;
};

type SubscriptionModalPageProps = {
    subscription_alert?: SubscriptionAlert;
    custom_contract?: boolean;
};

const COOLDOWN_MS = 3 * 60 * 60 * 1000;
const STORAGE_KEY = 'subscription_modal_last_shown';

/**
 * Aviso de assinatura vencida no período de cortesia. O pagamento é feito na tela de assinatura,
 * pela Efí Bank (Pix, boleto ou cartão), único gateway do SaaS (VETOR-SAAS-EFI-03/04).
 * Diálogo acessível (foco preso, Esc fecha); abre no máximo a cada 3 horas.
 */
export default function SubscriptionModal() {
    const { subscription_alert, custom_contract } = usePage<SubscriptionModalPageProps>().props;
    const [isOpen, setIsOpen] = useState(false);

    useEffect(() => {
        if (subscription_alert?.status !== 'grace_period') return;

        // localStorage pode falhar (janela privada, dados bloqueados): sem ele, o aviso apenas abre.
        try {
            const lastShown = Number(localStorage.getItem(STORAGE_KEY) ?? 0);
            if (Date.now() - lastShown <= COOLDOWN_MS) return;
            localStorage.setItem(STORAGE_KEY, String(Date.now()));
        } catch {
            // segue e abre
        }
        setIsOpen(true);
    }, [subscription_alert?.status]);

    useEffect(() => {
        const openManual = () => setIsOpen(true);
        window.addEventListener('open-subscription-modal', openManual);

        return () => window.removeEventListener('open-subscription-modal', openManual);
    }, []);

    const overdue = subscription_alert?.days_overdue ?? 0;
    const graceLeft = subscription_alert?.grace_days_left ?? 0;

    return (
        <Dialog open={isOpen} onOpenChange={setIsOpen}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="text-xl text-red-600">Assinatura pendente</DialogTitle>
                    <DialogDescription className="text-gray-600">
                        Sua assinatura venceu há <strong>{overdue}</strong> {overdue === 1 ? 'dia' : 'dias'}.{' '}
                        {graceLeft > 0 ? (
                            <>
                                Você tem <strong>{graceLeft}</strong> {graceLeft === 1 ? 'dia' : 'dias'} de cortesia restantes.
                            </>
                        ) : (
                            <>Hoje é o último dia de cortesia: o acesso será bloqueado a partir de amanhã.</>
                        )}
                    </DialogDescription>
                </DialogHeader>

                <p className="text-sm text-gray-600">
                    {custom_contract
                        ? 'Sua empresa tem contrato personalizado com a ABrasil Sistemas. Pague a cobrança em aberto do contrato.'
                        : 'Pague a renovação por Pix, boleto ou cartão. A liberação é automática após a confirmação do banco.'}
                </p>

                <DialogFooter className="flex-col gap-3 sm:flex-col">
                    <Button asChild className="w-full">
                        <Link href={route('subscription.show')} onClick={() => setIsOpen(false)}>
                            Ver opções de pagamento
                        </Link>
                    </Button>
                    <Button type="button" variant="ghost" className="w-full text-gray-500" onClick={() => setIsOpen(false)}>
                        Fechar e pagar depois
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
