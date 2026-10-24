import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { buildMessage } from '../../resources/js/components/WhatsAppButtonProps.tsx';

const NBSP = / /g;
const brl = (message: string) => message.replace(NBSP, ' ');

const budgetTemplate = {
    generatedbudget: '{{ cliente }}!\nServiços: {{ valor_serviço }}.\nTotal do orçamento: {{ valor_orçamento }}.',
};

const baseProps = {
    customerName: 'João',
    orderNumber: '1024',
    status: 3,
    feedback: false,
    context: 'default' as const,
    daysPending: 0,
    whats: budgetTemplate,
};

describe('mensagens WhatsApp com valores financeiros da OS', () => {
    test('substitui valor_orçamento e valor_serviço pelos valores formatados em BRL', () => {
        const message = buildMessage({
            ...baseProps,
            financialValues: { services: 950, total: 1250 },
        });

        assert.equal(brl(message).includes('Serviços: R$ 950,00.'), true);
        assert.equal(brl(message).includes('Total do orçamento: R$ 1.250,00.'), true);
    });

    test('aceita as grafias sem acento das variáveis', () => {
        const message = buildMessage({
            ...baseProps,
            whats: { generatedbudget: 'Total {{ valor_orcamento }} / Serviços {{ valor_servico }}' },
            financialValues: { services: 0.5, total: 1250.4 },
        });

        assert.equal(brl(message).includes('Total R$ 1.250,40 / Serviços R$ 0,50'), true);
    });

    test('não envia nada quando o valor da OS não está definido', () => {
        const message = buildMessage({
            ...baseProps,
            financialValues: { services: null, total: null },
        });

        assert.equal(message, '');
    });

    test('não envia quando só a parcela de serviços está faltando', () => {
        const message = buildMessage({
            ...baseProps,
            financialValues: { services: null, total: 1250 },
        });

        assert.equal(message, '');
    });

    test('templates sem variáveis financeiras seguem funcionando sem valores', () => {
        const message = buildMessage({
            ...baseProps,
            whats: { generatedbudget: '{{ cliente }}, sua OS {{ ordem }} está pronta.' },
            financialValues: { services: null, total: null },
        });

        assert.equal(message, 'João, sua OS 1024 está pronta.');
    });

    test('nenhum placeholder permanece sem substituição', () => {
        const message = buildMessage({
            ...baseProps,
            financialValues: { services: 10, total: 20 },
        });

        assert.equal(message.includes('{{'), false);
    });
});
