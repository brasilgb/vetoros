import { usePage } from '@inertiajs/react';

const DEFAULT_LOGO = '/images/default.png';

type AppLogoPageProps = {
    company?: {
        logo?: string | null;
        shortname?: string | null;
    };
};

export default function AppLogo() {
    const { company } = usePage<AppLogoPageProps>().props;
    const logoSrc = company?.logo ? `/storage/logos/${company.logo}` : DEFAULT_LOGO;

    return (
        <>
            <div className="flex aspect-square size-8 items-center justify-center overflow-hidden rounded-md">
                <img
                    src={logoSrc}
                    alt="Imagem de logo"
                    className="size-full object-contain"
                    onError={(event) => {
                        if (event.currentTarget.src.endsWith(DEFAULT_LOGO)) {
                            return;
                        }

                        event.currentTarget.src = DEFAULT_LOGO;
                    }}
                />
            </div>
            <div className="ml-1 grid flex-1 text-left text-sm">
                <span className="mb-0.5 truncate leading-none font-semibold">{company?.shortname && company?.shortname}</span>
            </div>
        </>
    );
}
