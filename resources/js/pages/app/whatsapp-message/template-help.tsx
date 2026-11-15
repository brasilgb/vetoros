import { toastError, toastSuccess } from '@/components/app-toast-messages';
import { Button } from '@/components/ui/button';
import { Copy } from 'lucide-react';
import { TEMPLATE_VARIABLE_GROUPS, toTemplateTag, type TemplateVariable } from './template-variables';

export const copyTemplateTag = async (variable: string) => {
    try {
        await navigator.clipboard.writeText(toTemplateTag(variable));
        toastSuccess('Variável copiada', toTemplateTag(variable));
    } catch {
        toastError('Não foi possível copiar', 'Copie a variável manualmente.');
    }
};

function VariableRow({ variable }: { variable: TemplateVariable }) {
    return (
        <li className="flex items-start justify-between gap-3 py-2">
            <div className="min-w-0">
                <code className="text-sm break-all">{toTemplateTag(variable.tag)}</code>
                <p className="text-muted-foreground text-xs">{variable.description}</p>
            </div>
            <Button
                type="button"
                variant="outline"
                size="sm"
                className="shrink-0"
                onClick={() => copyTemplateTag(variable.tag)}
                aria-label={`Copiar ${toTemplateTag(variable.tag)}`}
            >
                <Copy />
                Copiar
            </Button>
        </li>
    );
}

export function VariableList() {
    return (
        <div className="grid gap-4 md:grid-cols-2">
            {TEMPLATE_VARIABLE_GROUPS.map((group) => (
                <section key={group.title} className="rounded-md border p-3">
                    <h3 className="text-sm font-medium">{group.title}</h3>
                    <ul className="divide-y">
                        {group.variables.map((variable) => (
                            <VariableRow key={variable.tag} variable={variable} />
                        ))}
                    </ul>
                </section>
            ))}
        </div>
    );
}

export function TemplateHelpContent() {
    return (
        <div className="grid gap-6 text-sm">
            <section className="grid gap-2">
                <h3 className="font-medium">Como usar</h3>
                <ol className="text-muted-foreground list-decimal space-y-1 pl-5">
                    <li>
                        Para usar as mensagens padrão, clique em <strong className="text-foreground">Salvar</strong>.
                    </li>
                    <li>
                        Para personalizar uma mensagem, altere o texto e clique em <strong className="text-foreground">Salvar</strong>.
                    </li>
                    <li>
                        Para inserir uma informação automática, copie a variável e cole no ponto desejado da mensagem, mantendo as chaves{' '}
                        <code>{'{{ }}'}</code>. Ao gerar a mensagem, o VetorOS substitui a variável pelo dado da O.S. ou do cliente.
                    </li>
                </ol>
            </section>

            <section className="grid gap-2">
                <h3 className="font-medium">Exemplo</h3>
                <div className="bg-muted/40 rounded-md p-3">
                    <p className="text-muted-foreground text-xs">No template</p>
                    <code className="block break-words">
                        {'{{ saudacao }}, {{ cliente }}! Sua O.S. {{ ordem }} foi concluída. Qualquer dúvida, responda esta mensagem.'}
                    </code>
                </div>
                <div className="bg-muted/40 rounded-md p-3">
                    <p className="text-muted-foreground text-xs">Na mensagem</p>
                    <p className="break-words">Bom dia, Ana! Sua O.S. 1234 foi concluída. Qualquer dúvida, responda esta mensagem.</p>
                </div>
            </section>

            <section className="grid gap-2">
                <h3 className="font-medium">Regras de envio</h3>
                <ul className="text-muted-foreground list-disc space-y-1 pl-5">
                    <li>
                        Um modelo que usa <code>{'{{ valor_orçamento }}'}</code> ou <code>{'{{ valor_serviço }}'}</code> fica bloqueado enquanto a
                        O.S. não tiver esse valor definido, para nunca sair R$ 0,00 por engano. Essa regra vale para os envios da O.S.
                    </li>
                    <li>
                        O aviso de agendamento ao técnico não usa as variáveis da O.S. Ele aceita somente as variáveis do grupo “Agendamento ao
                        técnico”, além de <code>{'{{ saudacao }}'}</code> e <code>{'{{ cliente }}'}</code>.
                    </li>
                    <li>
                        Sem peças ou sem descrição no orçamento, as variáveis correspondentes ficam em branco. Linhas que ficam só com um rótulo, como
                        “Peças:”, são removidas automaticamente.
                    </li>
                </ul>
            </section>

            <section className="grid gap-2">
                <h3 className="font-medium">Dica para o orçamento com peças</h3>
                <p className="text-muted-foreground">
                    Deixe as variáveis de peças em linhas próprias. Um texto fixo como “Total das peças” continuará aparecendo mesmo quando a variável
                    estiver vazia.
                </p>
                <div className="bg-muted/40 rounded-md p-3">
                    <code className="block break-words whitespace-pre-wrap">
                        {'Segue o orçamento da O.S. {{ ordem }}.\n{{ descricao_orcamento }}\n{{ peças }}\n{{ valor_pecas }}'}
                    </code>
                </div>
            </section>

            <section className="grid gap-2">
                <h3 className="font-medium">Variáveis</h3>
                <p className="text-muted-foreground">Use o botão Copiar para colar a variável exatamente como o sistema reconhece.</p>
                <VariableList />
            </section>
        </div>
    );
}
