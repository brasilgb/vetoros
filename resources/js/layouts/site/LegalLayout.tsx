import { BrandHorizontalLogo } from '@/components/brand-logo';
import { CookieConsent, CookiePreferencesButton } from '@/pages/site/components/cookie-consent';
import { Link } from '@inertiajs/react';

export default function LegalLayout({ children }: { children: React.ReactNode }) {
    const productName = import.meta.env.VITE_PUBLIC_PRODUCT_NAME || 'VetorOS';
    return (
        <div className="bg-background flex min-h-screen min-w-0 flex-col overflow-x-hidden">
            {/* Header */}
            <header className="border-b border-white/10 bg-[#0B1220] text-white">
                <div className="mx-auto flex max-w-5xl min-w-0 items-center justify-between px-4 py-4">
                    <Link href="/" className="flex items-center gap-2">
                        <BrandHorizontalLogo />
                    </Link>
                </div>
            </header>

            {/* Conteúdo */}
            <main className="flex-1">
                <div className="mx-auto max-w-3xl min-w-0 px-4 py-8 sm:py-12">{children}</div>
            </main>

            {/* Footer */}
            <footer className="border-border border-t">
                <div className="text-muted-foreground mx-auto max-w-5xl min-w-0 px-4 py-6 text-sm">
                    <nav aria-label="Informações legais" className="mb-5 flex justify-center gap-6 sm:justify-end">
                        <Link href="/privacidade">Privacidade</Link>
                        <Link href="/termos">Termos</Link>
                        <CookiePreferencesButton />
                    </nav>
                    <div className="flex flex-col items-center gap-3 border-t border-border pt-5 text-center sm:flex-row sm:justify-between sm:text-left">
                        <p>© {new Date().getFullYear()} {productName}. Todos os direitos reservados.</p>
                        <p>Desenvolvido por <a href="https://abrasilsistemas.com.br" target="_blank" rel="noopener noreferrer" className="font-semibold underline-offset-4 hover:underline focus-visible:underline">ABrasil Sistemas</a></p>
                    </div>
                </div>
            </footer>

            <CookieConsent />
        </div>
    );
}
