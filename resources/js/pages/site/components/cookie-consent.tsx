import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { Cookie } from 'lucide-react';
import { useEffect, useState } from 'react';

const CONSENT_STORAGE_KEY = 'vetoros_cookie_consent';

export function CookieConsent() {
    const [visible, setVisible] = useState(false);

    useEffect(() => {
        try {
            if (!window.localStorage.getItem(CONSENT_STORAGE_KEY)) {
                setVisible(true);
            }
        } catch {
            setVisible(true);
        }
    }, []);

    const respond = (value: 'accepted' | 'declined') => {
        try {
            window.localStorage.setItem(CONSENT_STORAGE_KEY, value);
        } catch {
            // Navegação privada ou storage bloqueado: o banner só volta a aparecer na próxima visita.
        }

        setVisible(false);
    };

    if (!visible) return null;

    return (
        <div
            role="dialog"
            data-cookie-consent-banner
            aria-live="polite"
            aria-label="Aviso de cookies"
            className="fixed bottom-5 left-5 z-40 w-[calc(100%-2.5rem)] max-w-sm rounded-2xl border border-slate-200 bg-white p-5 shadow-[0_10px_30px_rgba(0,0,0,0.15)] sm:bottom-8 sm:left-8"
        >
            <div className="flex items-start gap-3">
                <Cookie className="mt-0.5 h-5 w-5 shrink-0 text-blue-700" />

                <div className="space-y-3">
                    <p className="text-sm leading-relaxed text-slate-600">
                        Usamos cookies para melhorar sua experiência e entender como você usa o site. Ao continuar navegando, você concorda com nossa{' '}
                        <Link href="/privacidade" className="font-medium text-blue-700 underline underline-offset-2">
                            Política de Privacidade
                        </Link>
                        .
                    </p>

                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            className="flex-1 rounded-lg bg-blue-700 font-bold text-white hover:bg-blue-800"
                            onClick={() => respond('accepted')}
                        >
                            Aceitar
                        </Button>

                        <Button size="sm" variant="outline" className="flex-1 rounded-lg" onClick={() => respond('declined')}>
                            Recusar
                        </Button>
                    </div>
                </div>
            </div>
        </div>
    );
}
