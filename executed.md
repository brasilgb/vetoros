# VetorOS — Correção da ajuda dos templates de mensagem — Resultado da execução

**Status:** correção feita e verificada localmente. **Não commitada e não publicada** nesta etapa. A versão em produção ainda é a da ajuda com gaveta lateral e lista de variáveis na tela principal (imagem `vetoros:template-help-20261106-182826`).

## Escopo

Restaurar a tela anterior à ajuda. A única adição visível na tela principal é o botão **Ajuda** ao lado do título **Variáveis**. O conteúdo detalhado fica numa gaveta sobreposta.

## Arquivos alterados

- `resources/js/pages/app/whatsapp-message/index.tsx`: base restaurada a partir de `fa570df8^`. Acrescentados:
  - título **Variáveis** com botão **Ajuda** (ícone de interrogação), no topo da seção, em linha própria (`md:col-span-2`);
  - `Sheet` (componente lateral existente do VetorOS), `side="right"`, `w-full sm:max-w-md`, com título, descrição e conteúdo em área com rolagem própria. Fecha com **X** (nativo do componente) e **Esc**.
- `resources/js/pages/app/whatsapp-message/template-help.tsx`: conteúdo da gaveta (instruções, finalidade de cada variável, exemplo, regras de envio, dica das peças, botões **Copiar** dentro da gaveta, com confirmação "Variável copiada").
- `resources/js/pages/app/whatsapp-message/template-variables.ts`: catálogo das variáveis, usado pela gaveta e pelo teste.
- `tests/js/whatsapp-template-variables.test.ts`: teste de cobertura contra `buildMessage`.

Os campos, a ordem, a largura, o box tracejado com a lista de variáveis e os templates salvos não foram alterados. Nenhuma regra de envio foi alterada, incluindo as variáveis de peças em branco quando a O.S. não tem peças.

## Verificações

- `tsc --noEmit`: sem erros.
- `eslint` na pasta da página: sem erros. Há 1 aviso já existente em `connection-panel.tsx`.
- `prettier --check`: OK.
- `npm run test:js`: 54/54 passando.
- **Visual (harness local, fora do repositório):** a página real foi renderizada com mocks de Inertia, layout e painel de conexão. Foi comparada com a versão anterior (`fa570df8^`) nos viewports 1440×900 e 390×844.
  - Largura e posição horizontal dos campos: idênticas à versão anterior nos dois viewports.
  - Posição vertical: +48px por causa da linha do título **Variáveis** e do botão **Ajuda**. Essa é a única diferença visível com a gaveta fechada.
  - Abrir a gaveta: a caixa de texto não muda de posição nem de tamanho (medido antes e depois de abrir).
  - Edição preservada: texto digitado sobrevive a abrir, fechar com Esc, fechar com X e copiar uma variável.
  - Copiar: copia a tag completa (`{{ peças }}`), mostra "Variável copiada" e mantém a gaveta aberta.
  - Salvamento: nenhuma chamada de `patch` ao abrir, fechar ou copiar.
  - Rolagem: a área de conteúdo da gaveta rola (scrollHeight 2555 > clientHeight 828 no desktop).
  - Sem erros de página.
  - Capturas: `/tmp/claude-0-harness/shots-*.png`.

## Problema encontrado e corrigido durante a verificação

Na primeira versão, o título e o botão ficaram na primeira coluna do grid, e o box tracejado encolheu pela metade no desktop. Corrigido com `md:col-span-2` no bloco do título. Depois disso, a largura do box voltou a 1358px, igual à versão anterior.

## Pendente

- Commit e deploy da correção (não pedidos nesta etapa).
- Validação no navegador real do ambiente de produção.

## Commit e deploy da correção (2026-11-06)

- Commit: `57e0a3f2` (`fix(whatsapp): ajuda dos templates em gaveta sobreposta e layout original`), na branch `fix/efi-identificador-invalido`. Sem push.
- Imagem: `vetoros:template-help-20261106-184624`. Asset novo `index-uRCdHSlw.js` contém o texto da gaveta; manifest aponta para ele.
- Rollback: `vetoros:rollback-20261106-184624` (imagem anterior `vetoros:template-help-20261106-182826`).
- Override com backup: `/opt/infra-abrasil/docker-compose.override.yml.bak-20261106-184624-deploy-template-help-fix`.
- `up -d --no-deps --no-build` nos três serviços; `nginx -s reload` (config válida).
- Migrações: nenhuma pendente. Os três contêineres ficaram `healthy`. `https://vetoros.com.br/login` retorna 200.
- Validação no navegador de produção: pendente (feita pelo usuário).
