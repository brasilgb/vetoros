# VETOROS-EFI-TEST-01 — Resultado da implementação

Data: 20/10/2026
SHA-256 de `correio.md`: `a79723076960ab6d9c66e56b93bd01a3b89d6342e4ff0992d17610c6ec040cb3`
Hash do relatório substituído: `84a317d107d3b6811e9f990e0a2444f2e947f5b2d313310e4923be98bd60f183` (VETOR-CHAT-10.1). Este arquivo substitui integralmente o relatório anterior.

## Auditoria prévia da integração Efí

- `EfiPixClient` (API Pix com mTLS: cob, QR Code, consulta, remoção, devolução, webhook) e `EfiChargesClient` (API Cobranças: boleto/Bolix e cartão one-step, consulta, notificações, recorrência).
- `SaasPaymentService` cria e concilia as cobranças reais em `payments`, ligadas a tenant/contrato/plano, e quita competências pelo motor comercial.
- Webhooks `/api/webhooks/efi/{token}/pix` e `/api/webhooks/efi-charges/{token}` (segredo na URL; confirmação sempre por consulta à API).
- Tela RootAdmin → Financeiro → Efí Bank (`admin/finance/efi`), grupo `root.admin`, com teste de conexão (só OAuth, sem cobrança).
- O cartão já é tokenizado no navegador pela biblioteca oficial `payment-token-efi`.

Conclusão: dava para reutilizar clientes, credenciais, ambiente, tokenização e webhooks. Não dava para gravar os testes em `payments`, porque esse fluxo exige tenant e alimenta financeiro, quitação e auditorias comerciais.

## Implementado

- **Botão "Testar pagamento"** no card principal da tela Efí Bank, abrindo um modal sem sair da página.
- **Modal** (`resources/js/pages/admin/finance/efi-test-modal.tsx`):
  - mostra o ambiente ativo (Homologação/Produção);
  - permite escolher Pix, Boleto ou Cartão, conforme as credenciais configuradas;
  - tem valor inicial R$ 1,00, editável, com o mínimo de cada método;
  - preenche dados fictícios de pagador/endereço em homologação, editáveis, e deixa os campos vazios em produção;
  - tem o botão **Gerar cobrança de teste**;
  - mostra etapas com horário, erros e resultado (método, valor, ambiente, ID Efí, situação local e da Efí, webhook, mensagem de sucesso/falha);
  - tem o botão **Consultar novamente**.
- **Pix**:
  - cria a cobrança pelo `EfiPixClient` existente (txid `VOT…`, expiração de 1 h) e mostra o QR Code e o Copia e Cola;
  - o webhook Pix existente reconhece o txid de teste, registra o recebimento e concilia em fila (`ProcessEfiIntegrationTest`);
  - o modal faz polling a cada 5 s e se atualiza sozinho; também há consulta manual.
  - O devedor passou a ser opcional: `createImmediateCharge` omite `devedor` vazio, sem mudar o comportamento das cobranças reais.
- **Boleto**:
  - cria pelo `EfiChargesClient`, com vencimento em D+3 e a URL de notificação existente;
  - mostra vencimento, linha digitável e link/PDF (e o Pix do Bolix, quando a conta devolve);
  - a notificação da API Cobranças reconhece o `charge_id` de teste e concilia só o teste.
- **Cartão**:
  - tokeniza no navegador com `payment-token-efi` (o mesmo fluxo do checkout); número, CVV e validade nunca chegam ao VetorOS, e os campos são limpos após a tokenização;
  - cobra via `createCardCharge` e consulta a situação logo em seguida;
  - mostra autorização (`paid`/`approved`), recusa (`unpaid`) ou erro da API;
  - o servidor não grava o `payment_token`, só a máscara.
- **Isolamento**:
  - nova tabela `efi_integration_tests` com `source=integration_test`, sem tenant, contrato, assinatura ou `payments`;
  - a conciliação dos testes só atualiza o próprio registro: não emite nota, não quita competência, não altera financeiro nem indicadores;
  - o webhook só procura um teste quando o identificador não pertence a uma cobrança real.
- **Segurança**:
  - rotas no grupo `root.admin` (`admin.finance.efi.integration-tests.*`), com throttle;
  - credenciais e ambiente são lidos, nunca alterados, e o teste não exige nem altera a homologação;
  - em **Produção**, a criação exige o checkbox de confirmação explícita **e** a senha do RootAdmin.
- **Registro**:
  - cada teste guarda identificador Efí, referência, método, ambiente, valor, datas, status, status Efí, webhook e erro técnico (mensagem saneada da `EfiException`, sem credenciais);
  - cada criação gera a auditoria `efi.integration_test_created`.
