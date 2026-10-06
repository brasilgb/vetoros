import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/react';
import { ArrowRight, CheckCircle2, MessageCircle } from 'lucide-react';
import { CookieConsent } from '../components/cookie-consent';
import { FAQ, faqItems } from '../components/faq';
import { Footer } from '../components/footer';
import { Header } from '../components/header';
import { Pricing } from '../components/pricing';
import { whatsappLink } from '../components/site-contact';
import { WhatsAppFloat } from '../components/whatsapp-float';

const faqSchema = {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqItems.map((item) => ({
        '@type': 'Question',
        name: item.question,
        acceptedAnswer: {
            '@type': 'Answer',
            text: item.answer,
        },
    })),
};

const benefits = [
    'Controle financeiro, vendas e prestação de serviços',
    'Ordens de serviço e relacionamento com clientes',
    'Acesso completo durante 14 dias',
    'Sem cartão de crédito e sem API fiscal obrigatória',
];

export default function Plans() {
    return (
        <main className="min-h-screen bg-white text-slate-900">
            <Head title="Planos do VetorOS">
                <meta name="description" content="Conheça os planos do VetorOS e teste a plataforma gratuitamente por 14 dias." />
                <meta name="theme-color" content="#ffffff" />
                <script type="application/ld+json">{JSON.stringify(faqSchema)}</script>
            </Head>
            <Header />

            <section className="relative overflow-hidden px-5 pt-36 pb-20 text-center sm:px-8 sm:pt-44 sm:pb-24">
                <div className="absolute inset-x-0 top-0 -z-0 h-[36rem] bg-linear-to-b from-blue-50 via-white to-white" />
                <div className="relative mx-auto max-w-4xl">
                    <p className="text-sm font-bold text-blue-700">Planos simples e transparentes</p>
                    <h1 className="mt-3 text-5xl font-bold tracking-[-0.05em] text-balance text-slate-950 sm:text-6xl">
                        Escolha a melhor forma de usar o VetorOS
                    </h1>
                    <p className="mx-auto mt-6 max-w-2xl text-lg leading-8 text-slate-600">
                        Simplifique as operações diárias da sua empresa de manutenção e mantenha atendimento, serviços e financeiro organizados em um
                        só lugar.
                    </p>

                    <div className="mx-auto mt-8 grid max-w-3xl gap-3 text-left sm:grid-cols-2">
                        {benefits.map((benefit) => (
                            <div
                                key={benefit}
                                className="flex items-start gap-3 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700 shadow-sm"
                            >
                                <CheckCircle2 className="mt-0.5 h-5 w-5 shrink-0 text-emerald-600" />
                                <span>{benefit}</span>
                            </div>
                        ))}
                    </div>

                    <Button asChild size="lg" className="mt-9 rounded-lg bg-blue-700 font-bold text-white hover:bg-blue-800">
                        <Link href={route('register')}>
                            Testar grátis por 14 dias
                            <ArrowRight className="ml-2 h-4 w-4" />
                        </Link>
                    </Button>
                </div>
            </section>

            <Pricing />

            <FAQ />

            <section className="px-5 py-24 text-center sm:px-8">
                <div className="mx-auto max-w-5xl rounded-3xl bg-blue-700 px-6 py-14 text-white shadow-2xl shadow-blue-700/15 sm:px-12">
                    <h2 className="text-3xl font-bold">Ainda tem dúvidas?</h2>
                    <p className="mt-3 text-blue-100">Converse diretamente com nossa equipe antes de começar.</p>
                    <Button
                        asChild
                        variant="outline"
                        size="lg"
                        className="mt-6 border-white bg-white font-bold text-blue-800 hover:bg-blue-50 hover:text-blue-900"
                    >
                        <a href={whatsappLink()} target="_blank" rel="noopener noreferrer">
                            <MessageCircle className="mr-2 h-5 w-5" />
                            Falar no WhatsApp
                        </a>
                    </Button>
                </div>
            </section>

            <Footer />
            <WhatsAppFloat />
            <CookieConsent />
        </main>
    );
}
