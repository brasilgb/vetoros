import { BrandLoginLogo } from '@/components/brand-logo';
import { FlashToastMessages } from '@/components/flash-toast-messages';
import { Card, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { useAppearance } from '@/hooks/use-appearance';
import authImage from '@/images/auth-images.jpg';
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

export default function AuthSimpleLayout({ children, title, description, width = 'w-full max-w-md' }: PropsWithChildren<AuthLayoutProps>) {
    const { appearance } = useAppearance();

    return (
        <div className="relative flex min-h-svh min-w-0 items-center justify-center overflow-hidden bg-slate-950 p-4 sm:p-8">
            {/* Background Image */}
            <div
                className="absolute inset-0 bg-cover bg-center bg-no-repeat"
                style={{
                    backgroundImage: `url(${authImage})`,
                }}
                aria-hidden="true"
            />
            <div className="absolute inset-0 bg-linear-to-br from-slate-950/85 via-slate-950/60 to-slate-950/80" aria-hidden="true" />

            <Card
                className={cn(
                    'border-border/50 bg-background relative z-10 max-h-[calc(100svh-2rem)] min-w-0 rounded-3xl py-8 shadow-2xl shadow-slate-950/40 sm:max-h-[calc(100svh-4rem)] sm:py-10',
                    width,
                )}
            >
                <div className="flex min-w-0 flex-col items-center px-5 sm:px-8">
                    <Link href={route('home')} className="flex flex-col items-center gap-2 font-medium">
                        <div className="border-border mb-5 flex items-center justify-center rounded-2xl border bg-white p-2 shadow-md shadow-slate-950/15">
                            <BrandLoginLogo />
                        </div>
                        <span className="sr-only">{title}</span>
                    </Link>

                    <CardHeader className="w-full space-y-2 px-0 pb-7">
                        <CardTitle className="text-center text-3xl font-black tracking-tight text-balance">{title}</CardTitle>
                        <CardDescription className="mx-auto max-w-sm text-center text-sm leading-6 text-balance">{description}</CardDescription>
                    </CardHeader>
                </div>

                {/* Content */}
                <div className="scrollbar-thin scrollbar-thumb-border relative z-10 min-w-0 overflow-y-auto px-5 sm:px-8">{children}</div>
            </Card>
            <FlashToastMessages />
            <Toaster theme={appearance} />
        </div>
    );
}
