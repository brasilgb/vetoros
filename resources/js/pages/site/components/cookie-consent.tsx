import { Link } from '@inertiajs/react';
import { Cookie } from 'lucide-react';
import { useEffect, useState } from 'react';

// Incrementar quando as finalidades ou categorias de cookies mudarem de forma
// material: decisões de versões anteriores deixam de valer e o banner volta.
const CONSENT_VERSION = 1;
export const CONSENT_KEY = 'vetoros-cookie-consent';
const LEGACY_KEY = 'vetoros_cookie_consent';
const OPEN_EVENT = 'vetoros:open-cookie-preferences';

type Choice = 'accepted' | 'declined';

// Sem armazenamento disponível, a decisão vale ao menos até recarregar a página.
let memoryChoice: Choice | null = null;

function readChoice(): Choice | null {
    try {
        const stored = window.localStorage.getItem(CONSENT_KEY);
        if (stored) {
            const parsed = JSON.parse(stored) as { choice?: string; version?: number };
            return parsed.version === CONSENT_VERSION && (parsed.choice === 'accepted' || parsed.choice === 'declined') ? parsed.choice : null;
        }

        // Escolhas explícitas do banner anterior continuam válidas.
        const legacy = window.localStorage.getItem(LEGACY_KEY);
        if (legacy === 'accepted') return 'accepted';
        if (legacy === 'declined') return 'declined';
    } catch {
        return memoryChoice;
    }

    return memoryChoice;
}

export function optionalConsent(): boolean {
    return readChoice() === 'accepted';
}

export function openCookiePreferences() {
    window.dispatchEvent(new Event(OPEN_EVENT));
}

export function CookieConsent() {
    const [visible, setVisible] = useState(false);
    const [choice, setChoice] = useState<Choice | null>(null);

    useEffect(() => {
        const current = readChoice();
        setChoice(current);
        setVisible(current === null);

        const reopen = () => {
            setChoice(readChoice());
            setVisible(true);
        };
        window.addEventListener(OPEN_EVENT, reopen);

        return () => window.removeEventListener(OPEN_EVENT, reopen);
    }, []);

    const save = (next: Choice) => {
        memoryChoice = next;
        try {
            window.localStorage.setItem(CONSENT_KEY, JSON.stringify({ choice: next, version: CONSENT_VERSION, decidedAt: new Date().toISOString() }));
            window.localStorage.removeItem(LEGACY_KEY);
        } catch {
            // Armazenamento bloqueado: mantém a decisão em memória.
        }
        setChoice(next);
        setVisible(false);
        window.dispatchEvent(new Event('vetoros:consent-changed'));
    };

    if (!visible) return null;

    return (
        <section
            aria-label="Aviso de cookies"
            data-cookie-consent
            className="fixed right-4 bottom-[max(1rem,env(safe-area-inset-bottom))] left-4 z-[80] mx-auto max-w-[380px] rounded-2xl border border-slate-200 bg-white p-5 text-slate-900 shadow-[0_12px_40px_rgba(15,23,42,0.18)] sm:right-6 sm:bottom-6 sm:left-auto sm:mx-0 sm:w-[380px]"
        >
            <div className="flex items-start gap-3">
                <span className="grid size-10 shrink-0 place-items-center rounded-full bg-blue-50 text-blue-700">
                    <Cookie className="size-5" aria-hidden="true" />
                </span>
                <p className="text-sm leading-6 text-slate-600">
                    Usamos cookies para melhorar sua experiência e entender como você usa o site. Você pode aceitar ou recusar cookies opcionais. Saiba mais em nossa{' '}
                    <Link href="/privacidade" className="font-semibold text-blue-700 underline underline-offset-2 hover:text-blue-800">
                        Política de Privacidade
                    </Link>
                    .
                </p>
            </div>
            {choice && <p className="mt-3 text-xs text-slate-500">Escolha atual: cookies opcionais {choice === 'accepted' ? 'aceitos' : 'recusados'}.</p>}
            <div className="mt-4 grid grid-cols-2 gap-2">
                <button
                    type="button"
                    onClick={() => save('accepted')}
                    className="h-11 rounded-xl bg-blue-700 px-4 text-sm font-bold text-white transition hover:bg-blue-800 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-blue-700"
                >
                    Aceitar
                </button>
                <button
                    type="button"
                    onClick={() => save('declined')}
                    className="h-11 rounded-xl border border-slate-300 bg-slate-50 px-4 text-sm font-bold text-slate-700 transition hover:bg-slate-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-500"
                >
                    Recusar
                </button>
            </div>
        </section>
    );
}

export function CookiePreferencesButton({ className }: { className?: string }) {
    return (
        <button type="button" onClick={openCookiePreferences} className={className}>
            Preferências de cookies
        </button>
    );
}
