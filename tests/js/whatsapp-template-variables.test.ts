import assert from 'node:assert/strict';
import { describe, test } from 'node:test';
import { buildMessage } from '../../resources/js/components/WhatsAppButtonProps.tsx';
import { TEMPLATE_VARIABLE_GROUPS, toTemplateTag } from '../../resources/js/pages/app/whatsapp-message/template-variables.ts';

const baseProps = {
    customerName: 'João',
    orderNumber: '1024',
    status: 3,
    feedback: false,
    context: 'default' as const,
    daysPending: 2,
    amountDue: 10,
    financialValues: { total: 100, services: 80 },
    budget: {
        description: 'Troca de bomba',
        parts: [{ quantity: 1, description: 'Bomba', total: 20 }],
        parts_value: 20,
    },
};

const render = (template: string, overrides: Partial<typeof baseProps> = {}) =>
    buildMessage({ ...baseProps, ...overrides, whats: { generatedbudget: template } } as Parameters<typeof buildMessage>[0]);

describe('variáveis documentadas dos templates', () => {
    const allVariables = TEMPLATE_VARIABLE_GROUPS.flatMap((group) => group.variables);

    test('cada variável da ajuda é substituída pelo envio (nenhuma tag fica sem resolver)', () => {
        for (const variable of allVariables) {
            const message = render(`Início ${toTemplateTag(variable.tag)} fim`);

            assert.ok(!message.includes('{{'), `variável ${variable.tag} não foi resolvida: ${message}`);
            assert.ok(message.includes('Início') && message.includes('fim'), `texto fixo perdido para ${variable.tag}`);
        }
    });

    test('peças com e sem acento produzem a mesma mensagem', () => {
        assert.equal(render('{{ peças }}'), render('{{ pecas }}'));
        assert.equal(render('{{ valor_orçamento }}'), render('{{ valor_orcamento }}'));
        assert.equal(render('{{ valor_serviço }}'), render('{{ valor_servico }}'));
    });

    test('sem peças nem descrição, as variáveis do orçamento saem em branco', () => {
        const withoutParts = {
            ...baseProps,
            budget: { description: null, parts: [], parts_value: null },
        };
        const message = render('Orçamento {{ ordem }}\n{{ descricao_orcamento }}\n{{ peças }}\n{{ valor_pecas }}', withoutParts);

        assert.ok(!message.includes('R$ 0,00'));
        assert.ok(!message.includes('{{'));
    });

    test('tags das variáveis são únicas', () => {
        const tags = allVariables.map((variable) => variable.tag);
        assert.equal(new Set(tags).size, tags.length);
    });
});
