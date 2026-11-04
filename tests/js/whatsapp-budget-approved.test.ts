import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { buildMessage } from '../../resources/js/components/WhatsAppButtonProps.tsx';

const NBSP = /\u00a0/g;
const brl = (message: string) => message.replace(NBSP, ' ');

const approvedTemplate =
    'Bom dia, {{ cliente }}! Agradecemos seu retorno e a aprovação do orçamento.\nOrçamento: {{ descricao_orcamento }}\nPeças:\n{{ peças }}\nTotal das peças: {{ valor_pecas }}';

const budget = {
    description: 'Troca de bomba d’água',
    parts: [
        { quantity: 1, description: 'Bomba d’água', total: 320 },
        { quantity: 2, description: 'Correia', total: 90 },
    ],
    parts_value: 410,
};

const baseProps = {
    customerName: 'João',
    orderNumber: '1024',
    status: 4,
    feedback: false,
    context: 'budget_approved' as const,
    daysPending: 0,
    whats: { budgetapproved: approvedTemplate },
};

describe('mensagem de agradecimento pela aprovação do orçamento', () => {
    test('usa o modelo de orçamento aprovado e substitui cliente, descrição, peças e valor', () => {
        const message = brl(buildMessage({ ...baseProps, budget }));

        assert.equal(message.includes('Bom dia, João! Agradecemos seu retorno e a aprovação do orçamento.'), true);
        assert.equal(message.includes('Orçamento: Troca de bomba d’água'), true);
        assert.equal(message.includes('- 1x Bomba d’água: R$ 320,00\n- 2x Correia: R$ 90,00'), true);
        assert.equal(message.endsWith('Total das peças: R$ 410,00'), true);
    });

    test('sem peças nem descrição não deixa tags literais, undefined ou null', () => {
        const message = brl(
            buildMessage({
                ...baseProps,
                budget: { description: null, parts: [], parts_value: null },
            }),
        );

        assert.equal(message.includes('{{'), false);
        assert.equal(message.includes('undefined'), false);
        assert.equal(message.includes('null'), false);
        assert.equal(message.includes('Orçamento: \n'), true);
        assert.equal(message.endsWith('Total das peças:'), true);
    });

    test('sem dados de orçamento (null) também não gera valores inválidos', () => {
        const message = brl(buildMessage({ ...baseProps, budget: null }));

        assert.equal(message.includes('{{'), false);
        assert.equal(message.includes('undefined'), false);
        assert.equal(message.includes('null'), false);
    });

    test('aceita a tag com acento e sem acento e espaços variados', () => {
        const withAccent = brl(
            buildMessage({ ...baseProps, budget, whats: { budgetapproved: '{{ cliente }}: {{peças}} | {{ pecas }} | {{  valor-pecas  }}' } }),
        );

        assert.equal(
            withAccent,
            'João: - 1x Bomba d’água: R$ 320,00\n- 2x Correia: R$ 90,00 | - 1x Bomba d’água: R$ 320,00\n- 2x Correia: R$ 90,00 | R$ 410,00',
        );
    });

    test('quantidades fracionadas usam vírgula decimal', () => {
        const message = brl(
            buildMessage({
                ...baseProps,
                budget: { description: 'Serviço', parts: [{ quantity: 1.5, description: 'Cabo', total: 30 }], parts_value: 30 },
                whats: { budgetapproved: '{{ cliente }}: {{ peças }}' },
            }),
        );

        assert.equal(message, 'João: - 1,5x Cabo: R$ 30,00');
    });

    test('o modelo só é escolhido quando a OS está com orçamento aprovado', () => {
        const approved = buildMessage({ ...baseProps, budget });
        const generated = buildMessage({
            ...baseProps,
            status: 3,
            context: 'default',
            budget,
            whats: { budgetapproved: approvedTemplate, generatedbudget: 'Orçamento gerado para {{ cliente }}: {{ valor_pecas }}' },
        });

        assert.equal(approved.startsWith('Bom dia, João!'), true);
        assert.equal(brl(generated), 'Orçamento gerado para João: R$ 410,00');
    });

    test('sem modelo próprio para aprovado, usa a mensagem padrão como antes', () => {
        const message = brl(
            buildMessage({ ...baseProps, budget, whats: { budgetapproved: '', defaultmessage: '{{ cliente }}: {{ valor_pecas }}' } }),
        );

        assert.equal(message, 'João: R$ 410,00');
    });

    test('sem modelo configurado para aprovado, não há mensagem', () => {
        const message = buildMessage({ ...baseProps, budget, whats: { budgetapproved: '' } });

        assert.equal(message, '');
    });
});
