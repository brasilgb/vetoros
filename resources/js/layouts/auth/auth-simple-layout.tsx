import { BrandLoginLogo } from '@/components/brand-logo';
import { FlashToastMessages } from '@/components/flash-toast-messages';
import { useAppearance } from '@/hooks/use-appearance';
import { Link } from '@inertiajs/react';
import { type PropsWithChildren } from 'react';
import { Toaster } from 'sonner';

interface AuthLayoutProps {
    name?: string;
    title?: string;
    description?: string;
    width?: string;
}

const brandImage = '/images/vetoros-hero-maintenance.png';
const brandMessage = 'Sua assistência técnica mais organizada e produtiva.';

export default function AuthSimpleLayout({ children, title, description, width = 'w-full max-w-md' }: PropsWithChildren<AuthLayoutProps>) {
    const { appearance } = useAppearance();

    return (
        <div className="auth-surface auth-shell" style={{ backgroundImage: `url('${brandImage}')` }}>
            <div className="auth-overlay" aria-hidden="true" />
            <main className={`auth-card ${width.includes('max-w-4xl') ? 'auth-card--wide' : ''}`}>
                <Link href={route('home')} className="auth-brand" aria-label="VetorOS: início">
                    <BrandLoginLogo />
                </Link>
                <header className="auth-header">
                    <p className="auth-brand-message">{brandMessage}</p>
                    <h1>{title}</h1>
                    <p className="auth-description">{description}</p>
                </header>
                <div className="auth-content">{children}</div>
            </main>
            <FlashToastMessages />
            <Toaster theme={appearance} />
        </div>
    );
}
