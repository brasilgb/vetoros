import { BrandLoginLogo } from '@/components/brand-logo';
import { FlashToastMessages } from '@/components/flash-toast-messages';
import { useAppearance } from '@/hooks/use-appearance';
import { cn } from '@/lib/utils';
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
        <div className="auth-surface grid min-h-svh min-w-0 overflow-x-hidden bg-background text-foreground lg:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
            <aside className="relative hidden overflow-hidden text-white lg:flex">
                <div
                    className="absolute inset-0 bg-cover bg-center"
                    style={{ backgroundImage: `url('${brandImage}')` }}
                    aria-hidden="true"
                />
                <div className="absolute inset-0 bg-linear-to-t from-zinc-950/85 via-zinc-950/20 to-transparent" aria-hidden="true" />
                <div className="relative z-10 flex w-full flex-col justify-between p-12">
                    <Link href={route('home')} className="flex items-center">
                        <div className="flex items-center justify-center rounded-2xl bg-white p-3 shadow-md">
                            <BrandLoginLogo />
                        </div>
                    </Link>
                    <p className="max-w-sm text-3xl leading-tight font-black tracking-tight text-balance">{brandMessage}</p>
                </div>
            </aside>

            <main className="flex min-w-0 items-center justify-center px-4 py-8 sm:px-8 lg:px-12">
                <div className={cn('flex min-w-0 flex-col gap-6', width)}>
                    <div className="flex flex-col items-center lg:hidden">
                        <Link href={route('home')} className="flex flex-col items-center gap-2 font-medium">
                            <div className="flex items-center justify-center rounded-2xl bg-white p-3 shadow-md">
                                <BrandLoginLogo />
                            </div>
                        </Link>
                    </div>

                    <header className="flex flex-col gap-2 text-center">
                        <h1 className="text-2xl font-black tracking-tight text-balance text-foreground sm:text-3xl">{title}</h1>
                        <p className="mx-auto max-w-sm text-sm leading-6 text-balance text-muted-foreground">{description}</p>
                    </header>

                    <div className="min-w-0">{children}</div>
                </div>
            </main>
            <FlashToastMessages />
            <Toaster theme={appearance} />
        </div>
    );
}
