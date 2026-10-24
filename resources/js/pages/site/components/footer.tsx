import { BrandHorizontalLogo } from '@/components/brand-logo';
import { Link } from '@inertiajs/react';
import { MessageCircle } from 'lucide-react';
import { CookiePreferencesButton } from './cookie-consent';
import { contactLink, useContactChannel } from './site-contact';

export function Footer() {
    const productName = import.meta.env.VITE_PUBLIC_PRODUCT_NAME || 'VetorOS';
    const contactChannel = useContactChannel();
    const contactUrl = contactLink(contactChannel);
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
                            {contactUrl && (
                                <li>
                                    <a
                                        href={contactUrl}
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        className="flex items-center gap-2 text-white/58 transition-colors hover:text-white"
                                    >
                                        <MessageCircle className="h-4 w-4" />
                                        WhatsApp
                                    </a>
                                </li>
                            )}

                            <li>
                                <a href="mailto:contato@vetoros.com.br" className="text-white/58 transition-colors hover:text-white">
                                    contato@vetoros.com.br
                                </a>
                            </li>
                        </ul>
                    </div>
                </div>

                {/* Bottom */}
                <nav aria-label="Informações legais" className="mt-12 flex justify-center gap-6 border-t border-white/10 pt-6 text-sm sm:justify-end">
                    <a href="/privacidade" className="text-white/60 transition-colors hover:text-white">Privacidade</a>
                    <a href="/termos" className="text-white/60 transition-colors hover:text-white">Termos</a>
                    <CookiePreferencesButton className="text-white/60 transition-colors hover:text-white" />
                </nav>
                <div className="mt-6 flex flex-col items-center gap-3 border-t border-white/10 pt-6 text-center text-xs text-white/70 sm:flex-row sm:justify-between sm:text-left">
                    <p>© {new Date().getFullYear()} {productName}. Todos os direitos reservados.</p>
                    <p>Desenvolvido por <a href="https://abrasilsistemas.com.br" target="_blank" rel="noopener noreferrer" className="font-semibold text-white underline-offset-4 hover:underline focus-visible:underline">ABrasil Sistemas</a></p>
                </div>
            </div>
        </footer>
    );
}
