import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { ArrowRight, MessageCircle } from 'lucide-react';
import { whatsappLink } from './site-contact';

export function CTA() {
    return (
        <section className="relative overflow-hidden bg-[#08111f] py-20 text-white sm:py-28">
            <div className="absolute inset-0">
                <div className="absolute inset-0 bg-[linear-gradient(rgba(255,255,255,.035)_1px,transparent_1px),linear-gradient(90deg,rgba(255,255,255,.035)_1px,transparent_1px)] bg-[size:44px_44px]" />
                <div className="absolute top-1/2 left-1/2 h-72 w-72 -translate-x-1/2 -translate-y-1/2 rounded-full bg-[#00E59B]/14 blur-3xl" />
            </div>

            <div className="relative mx-auto max-w-6xl px-4">
                <div className="mx-auto max-w-3xl rounded-[2rem] border border-white/10 bg-white/[0.045] px-6 py-12 text-center shadow-[0_24px_80px_rgba(0,0,0,0.28)] backdrop-blur-sm sm:px-10">
                    <span className="inline-flex rounded-full border border-white/12 bg-white/6 px-4 py-1 text-[0.7rem] font-semibold tracking-[0.26em] text-[#7ee7ff] uppercase">
                        Teste completo
                    </span>

                    <h2 className="mt-5 text-3xl font-bold tracking-tight sm:text-4xl">Pronto para transformar sua gestão?</h2>

                    <p className="mt-6 text-lg leading-relaxed text-white/76">
                        Centralize atendimento, ordens, estoque, financeiro, vendas, campo técnico e relacionamento com clientes em uma plataforma
                        conectada aos apps da operação. Teste o VetorOS gratuitamente por 14 dias.
                    </p>

                    <div className="mt-10 flex flex-col items-center justify-center gap-4 sm:flex-row">
                        <Button
                            size="lg"
                            className="gap-2 rounded-full bg-cyan-300 px-8 text-base font-extrabold text-slate-950 hover:bg-cyan-200"
                            asChild
                        >
                            <Link href={route('plans.index')}>
                                Conhecer planos e testar grátis
                                <ArrowRight className="h-5 w-5" />
                            </Link>
                        </Button>

                        <Button
                            size="lg"
                            variant="outline"
                            className="gap-2 rounded-full border-white/18 bg-white/8 px-8 text-base font-semibold text-white hover:bg-white/14 hover:text-white"
                            asChild
                        >
                            <a href={whatsappLink()} target="_blank" rel="noopener noreferrer">
                                <MessageCircle className="h-5 w-5" />
                                Falar no WhatsApp
                            </a>
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    );
}
