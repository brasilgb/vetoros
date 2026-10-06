import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardFooter, CardHeader, CardTitle } from '@/components/ui/card';
import { usePage } from '@inertiajs/react';
import { Check, MessageCircle } from 'lucide-react';
import { commonFeatures, PublicPlan, quoteMessage } from './pricing-data';
import { whatsappLink } from './site-contact';

export function Pricing() {
    const { publicPlans = [] } = usePage<{ publicPlans?: PublicPlan[] }>().props;

    return (
        <section id="precos" className="border-y border-slate-200 bg-slate-50 py-24 text-slate-900 sm:py-32">
            <div className="mx-auto max-w-7xl px-5 sm:px-8 lg:px-12">
                <div className="mx-auto mb-16 max-w-2xl text-center">
                    <p className="text-sm font-bold text-blue-700">Planos VetorOS</p>

                    <h2 className="mt-3 text-4xl font-bold tracking-[-0.04em] text-slate-950 sm:text-5xl">Escolha seu plano</h2>

                    <p className="mt-5 text-lg leading-8 text-slate-600">
                        Todos os planos têm acesso completo aos recursos. Escolha a periodicidade e solicite um orçamento pelo WhatsApp.
                    </p>
                </div>

                <div className="mx-auto grid max-w-5xl gap-8 md:grid-cols-3">
                    {publicPlans.map((plan) => (
                        <Card
                            key={plan.periodicity}
                            className={`relative border-slate-200 bg-white text-slate-950 shadow-sm transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-slate-900/5 ${
                                plan.popular ? 'border-2 border-blue-700 shadow-xl shadow-blue-700/10 md:scale-105' : ''
                            }`}
                        >
                            {plan.popular && (
                                <div className="absolute -top-4 left-1/2 -translate-x-1/2">
                                    <span className="rounded-full bg-blue-700 px-4 py-1 text-sm font-bold text-white shadow">Mais popular</span>
                                </div>
                            )}

                            <CardHeader className="pt-8 pb-6 text-center">
                                <CardTitle className="text-2xl text-slate-950">{plan.name}</CardTitle>

                                <CardDescription className="mt-2 text-slate-500">{plan.description}</CardDescription>

                                {/* price_label só existe com commercial.public_prices_enabled (valor da tabela plans). */}
                                <div className="mt-6 text-lg font-semibold text-blue-700">{plan.price_label ?? 'Solicite um orçamento'}</div>
                            </CardHeader>

                            <CardContent className="space-y-4">
                                <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 p-2 text-center text-xs text-slate-500">
                                    Todos os módulos liberados desde o primeiro dia.
                                </div>
                                {commonFeatures.map((feature, featureIndex) => (
                                    <div key={featureIndex} className="flex items-start gap-3">
                                        <Check className="mt-0.5 h-5 w-5 shrink-0 text-blue-700" />

                                        <span className="text-sm leading-relaxed text-slate-700">{feature}</span>
                                    </div>
                                ))}
                            </CardContent>

                            <CardFooter className="flex flex-col gap-3 pt-6">
                                <a
                                    href={whatsappLink(quoteMessage(plan.name, plan.periodicity))}
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    className="w-full"
                                >
                                    <Button
                                        size="lg"
                                        className="w-full rounded-lg bg-blue-700 font-bold text-white hover:bg-blue-800"
                                        aria-label={`Consultar o plano ${plan.name} pelo WhatsApp`}
                                    >
                                        <MessageCircle className="mr-2 h-5 w-5" />
                                        Consultar pelo WhatsApp
                                    </Button>
                                </a>

                                <span className="text-xs text-slate-400">14 dias grátis para testar tudo</span>
                            </CardFooter>
                        </Card>
                    ))}
                </div>

                <p className="mt-12 text-center text-sm text-slate-500">
                    Todos os planos incluem os mesmos recursos, suporte em português e acesso completo desde o primeiro dia de teste.
                </p>
            </div>
        </section>
    );
}
