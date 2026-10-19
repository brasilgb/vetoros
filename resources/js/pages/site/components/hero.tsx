import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, ClipboardList } from 'lucide-react';
import { contactLink, useContactChannel } from './site-contact';

export function Hero() {
    const contactChannel = useContactChannel();
    const contactUrl = contactLink(contactChannel, 'Olá! Gostaria de falar com um especialista sobre o VetorOS.');
    return (
        <section className="relative isolate overflow-hidden bg-[#08111f] pt-20 text-white">
            <img
                src="/images/vetoros-hero-maintenance.png"
                alt=""
                aria-hidden="true"
                width="1983"
                height="793"
                fetchPriority="high"
                className="absolute inset-0 -z-20 size-full object-cover object-[62%_center]"
            />
            <div className="absolute inset-0 -z-10 bg-gradient-to-r from-[#08111f]/95 via-[#08111f]/82 to-[#08111f]/35" />
            <div className="absolute inset-0 -z-10 bg-gradient-to-t from-[#08111f]/75 via-transparent to-[#08111f]/10" />

            <div className="relative mx-auto flex min-h-[620px] max-w-[86rem] items-center px-5 py-20 sm:min-h-[680px] sm:px-8 sm:py-28 lg:min-h-[740px] lg:px-12">
                <div className="max-w-3xl">
                    <h1 className="max-w-3xl text-[clamp(3.5rem,7vw,6.6rem)] leading-[0.88] font-black tracking-[-0.075em] text-balance">
                        <span className="inline-flex items-center gap-2 rounded-full border border-sky-300/25 bg-sky-300/10 px-4 py-2 text-xs leading-normal font-extrabold tracking-[0.12em] text-sky-200 uppercase">
                            <ClipboardList className="size-3.5 shrink-0" aria-hidden="true" />
                            Sistema de gestão para assistências técnicas
                        </span>
                        <span className="mt-7 block">Sua operação</span>
                        <span className="mt-2 block text-sky-300">sob controle.</span>
                    </h1>

                    <p className="mt-8 max-w-xl text-lg leading-8 text-slate-300 sm:text-xl">
                        Da bancada ao caixa: organize reparos de celulares, PCs, notebooks e eletrônicos em uma plataforma feita para assistências
                        técnicas.
                    </p>

                    <div className="mt-9 flex flex-col gap-3 sm:flex-row">
                        <Button
                            size="lg"
                            className="h-14 w-full rounded-full bg-sky-400 px-7 text-sm font-extrabold text-slate-950 shadow-[0_18px_60px_rgba(56,189,248,.18)] transition hover:-translate-y-1 hover:bg-sky-300 sm:w-auto"
                            asChild
                        >
                            <Link href={route('plans.index')}>
                                Quero testar o VetorOS
                                <ArrowRight className="h-5 w-5" />
                            </Link>
                        </Button>

                        <Button
                            size="lg"
                            variant="outline"
                            className="h-14 w-full rounded-full border-white/15 bg-transparent px-7 text-sm font-bold text-white hover:bg-white/5 hover:text-white sm:w-auto"
                            asChild
                        >
                            <a href="#recursos">Ver recursos</a>
                        </Button>
                    </div>

                    <p className="mt-5 flex items-center gap-2 text-xs text-slate-500">
                        <CheckCircle2 className="size-4 text-cyan-300" />
                        Cadastro online e 14 dias para testar gratuitamente.
                    </p>

                    {contactUrl && (
                        <a
                            href={contactUrl}
                            target="_blank"
                            rel="noopener noreferrer"
                            className="mt-3 inline-flex items-center gap-2 text-xs font-bold text-sky-300 underline-offset-4 hover:underline"
                        >
                            Solicite um orçamento pelo WhatsApp
                            <ArrowRight className="size-3.5" />
                        </a>
                    )}
                </div>
            </div>
            <div className="relative border-t border-white/15 bg-[#08111f]/35 backdrop-blur-sm">
                <div className="mx-auto grid max-w-[86rem] divide-y divide-white/15 px-5 sm:grid-cols-3 sm:divide-x sm:divide-y-0 sm:px-8 lg:px-12">
                    {['Emite NF-e, NFC-e e NFS-e', 'Suporte próximo e humano', 'Acesso seguro de qualquer lugar'].map((item) => (
                        <p
                            key={item}
                            className="flex items-center justify-center gap-2 py-5 text-center text-xs font-bold tracking-[0.08em] text-slate-400 uppercase sm:py-6"
                        >
                            <CheckCircle2 className="size-4 shrink-0 text-cyan-300" aria-hidden="true" />
                            {item}
                        </p>
                    ))}
                </div>
            </div>
        </section>
    );
}