- **Últimos testes**: nova seção na tela com os 10 últimos (método, valor, status, data, ambiente, autor) e **Ver detalhes**, que reabre o modal com o resultado.

## Arquivos

- Novos:
  - `app/Models/Admin/EfiIntegrationTest.php`
  - `app/Services/Payments/EfiIntegrationTestService.php`
  - `app/Jobs/ProcessEfiIntegrationTest.php`
  - `app/Http/Controllers/Admin/Finance/EfiIntegrationTestController.php`
  - `database/migrations/2026_10_20_100000_create_efi_integration_tests_table.php`
  - `resources/js/pages/admin/finance/efi-test-modal.tsx`
  - `tests/Feature/Admin/EfiIntegrationTestTest.php`
- Alterados:
  - `app/Services/Payments/SaasPaymentService.php`: os webhooks Pix e Cobranças reconhecem os testes;
  - `app/Services/Payments/Efi/EfiPixClient.php`: devedor opcional;
  - `app/Http/Controllers/Admin/Finance/EfiSettingsController.php`: props do modal e dos últimos testes, só leitura do banco;
  - `routes/admin-finance.php`;
  - `resources/js/pages/admin/finance/efi.tsx`.

## Testes e verificações

- `tests/Feature/Admin/EfiIntegrationTestTest.php`: **10 testes, 98 assertions, aprovados**, contra o simulador `FakesEfi` (sem credencial ou cobrança real):
  - Pix: QR/Copia e Cola, webhook com confirmação pela API, idempotência e atualização automática; consulta manual, e webhook sem pagamento mantém pendente;
  - Boleto: vencimento, linha digitável, link, notificação Cobranças reconhecida, mínimo de valor;
  - Cartão: autorização, recusa, token não armazenado;
  - erro da API registrado sem credenciais e auditado;
  - produção exige confirmação e senha, sem chamada à Efí antes disso, e o ambiente não é alterado;
  - apenas RootAdmin; método sem credencial é recusado; a tela lista os testes sem chamar a Efí;
  - todos verificam o isolamento: nenhum `payments`, tenant ou auditoria `saas_payment.*`/`commercial.*` criado.
- `tests/Feature/Admin/EfiSaasPaymentsTest.php` (fluxo real existente): aprovado, sem regressão.
- Suíte PHP completa (`php artisan test`): **825 testes, 0 falhas**.
  - Os avisos vêm apenas da ausência de `.env` neste diretório; usei uma `APP_KEY` descartável só na execução.
  - Antes do `npm run build`, 77 testes falhavam só por falta do manifest do Vite.
- Frontend: `tsc --noEmit` sem erros; ESLint e Prettier sem pendências nos arquivos alterados; `npm run build` concluído.
- Pint aplicado nos arquivos PHP alterados; `git diff --check` sem problemas nos arquivos da tarefa.
- Ambiente: `vendor/` e `node_modules/` não existiam e foram instalados a partir dos lockfiles (`composer install`, `npm ci`). O `npm ci` alterou o `yarn.lock`; a alteração foi revertida.

## Limitações e pendências

- **Nenhuma cobrança real nem chamada à Efí foi feita.** Falta a homologação manual no ambiente de homologação da Efí, com credenciais reais.
- **Interface responsiva:** usa grid de 1 coluna no mobile e 2 a partir de `sm`, com rolagem interna (`max-h-[90vh]`). Não foi inspecionada visualmente no navegador, porque não há banco ou `.env` para subir a aplicação aqui.
- **Valores mínimos:** Pix R$ 0,01; boleto e cartão R$ 5,00 (`EfiIntegrationTestService::MINIMUM_CENTS`). O mínimo de R$ 5,00 do boleto veio de fonte de terceiros; o do cartão não foi encontrado. Ambos precisam ser confirmados na documentação oficial (dev.efipay.com.br).
- **Homologação Pix:** relatos não oficiais dizem que cobranças de até R$ 9,99 são concluídas automaticamente no sandbox, disparando o webhook. Não confirmado.
- **Cartões de teste:** não foram encontrados nas fontes consultadas; o modal só orienta a usar os cartões indicados pela Efí.
- **Webhook:**
  - Pix: depende do webhook já cadastrado pelo botão existente "Cadastrar webhook Pix";
  - Cobranças: usa a `notification_url` de cada cobrança;
  - os dois exigem a aplicação acessível por HTTPS público.
- **Cancelamento:** cobranças de teste pendentes (Pix/boleto) não são canceladas pelo modal; expiram sozinhas (Pix em 1 h, boleto no vencimento).
- **Migration:** não foi aplicada em nenhum banco de desenvolvimento ou produção; é preciso rodar `php artisan migrate` no deploy.
- Nenhum commit, push ou deploy foi feito.

