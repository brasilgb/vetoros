import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import {
    BarChart3,
    CalendarCheck,
    Camera,
    ChartLine,
    CheckCircle,
    ClipboardList,
    Eye,
    HandCoins,
    MessageSquare,
    Package,
    Printer,
    ReceiptText,
    Settings,
    ShoppingCart,
    Signature,
    Smartphone,
    Users,
    Wrench,
} from 'lucide-react';

const appFeatures = [
    {
        icon: Smartphone,
        title: 'Vetor Atendimento',
        description: 'Cadastre clientes, consulte orçamentos e colete pelo celular a assinatura do cliente em uma ordem de serviço já aberta.',
    },
    {
        icon: Wrench,
        title: 'App Técnico',
        description: 'Agenda em campo com check-in, check-out, checklist, relatório técnico, imagens e pagamento local.',
    },
    {
        icon: Camera,
        title: 'App Imagens',
        description: 'Upload de fotos da ordem direto pelo celular para documentar entrada, defeito, reparo e entrega.',
    },
];

const features = [
    {
        icon: ClipboardList,
        title: 'Ordens de Serviço',
        description: 'Acompanhe cada etapa do diagnóstico à entrega, com responsáveis, fotos e histórico para manter o atendimento previsível.',
    },
    {
        icon: Users,
        title: 'Clientes e Equipamentos',
        description: 'Consulte contatos, equipamentos e histórico em um só cadastro e retome cada relacionamento com contexto.',
    },
    {
        icon: Eye,
        title: 'Acompanhamento da Ordem',
        description: 'Mantenha o cliente informado sobre status, comprovantes e próximos passos em uma página própria da ordem.',
    },
    {
        icon: CheckCircle,
        title: 'Orçamentos e Aprovação',
        description: 'Envie propostas para aprovação e mantenha a decisão registrada junto ao atendimento.',
    },
    {
        icon: Package,
        title: 'Estoque e Peças',
        description: 'Acompanhe disponibilidade e movimentações para planejar reposições e reduzir surpresas na execução.',
    },
    {
        icon: ShoppingCart,
        title: 'Vendas e Recibos',
        description: 'Registre vendas e gere recibos no mesmo fluxo usado para serviços e peças.',
    },
    {
        icon: ReceiptText,
        title: 'Notas Fiscais',
        description: 'Emita NF-e, NFC-e e NFS-e a partir das vendas e ordens, com os documentos ligados à operação.',
    },
    {
        icon: HandCoins,
        title: 'Financeiro e Caixa',
        description: 'Confira entradas, despesas e movimentação do caixa para decidir com base na rotina financeira.',
    },
    {
        icon: MessageSquare,
        title: 'Comunicação Automática',
        description: 'Comunique status e cobranças em cada etapa, com modelos e canais integrados à operação.',
    },
    {
        icon: Signature,
        title: 'Assinatura Digital no App',
        description: 'Colete a assinatura do cliente no Vetor Atendimento, vincule-a à OS existente e exiba o registro no recibo de entrada.',
    },
    {
        icon: CalendarCheck,
        title: 'Agenda Técnica em Campo',
        description: 'Envie atendimentos ao técnico, acompanhe status da visita e mantenha o histórico conectado à ordem de serviço.',
    },
    {
        icon: ChartLine,
        title: 'Indicadores Comerciais',
        description: 'Acompanhe conversão de orçamentos, recuperação de cobrança e desempenho da operação.',
    },
    {
        icon: Printer,
        title: 'Impressões Operacionais',
        description: 'Imprima recibos, etiquetas e documentos da operação sem depender de processos externos.',
    },
    {
        icon: BarChart3,
        title: 'Relatórios e Dashboard',
        description: 'Leia faturamento, ordens e vendas em indicadores que ajudam a priorizar a próxima ação.',
    },
    {
        icon: Settings,
        title: 'Gestão de Usuários',
        description: 'Defina papéis, permissões e liberação de técnico master para visualizar todas as ordens quando necessário.',
    },
];

export function Features() {
    return (
        <section id="recursos" className="relative overflow-hidden bg-white py-24 text-slate-900 sm:py-32">
            <div className="mx-auto max-w-7xl px-5 sm:px-8 lg:px-12">
                <div className="mx-auto mb-16 max-w-3xl text-center">
                    <p className="text-sm font-bold text-blue-700">Uma operação conectada</p>

                    <h2 className="mt-3 text-4xl font-bold tracking-[-0.04em] text-balance text-slate-950 sm:text-5xl">
                        Da entrada do equipamento ao resultado financeiro, cada etapa com contexto e acompanhamento
                    </h2>

                    <p className="mt-5 text-lg leading-8 text-slate-600">
                        O VetorOS conecta serviços, clientes, estoque, financeiro e equipe em campo. Os apps complementam a operação com assinatura,
                        registro de imagens e acompanhamento técnico.
                    </p>
                    <p className="mt-3 text-xs text-slate-400">
                        Emissão de NF-e, NFC-e e NFS-e pelo próprio sistema, mediante liberação e certificado digital A1 da empresa.
                    </p>
                </div>

                <div className="mb-12 grid gap-6 md:grid-cols-3">
                    {appFeatures.map((app) => (
                        <Card key={app.title} className="border-slate-800 bg-slate-950 text-white shadow-xl shadow-slate-900/10">
                            <CardHeader>
                                <div className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-blue-500/15">
                                    <app.icon className="h-6 w-6 text-blue-300" />
                                </div>
                                <CardTitle className="text-lg text-white">{app.title}</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <CardDescription className="text-sm leading-6 text-slate-400">{app.description}</CardDescription>
                            </CardContent>
                        </Card>
                    ))}
                </div>

                <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
                    {features.map((feature, index) => (
                        <Card
                            key={index}
                            className="flex h-full flex-col border-slate-200 bg-white shadow-sm transition duration-300 hover:-translate-y-1 hover:border-blue-200 hover:shadow-xl hover:shadow-slate-900/5"
                        >
                            <CardHeader>
                                <div className="mb-3 inline-flex h-12 w-12 items-center justify-center rounded-xl bg-blue-50">
                                    <feature.icon className="h-6 w-6 text-blue-700" />
                                </div>

                                <CardTitle className="flex min-h-12 items-start text-lg leading-6 text-slate-950">{feature.title}</CardTitle>
                            </CardHeader>

                            <CardContent className="flex-1">
                                <CardDescription className="text-sm leading-6 text-slate-600">{feature.description}</CardDescription>
                            </CardContent>
                        </Card>
                    ))}
                </div>
            </div>
        </section>
    );
}
