# Execução — VETOR-CHAT-08

Data: 18/10/2026  
Comparação: `correio.md` mudou do pedido VETOR-STABILIZE-03 para VETOR-CHAT-08. Este relatório substitui o anterior.

## Implementação

- Adicionada ação individual de regeneração da chave pública na edição da OS, restrita a administradores com permissão de OS e acompanhada de confirmação e motivo obrigatório.
- Backend autoriza a operação e aplica isolamento por tenant. A regeneração usa transação com bloqueio da linha e revalida a chave após adquirir o lock, evitando substituições simultâneas ou repetidas.
- A nova chave tem 8 caracteres alfanuméricos em maiúsculas; o valor é cifrado pelo accessor e seu hash é atualizado na mesma transação. A chave anterior deixa de validar.
- OS cuja chave ainda pode ser recuperada não pode ser regenerada. Nenhuma chave foi regenerada automaticamente ou em lote.
- Auditoria registra usuário, tenant/empresa, OS, data, motivo sanitizado e revogação anterior; nenhum valor da chave é guardado em log ou auditoria.
- A leitura de chave cifrada agora também exige correspondência do hash armazenado.
- Corrigidos os 42 erros ESLint de `edit-order.tsx`, com tipos explícitos e dependências dos hooks; ajustados tipos do equipamento vinculado e do modal fiscal.

## Arquivos

- `app/Http/Controllers/App/OrderController.php`, `app/Models/App/Order.php`, `routes/app.php`: endpoint, autorização, transação, auditoria e validação da chave.
- `resources/js/pages/app/orders/edit-order.tsx`: ação, modal de confirmação e correções ESLint/tipagem.
- `resources/js/pages/app/orders/customer-equipment-field.tsx`, `resources/js/components/Modals/InvoiceModal.tsx`: tipos compartilhados e compatibilidade de valores nulos.
- `tests/Feature/App/OrderPublicAccessKeyRegenerationTest.php`, `tests/Feature/App/OrderAccessKeyResilienceTest.php`: autorização, isolamento, regeneração, revogação, auditoria, concorrência e integridade da chave.
- `correio.md` foi mantido fora dos commits.

## Verificações

- `npm run types`: passou.
- ESLint em `edit-order.tsx` e `InvoiceModal.tsx`: passou sem erros.
- Pint nos arquivos PHP alterados: passou.
- Testes focados de chave e acesso público: 19 testes e 139 assertions passaram.
- Suíte PHP completa (`php artisan test`): 812 testes e 5.691 assertions passaram.
- `npm run build`: passou. O Vite reportou o aviso existente de chunk PDF acima de 500 kB.
- CI passou para `d7ccb3b94c5f7bd48b86dafd8e0e6de95b0707b7`: [workflow 39133812502](https://github.com/brasilgb/vetoros/actions/runs/39133812502).
- Validação visual em navegador não concluída; Chromium/Firefox já haviam falhado ao iniciar neste sandbox.

## Publicação e restrições

- Commit de implementação: `d7ccb3b9` — `Add audited OS public key regeneration` (enviado à `main`).
- Nenhuma `APP_KEY` foi alterada ou consultada, não houve migration, acesso ao banco/configuração de produção nem deploy.
- O relatório foi registrado em commit local separado. O push desse commit não foi concluído: a revisão automática bloqueou a publicação por exigir autorização explícita para atualizar novamente a branch remota `main`.
