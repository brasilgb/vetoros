import { maskCpfCnpj } from '@/Utils/mask';
import { usePage } from '@inertiajs/react';
import moment from 'moment';

type AppFooterPageProps = {
    company?: {
        companyname?: string | null;
        cnpj?: string | null;
    };
};

export default function AppFooter() {
    const { company } = usePage<AppFooterPageProps>().props;
    return (
        <footer className="border-sidebar-border/80 flex w-full min-w-0 items-center justify-between border-t px-2 shadow-md sm:px-3">
            <div className="mx-auto flex w-full min-w-0 flex-col items-center justify-between gap-1 p-2 sm:flex-row sm:gap-3 sm:px-4">
                <span className="min-w-0 text-center text-xs font-medium break-words text-gray-600 sm:text-left">
                    &copy;{moment().format('YYYY')} - {company?.companyname} - CNPJ: {maskCpfCnpj(company?.cnpj ?? '')}
                </span>
                <div className="flex shrink-0 items-center gap-3 text-xs font-semibold text-gray-600">
                    <span>
                        <a href="https://abrasilsistemas.com.br" target="_blank" rel="noreferrer">
                            ABrasil Sistemas
                        </a>
                        {' — VetorOS | '}
                        <span className="text-gray-500">{__APP_VERSION__}</span>
                    </span>
                </div>
            </div>
        </footer>
    );
}
