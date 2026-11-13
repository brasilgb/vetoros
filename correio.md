# VetorOS — Ajuda na tela de templates de mensagem

Implemente um botão **Ajuda** com ícone de interrogação ao lado do título **Variáveis** na tela de templates de mensagem. Ao clicar, abra um modal com o título **Como usar os templates de mensagem**, seguindo o padrão visual do VetorOS. Mantenha a lista de variáveis visível na tela principal e coloque a explicação detalhada no modal.

Antes de implementar, confira no código quais variáveis o sistema aceita, em quais templates elas funcionam e quando o envio é bloqueado por falta de valor na O.S. Use na interface a grafia exata que o sistema reconhece. Confira especialmente as versões com e sem acento, como `{{ peças }}` e `{{ pecas }}`. Preserve a compatibilidade com os templates já salvos.

## Conteúdo da ajuda

### Como usar

1. Para usar as mensagens padrão, clique em **Salvar**.
2. Para personalizar uma mensagem, altere o texto e clique em **Salvar**.
3. Para inserir uma informação automática, copie a variável da lista e cole no ponto desejado da mensagem, mantendo as chaves `{{ }}`. Ao gerar a mensagem, o VetorOS substitui a variável pelo dado da O.S. ou do cliente.

Mostre um exemplo curto:

**No template**  
`{{ saudacao }}, {{ cliente }}! Você pode acompanhar a O.S. {{ ordem }} por aqui: {{ link_os }}`

**Na mensagem**  
Bom dia, Ana! Você pode acompanhar a O.S. 1234 por aqui: https://exemplo.com/os/1234

### Cliente e ordem de serviço

| Variável | Para que serve |
|---|---|
| `{{ cliente }}` | Insere o nome do cliente. |
| `{{ ordem }}` | Insere o número da ordem de serviço. |
| `{{ link_os }}` | Insere o link para o cliente acompanhar a O.S. |
| `{{ saudacao }}` | Insere uma saudação como “Bom dia”, “Boa tarde” ou “Boa noite”. |

### Pendências

| Variável | Para que serve |
|---|---|
| `{{ saldo }}` | Insere o valor que ainda está pendente de pagamento. |
| `{{ dias_pendentes }}` | Insere a quantidade de dias de pendência. |

### Orçamento

| Variável | Para que serve |
|---|---|
| `{{ valor_orçamento }}` | Insere o total do orçamento, incluindo serviços e peças. |
| `{{ valor_serviço }}` | Insere o valor dos serviços. |
| `{{ descricao_orcamento }}` | Insere a descrição registrada no orçamento. Se estiver vazia, a variável fica em branco. |
| `{{ peças }}` | Insere a lista de peças incluídas no orçamento. Se a O.S. estiver sem peças, a variável fica em branco. |
| `{{ valor_pecas }}` | Insere o total das peças. Se a O.S. estiver sem peças, a variável fica em branco. |

Explique que, quando a mensagem exige um valor e a O.S. ainda está sem esse valor definido, o envio fica bloqueado. Indique quais mensagens seguem essa regra, conforme o comportamento verificado no código.

Mostre também este exemplo com as peças em linhas separadas:

`Segue o orçamento da O.S. {{ ordem }}.`  
`{{ descricao_orcamento }}`  
`{{ peças }}`  
`{{ valor_pecas }}`

Inclua a dica **“Se o orçamento puder ficar sem peças, deixe as variáveis de peças em linhas próprias. Um texto fixo como ‘Total das peças’ continuará aparecendo mesmo quando a variável estiver vazia.”**

### Agendamento ao técnico

| Variável | Para que serve |
|---|---|
| `{{ tecnico }}` | Insere o nome do técnico responsável. |
| `{{ data_visita }}` | Insere a data agendada para a visita. |
| `{{ servico }}` | Insere o serviço previsto para a visita. |
| `{{ materiais }}` | Insere os materiais informados no agendamento. |
| `{{ endereco }}` | Insere o endereço da visita. |

## Validação

- Abra e feche a ajuda no computador e em tela estreita. Confira a rolagem e a navegação por teclado.
- Confira que o botão **Ajuda** abre o modal sem enviar o formulário ou apagar alterações ainda em edição.
- Confira que o usuário ainda consegue salvar as mensagens padrão e personalizar os textos normalmente.
- Valide as variáveis da ajuda nos templates em que são aceitas. Teste um orçamento com peças e outro sem peças. No segundo caso, a lista e o total das peças devem ficar em branco, sem exibir `R$ 0,00`. Teste também um orçamento sem descrição.
- Se o comportamento existente divergir do texto solicitado, identifique a divergência no relatório. Preserve o escopo da mudança na interface de ajuda.
- Registre no `executed.md` os arquivos alterados, a grafia das variáveis confirmada no código e as validações realizadas.


Adicione um botão **Ajuda**, com ícone de interrogação, ao lado do título **Variáveis** na tela de templates de mensagem.

No desktop, abra uma gaveta à direita, com o título **Como usar os templates de mensagem** e rolagem própria. Mantenha o formulário visível e editável enquanto a gaveta estiver aberta, para o usuário consultar a ajuda e aplicar as variáveis sem fechar o painel.

No celular, apresente o mesmo conteúdo em um modal adaptado à largura da tela. Reutilize o componente de gaveta ou modal já existente no VetorOS, conforme o espaço disponível.

Inclua um botão **Copiar** ao lado de cada variável. Ele deve copiar a tag completa, com as chaves e a grafia aceita pelo sistema, e exibir a confirmação **Variável copiada**. Abrir ou fechar a ajuda e copiar uma variável devem preservar o texto em edição e evitar o envio do formulário.

Mantenha a lista de variáveis na tela principal e use a gaveta ou o modal para mostrar as instruções, a finalidade de cada variável e os exemplos já definidos neste prompt.
