import { Accordion, AccordionContent, AccordionItem, AccordionTrigger } from '@/components/ui/accordion';

export const faqItems = [
    {
        question: 'Preciso de cartão de crédito para testar?',
        answer: 'Não. O cadastro libera 14 dias de acesso completo sem pedir cartão. Depois do teste, consulte as formas de pagamento e as condições vigentes antes de decidir continuar.',
    },
    {
        question: 'Posso cancelar quando quiser?',
        answer: 'Sim. Não há fidelidade nem multa de cancelamento. Consulte as condições vigentes do plano antes de renovar.',
    },
    {
        question: 'O VetorOS emite nota fiscal?',
        answer: 'Sim. O VetorOS emite NF-e nas vendas com cliente identificado, NFC-e no PDV e NFS-e nos serviços das ordens, direto do sistema e com o documento vinculado ao atendimento. Para ativar, solicite a liberação ao suporte, envie o certificado digital A1 da empresa (.pfx ou .p12) e revise as configurações tributárias com seu contador. Se preferir emitir em outro sistema, você continua podendo registrar o número e o link da nota manualmente.',
    },
    {
        question: 'Existe limite de usuários?',
        answer: 'Não. Todos os planos incluem usuários ilimitados, com controle de papéis e permissões por colaborador, sem cobrança extra por pessoa.',
    },
    {
        question: 'Já uso outro sistema. Dá para importar meus clientes?',
        answer: 'Sim. O VetorOS importa clientes por CSV, com tratamento tolerante a e-mails repetidos e CPF/CNPJ inconsistente, para você migrar sem travar na base atual.',
    },
    {
        question: 'O sistema funciona pelo celular?',
        answer: 'Sim. O painel web é responsivo e a operação conta com apps auxiliares: autoatendimento para recepção, técnico para atendimento em campo e um app dedicado ao registro de fotos da ordem.',
    },
    {
        question: 'Meus dados ficam separados de outras empresas que usam o VetorOS?',
        answer: 'Sim. A plataforma é multi-tenant com isolamento por empresa: cada conta só acessa seus próprios clientes, ordens, financeiro e configurações.',
    },
    {
        question: 'Meu cliente consegue acompanhar a ordem de serviço sem criar login?',
        answer: 'Sim. Cada ordem tem um link público único para o cliente acompanhar status, aprovar orçamento, ver comprovantes e avaliar o atendimento, sem precisar de senha.',
    },
];

export const faqSchema = {
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

export function FAQ() {
    return (
        <section id="faq" className="bg-white py-24 text-slate-900 sm:py-32">
            <div className="mx-auto max-w-3xl px-5 sm:px-8 lg:px-12">
                <div className="text-center">
                    <p className="text-sm font-bold text-blue-700">Perguntas frequentes</p>
                    <h2 className="mt-3 text-4xl font-bold tracking-[-0.04em] text-balance text-slate-950 sm:text-5xl">
                        Ainda com dúvidas antes de testar?
                    </h2>
                </div>

                <Accordion type="single" collapsible className="mt-12">
                    {faqItems.map((item) => (
                        <AccordionItem key={item.question} value={item.question}>
                            <AccordionTrigger className="text-base font-semibold text-slate-950">{item.question}</AccordionTrigger>
                            <AccordionContent className="text-sm leading-6 text-slate-600">{item.answer}</AccordionContent>
                        </AccordionItem>
                    ))}
                </Accordion>
            </div>
        </section>
    );
}
