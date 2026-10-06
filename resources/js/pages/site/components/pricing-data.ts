// Os planos públicos (nomes, periodicidades e, quando habilitado, o preço) vêm
// do backend (PublicPlanCatalog), que lê a tabela `plans` administrada pelo
// RootAdmin. Aqui ficam só os textos comuns e a mensagem do orçamento.
export type PublicPlan = {
    name: string;
    periodicity: string;
    description: string;
    popular: boolean;
    price_label?: string;
};

export const commonFeatures = [
    'Todos os recursos incluídos',
    'Usuários ilimitados',
    'Aplicativo Android',
    'Suporte prioritário',
    'Atualizações automáticas',
    'Backup diário',
];

export const quoteMessage = (planName: string, periodicity: string) =>
    `Olá! Gostaria de solicitar um orçamento do plano ${planName} (periodicidade ${periodicity}) do VetorOS.`;
