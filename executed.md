# VETOROS-WHATSAPP-01 — Correção de telefones e travamento no envio

Data: 30/10/2026. **Status: IMPLEMENTADO E TESTADO EM AMBIENTE LOCAL.** Não houve homologação com WAHA real nem commit/push (não solicitados).

## Comparação do correio.md

O `correio.md` difere do último commit (`HEAD`): o commit trazia VETOROS-DEPLOY-SEC-01 (commit, push e deploy em produção); o atual traz VETOROS-WHATSAPP-01. O relatório anterior (VETOROS-NFSE-09) foi substituído.

## Diagnóstico (leitura do código)

- **Travamento / erro sem retorno:** `WahaService::resolveChatId()` (consulta `/api/contacts/check-exists`) não capturava exceções de conexão nem timeout. Uma falha de rede virava erro 500 sem mensagem para o usuário. As demais chamadas ao WAHA já passavam pelo `request()`, que converte as exceções em `WhatsAppException`.
- **Validação de telefone frouxa:** `WhatsAppPhone::isValid()` aceitava qualquer número com 12 dígitos após o DDI, sem checar DDD nem formato de celular. Fixos (começando com 2 a 5) passavam e só falhavam depois, na consulta ao WAHA.
- **Normalização ambígua:** `normalize()` e o `normalizeWhatsappPhone()` do frontend prefixavam 55 em qualquer número que não começasse com 55. Um celular de DDD 55 digitado sem DDI (`55 9xxxx-xxxx`) virava número inválido.
- **Mensagens:** textos genéricos ("Número de WhatsApp inválido.", "Serviço ... indisponível") e a mensagem de sucesso dizia "enviada", embora o WAHA apenas aceite a requisição.
- **Verificado sem alteração:** o botão já é desabilitado durante o envio (`isSending` e `sendingWhatsappId`), e o estado é restaurado em `onFinish`, inclusive em erro. Não há modal no fluxo de envio, então não há modal a fechar.

## Decisões

1. **Normalização:** números com 10 ou 11 dígitos recebem DDI 55. Números com 12 ou 13 dígitos são mantidos como estão. O valor salvo no cadastro não muda.
2. **Validação:** DDD de 11 a 99; celular de 9 dígitos começando com 9, ou formato antigo de 8 dígitos começando com 6 a 9. Fixos são rejeitados antes de chamar o WAHA, porque não têm WhatsApp.
3. **Timeout:** consulta de existência com 10 s (`CHECK_EXISTS_TIMEOUT_SECONDS`); envio com os 15 s já existentes no cliente HTTP.
4. **Mensagens:** textos do correio para número inválido, sem WhatsApp, sessão desconectada, erro de conexão e envio aceito. Para a validação de número, o texto "cadastrado para este cliente" também aparece no envio de técnicos (agendamentos), onde o cadastro é do técnico. Se preferir um texto neutro, basta alterar `WhatsAppException::invalidPhone()`.
5. **Duplicidade:** mantida a proteção existente de botão desabilitado durante o envio. Não foi criada deduplicação no servidor.
6. **Escopo:** os três pontos de envio (cliente, OS via `WhatsAppButtonProps`, agendamento do técnico) passaram a usar a mesma validação do frontend (`isValidWhatsappPhone`).

## Arquivos alterados

Backend:
- `app/Support/WhatsAppPhone.php`: normalização e validação.
- `app/Services/WahaService.php`: `resolveChatId()` com try/catch e timeout.
- `app/Exceptions/WhatsAppException.php`: mensagens segundo o correio.
- `app/Http/Controllers/App/CustomerController.php`, `OrderController.php`, `ScheduleController.php`: mensagem de sucesso.

Frontend:
- `resources/js/Utils/mask.tsx`: `normalizeWhatsappPhone()` alinhado ao backend e nova `isValidWhatsappPhone()`.
- `resources/js/components/WhatsAppButtonProps.tsx`: usa `isValidWhatsappPhone()` e a nova mensagem de número inválido.
- `resources/js/pages/app/customers/index.tsx`, `resources/js/pages/app/schedules/index.tsx`: botão habilitado conforme `isValidWhatsappPhone()`.

Testes:
- `tests/Unit/Support/WhatsAppPhoneTest.php` (novo): 6 números válidos, 7 inválidos e a normalização.
- `tests/Feature/App/WhatsAppSendControllerTest.php`: mensagens atualizadas; 2 testes novos (timeout do WAHA vira mensagem amigável sem envio; fixo rejeitado sem chamar o WAHA).

## Testes executados

- `php artisan test tests/Unit/Support/WhatsAppPhoneTest.php tests/Feature/App/WhatsAppSendControllerTest.php tests/Feature/App/WhatsappConnectionControllerTest.php tests/Feature/Admin/CommercialWhatsappSettingsTest.php`: **32 passaram**.
- `php artisan test` (suíte completa): 874 passaram, **28 falharam**. Todas as falhas têm o erro "O campo técnico é obrigatório.". A causa é a alteração não commitada em `app/Http/Requests/OrderRequest.php` (`user_id` obrigatório quando a OS não está aberta), que não faz parte deste correio. Os fixtures existentes não preenchem `user_id`. Três dessas falhas estão em `OrderCommunicationTest`, e o mesmo erro aparece também em testes de OS que não envolvem WhatsApp.
- `npx tsc --noEmit`: **0 erros**.
- `npm run test:js`: **não executou**. O pacote `tsx` não está instalado neste checkout (`ERR_MODULE_NOT_FOUND`), o que afeta todos os arquivos de `tests/js`, inclusive os que não foram alterados.
- `prettier --check`: o aviso em `mask.tsx` já existia no `HEAD`. Pint aponta estilo em linhas que não fazem parte desta alteração.

## Pendências e riscos residuais

- **Homologação real:** não foi testado envio contra um WAHA real. Vale validar com um número de teste: celular válido, fixo, número sem WhatsApp e sessão desconectada.
- **Duplicidade no servidor:** cliques repetidos são bloqueados só no navegador. Duplo envio por outra aba ou requisição paralela não é impedido.
- **Testes JS:** instalar `tsx` (ou restaurar a dependência no `package.json`) para rodar `tests/js`, incluindo o novo `isValidWhatsappPhone`, que ainda não tem teste próprio no frontend.
- **Falhas da suíte completa:** resolver a validação de `user_id` em `OrderRequest.php` (ou ajustar os fixtures) antes de commitar.
- **Alteração de comportamento:** número com 12 ou 13 dígitos que não começa com 55 não recebe mais prefixo 55. Os links `wa.me` de OS e de tenants usam o mesmo `normalizeWhatsappPhone()`; a mudança só afeta esse caso raro.
- **Commit e push:** nada foi commitado nem enviado ao GitHub. Os arquivos ficaram no working tree para revisão.