## Adendo — Auditoria na VPS (25/10/2026)

**Status: não resolvido.** O cartão continua bloqueado; Pix e boleto estão funcionando em homologação.

### Verificações na VPS

- Migration `2026_10_20_100000_create_efi_integration_tests_table`: **aplicada** (status `Ran`). Nenhuma migration foi executada.
- Ambiente Efí: `homologation`. Pix e Cobranças configurados. O identificador de conta (`efi_payee_code`) tem **14 caracteres alfanuméricos**.
- Tabela `efi_integration_tests`: 2 registros, ambos de homologação. **Nenhum registro de cartão** e nenhum com `identificador_invalido`.
- Auditoria `efi.integration_test_created`: 2 registros (25/10 00:05:32 e 00:20:12, horário do banco).
- `storage/logs/laravel.log` e `docker logs` (72 h): nenhuma ocorrência de Efí ou de `identificador_invalido`.
- Código no container (`vetoros:pull-5f7caf3`, iniciado em 24/10 21:14): tem o teste de integração, mas **não tem** o `identifierHint` do commit `e1a94898`.

### Resultados individuais

- **Pix:** pago em homologação. R$ 1,00, txid `VOTKRFAJ…`, situação Efí `CONCLUIDA`, pago em 25/10 00:05:32. Webhook recebido em 00:06:02.
- **Boleto:** cobrança criada em homologação. R$ 5,00, vencimento 28/10/2026, `charge_id` 45055281, situação Efí `waiting`, webhook recebido em 00:20:19. Status local `pending`, que é o esperado enquanto o boleto não é pago. O `identificador_invalido` relatado para boleto **não foi reproduzido**: boleto não usa o identificador de conta, e não há registro nem log desse erro.
- **Cartão:** **não concluído.** A falha ocorre na tokenização no navegador, antes de qualquer chamada ao VetorOS. Por isso não há registro nem log no servidor.

### Causa raiz (comprovada)

- A biblioteca `payment-token-efi` 3.4.1 (`node_modules/payment-token-efi/dist/payment-token-efi-cjs.min.js`) valida o identificador de conta localmente com `/^[a-zA-Z0-9]{32}$/`. Se o valor não bater, ela lança `identificador_invalido` ("Identificador de conta [...] inválido") **sem chamar a Efí**.
- O `efi_payee_code` salvo tem 14 caracteres, então `EfiPay.CreditCard.setAccount(payee_code)` falha em `resources/js/pages/admin/finance/efi-test-modal.tsx` (linha ~142). O modal mostra o erro, e `generate()` retorna antes do POST.
- **Origem:** SDK de tokenização (validação local). Não é a API da Efí nem o VetorOS no servidor.
- Documentação Efí (https://dev.efipay.com.br/en/docs/api-cobrancas/cartao/): o identificador de conta vem de "API > Introdução > Identificador de conta". O exemplo da documentação tem 32 caracteres hexadecimais.
- Não foi possível confirmar qual é o valor correto. Ele não foi exibido nem copiado durante a auditoria.

### Correção aplicada

- **Nenhuma alteração de código nem de configuração.** O valor correto do identificador de conta não está disponível no VPS.
- Para corrigir, é preciso copiar o identificador de 32 caracteres do painel Efí (API > Introdução > Identificador de conta), salvar em Efí Bank com a senha do RootAdmin e repetir o teste de cartão em homologação.
- O mesmo `efi_payee_code` alimenta o checkout de cartão real (`SaasCheckoutController`). Ao trocar o valor, o checkout também deve ser verificado.

### Estado do VETOROS-EFI-FIX-01 (item 9)

- O commit `e1a94898` existe no branch `fix/efi-identificador-invalido`, mas **não está implantado**. O diagnóstico por operação não está operacional no VPS.
- O deploy não foi feito: não foi solicitado, e reiniciar os serviços não estava no escopo deste adendo.

### Testes

- Nenhum código foi alterado, então os testes automatizados não foram reexecutados nesta rodada.

### Pendências

1. Informar o identificador de 32 caracteres ou salvá-lo na tela Efí Bank; depois repetir o teste de cartão em homologação.
2. Decidir se o `e1a94898` deve ser implantado.
3. Opcional: validar `payee_code` com exatamente 32 caracteres alfanuméricos em `EfiSettingsController::update`. Hoje aceita qualquer valor alfanumérico de até 64. Isso exigiria ajustar as fixtures `abc123payee` em `tests/Concerns/FakesEfi.php` e `tests/Feature/Admin/EfiSaasPaymentsTest.php`.
