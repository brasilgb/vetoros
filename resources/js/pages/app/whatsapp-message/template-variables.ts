// Catálogo das variáveis dos templates de WhatsApp. Fonte única para a lista da tela,
// a ajuda (gaveta/modal) e o teste de cobertura. As grafias são as que o sistema aceita:
// a comparação ignora acentos e maiúsculas (ver normalizePlaceholderKey em
// resources/js/components/WhatsAppButtonProps.tsx e normalizeKey em
// app/Services/WhatsappMessageTemplateService.php), então `{{ peças }}` e `{{ pecas }}` funcionam igual.

export type TemplateVariable = {
    tag: string;
    description: string;
};

export type TemplateVariableGroup = {
    title: string;
    variables: TemplateVariable[];
    // Se true, a variável só é preenchida nos envios da O.S. (não no aviso ao técnico).
    orderOnly?: boolean;
};

export const TEMPLATE_VARIABLE_GROUPS: TemplateVariableGroup[] = [
    {
        title: 'Cliente e ordem de serviço',
        variables: [
            { tag: 'cliente', description: 'Nome do cliente.' },
            { tag: 'ordem', description: 'Número da ordem de serviço.' },
            { tag: 'link_os', description: 'Link para o cliente acompanhar a O.S.' },
            { tag: 'saudacao', description: 'Saudação conforme o horário, como “Bom dia”, “Boa tarde” ou “Boa noite”.' },
        ],
    },
    {
        title: 'Pendências',
        orderOnly: true,
        variables: [
            { tag: 'saldo', description: 'Valor que ainda está pendente de pagamento.' },
            { tag: 'dias_pendentes', description: 'Quantidade de dias de pendência.' },
        ],
    },
    {
        title: 'Orçamento',
        orderOnly: true,
        variables: [
            { tag: 'valor_orçamento', description: 'Total do orçamento, com serviços e peças.' },
            { tag: 'valor_serviço', description: 'Valor somente dos serviços.' },
            { tag: 'descricao_orcamento', description: 'Descrição registrada no orçamento. Sem descrição, fica em branco.' },
            { tag: 'peças', description: 'Lista das peças do orçamento. Sem peças, fica em branco.' },
            { tag: 'valor_pecas', description: 'Total das peças. Sem peças, fica em branco.' },
        ],
    },
    {
        title: 'Agendamento ao técnico',
        variables: [
            { tag: 'tecnico', description: 'Nome do técnico responsável.' },
            { tag: 'data_visita', description: 'Data agendada para a visita.' },
            { tag: 'servico', description: 'Serviço previsto para a visita.' },
            { tag: 'materiais', description: 'Materiais informados no agendamento.' },
            { tag: 'endereco', description: 'Endereço da visita.' },
        ],
    },
];

export const toTemplateTag = (variable: string) => `{{ ${variable} }}`;
