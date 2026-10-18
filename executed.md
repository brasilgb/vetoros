# Execução — VETOR-CHAT-06

Data: 18/10/2026  
Comparação: `correio.md` mudou da tarefa VETOR-CHAT-05 para VETOR-CHAT-06. Este relatório substitui integralmente o anterior.

## Causa encontrada

O histórico aponta a regressão para `9c69101a`: o cast criptográfico da chave foi trocado por um accessor tolerante. Quando a descriptografia falha, o accessor retorna `null`; a interface só mostra o botão da chave quando `publicAccessKey` tem valor. Assim, a chave e o botão somem da tela, embora o hash usado para validar o acesso público possa continuar funcionando.

A descriptografia pode falhar se uma chave cifrada foi gravada com uma `APP_KEY` anterior. O projeto já aceita rotação por `APP_PREVIOUS_KEYS` em `config/app.php`, mas essa variável não estava documentada no `.env.example`. Para valores legados em texto claro, agora há fallback restrito: a chave só é devolvida quando tem oito caracteres alfanuméricos maiúsculos e confere com o hash persistido.

Uma chave cifrada por uma `APP_KEY` desconhecida não pode ser reconstruída a partir do hash. Nesse caso, é necessário recuperar a chave de criptografia anterior e configurá-la em `APP_PREVIOUS_KEYS`; nenhuma chave de OS foi recriada ou substituída nesta execução.

## Correções

- `app/Models/App/Order.php`: recupera apenas chaves legadas em texto claro com formato válido e hash correspondente; preserva o comportamento seguro para valores cifrados por chave desconhecida.
- `.env.example`: documenta `APP_PREVIOUS_KEYS` para rotação de `APP_KEY`.
- `tests/Feature/App/OrderAccessKeyResilienceTest.php`: cobre chave legada confirmada pelo hash, chave com hash incompatível e cifra feita com outra chave.
- `tests/Feature/App/OrderControllerTest.php`: valida criação, persistência cifrada, hash e envio da chave à página React/Inertia da OS.

Não foi necessária migration. Não houve alteração de rotas, isolamento por tenant ou widget de chat.

## Validações

- Cinco testes focados passaram, com 48 assertions: criação e exibição da chave; leitura resiliente; rejeição de chave legada sem hash correspondente; e acesso público com chave correta/incorreta.
- Pint nos arquivos PHP alterados passou.
- `npm run types` passou.
- `npm run build` passou.
- `git diff --check` passou.
- A inspeção visual automatizada em Chromium/Firefox desktop/mobile foi tentada, mas os navegadores encerraram ao iniciar no sandbox. O teste Inertia confirmou que a chave chega à página; a confirmação visual em navegador permanece pendente.
- CI do commit `09338818` passou, incluindo TypeScript, testes JavaScript, build, suíte PHP e secret guard: [workflow CI 39132342514](https://github.com/brasilgb/vetoros/actions/runs/39132342514).
- Testes emitiram dois avisos PHPUnit preexistentes sobre metadata em doc-comments, sem falhas.

## Homologação e produção

Os testes usaram banco SQLite temporário. Não houve acesso a banco, `.env`, proxy ou usuários de produção, nem execução de migrations ou deploy.

Antes de publicar, verificar no ambiente o valor correto de `APP_KEY` e, caso ela tenha sido rotacionada, adicionar a chave anterior a `APP_PREVIOUS_KEYS` pelo mecanismo protegido de configuração. Não versionar chaves. Reabrir uma OS existente com chave conhecida, validar a página pública e confirmar que outra empresa não acessa essa OS. Depois, conferir a criação de uma OS nova, a exibição da chave no desktop/mobile e as respostas para chave inválida ou revogada. Não regenerar chaves em lote.

## Pendências

- Confirmar no ambiente afetado se houve rotação de `APP_KEY` e configurar a chave anterior, se disponível.
- Fazer homologação visual em Chromium e Firefox, desktop e mobile, quando o navegador puder ser iniciado.
- Fazer homologação visual em navegador quando Chromium/Firefox puderem ser iniciados no ambiente. Nenhuma ação de produção foi executada.
