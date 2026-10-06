import { BrandHorizontalLogo } from '@/components/brand-logo';
import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { whatsappLink } from './site-contact';

export function Footer() {
    return (
        <footer id="contato" className="border-t border-white/10 bg-[#0B1220] py-16 text-white">
            <div className="mx-auto max-w-7xl px-4">
                <div className="grid gap-10 sm:grid-cols-2 lg:grid-cols-4">
                    {/* Logo + descrição */}
                    <div className="space-y-4">
                        <div className="flex items-center gap-2">
                            <BrandHorizontalLogo inverse />
                        </div>

                        <p className="text-sm leading-relaxed text-white/58">
                            Sistema de gestão para assistências técnicas de celulares, informática e eletrônicos com mais controle sobre ordens,
                            clientes, vendas e financeiro.
                        </p>
                        <a
                            href="https://abrasilsistemas.com.br"
                            target="_blank"
                            rel="noreferrer"
                            title="Desenvolvido por ABrasil Sistemas"
                            className="group inline-flex items-center gap-3 rounded-xl border border-white/10 bg-white/5 p-2.5 pr-4 transition hover:border-white/20 hover:bg-white/10"
                        >
                            <span className="grid size-10 place-items-center overflow-hidden rounded-lg bg-white p-1">
                                <img
                                    src="/images/logo_ab.png"
                                    alt="Desenvolvido por ABrasil Sistemas"
                                    title="Desenvolvido por ABrasil Sistemas"
                                    className="size-full object-contain"
                                />
                            </span>
                            <span className="text-xs font-semibold tracking-wide text-white/58 transition group-hover:text-white">
                                Desenvolvido por
                                <strong className="mt-0.5 block text-white">ABrasil Sistemas</strong>
                            </span>
                        </a>
                    </div>

                    {/* Produto */}
                    <div>
                        <h3 className="mb-4 font-semibold text-[#7ee7ff]">Produto</h3>

                        <ul className="space-y-3 text-sm">
                            <li>
                                <a href="/#recursos" className="text-white/58 transition-colors hover:text-white">
                                    Recursos
                                </a>
                            </li>

                            <li>
                                <Link href={route('plans.index')} className="text-white/58 transition-colors hover:text-white">
                                    Planos
                                </Link>
                            </li>

                            <li>
                                <Link href={route('login')} className="text-white/58 transition-colors hover:text-white">
                                    Entrar
                                </Link>
                            </li>
                        </ul>
                    </div>

                    {/* Empresa */}
                    <div>
                        <h3 className="mb-4 font-semibold text-[#7ee7ff]">Empresa</h3>

                        <ul className="space-y-3 text-sm">
                            <li>
                                <a href="/#contato" className="text-white/58 transition-colors hover:text-white">
                                    Contato
                                </a>
                            </li>

                            <li>
                                <a href="/termos" className="text-white/58 transition-colors hover:text-white">
                                    Termos de uso
                                </a>
                            </li>

                            <li>
                                <a href="/privacidade" className="text-white/58 transition-colors hover:text-white">
                                    Política de privacidade
                                </a>
                            </li>
                        </ul>
                    </div>

                    {/* Suporte */}
                    <div>
                        <h3 className="mb-4 font-semibold text-[#7ee7ff]">Contato</h3>

                        <p className="mb-4 text-sm leading-relaxed text-white/58">
                            Fale com nossa equipe comercial ou tire dúvidas sobre o teste grátis.
                        </p>

                        <ul className="space-y-3 text-sm">
                            <li>
                                <a
                                    href={whatsappLink()}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="flex items-center gap-2 text-white/58 transition-colors hover:text-white"
                                >
                                    <MessageCircle className="h-4 w-4" />
                                    WhatsApp
                                </a>
                            </li>

                            <li>
                                <a href="mailto:contato@vetoros.com.br" className="text-white/58 transition-colors hover:text-white">
                                    contato@vetoros.com.br
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                {/* Bottom */}
                <div className="mt-12 flex flex-col items-center justify-between gap-4 border-t border-white/10 pt-6 sm:flex-row">
                    <p className="text-sm text-white/42">© {new Date().getFullYear()} VetorOS. Todos os direitos reservados.</p>

                    <div className="flex gap-6 text-sm">
                        <a href="/privacidade" className="text-white/42 transition-colors hover:text-white">
                            Privacidade
                        </a>

                        <a href="/termos" className="text-white/42 transition-colors hover:text-white">
                            Termos
                        </a>
                    </div>
                </div>
            </div>
        </footer>
    );
}
