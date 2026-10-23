# VETOROS-SECURITY-FIX-01 — Correção das vulnerabilidades da auditoria

Data: 20/10/2026
SHA-256 de `correio.md`: `935173ad51fb9f4a94b4b9f114f495940a6d6f1b4a93afb16bc25cc3fce775d0`
Hash deste arquivo antes da atualização: `2fd9bc8ec01cd710e24b265330681d025b8bd632df02cd902533a4b0b7f3860b`. Os relatórios da VETOROS-AUDIT-01 e da VETOROS-EFI-TEST-01 continuam abaixo (ainda não commitados).
Referência: `docs/audits/VETOROS-AUDIT-01.md`.

Não houve commit, push, deploy, migration nem chamada externa, e nenhum dado real foi alterado. As alterações da EFI-TEST-01 foram preservadas: nenhum arquivo Efí foi tocado e o teste dela continua passando.

## 1. Vulnerabilidades corrigidas

### SEC-01 — Isolamento multitenant (C-01 da auditoria)

- **`Tenantable`:** durante qualquer requisição web ou API, o registro criado fica **sempre** na empresa do contexto (usuário ou Modo Suporte), e o `tenant_id` não pode ser alterado. Toda tentativa é bloqueada e registrada em log.
  - A trava vale só no ciclo da requisição (middleware `EnforceTenantWrites`, `TenantWriteGuard`).
  - Jobs, webhooks e comandos continuam gravando o `tenant_id` explícito, como antes.
- **Orçamentos:** `store`/`update` usam só os campos validados.
  - `equipment_id` precisa ser da própria empresa.
  - `part_value`, que o formulário enviava sem validação, entrou nas regras.
- **Serviços:** `store`/`update` usam só os campos validados, e o equipamento precisa ser da empresa.
- **Admin (Planos/Períodos, Características, Filiais):** `$request->all()` foi trocado por lista explícita dos campos do formulário.
- **Pertencimento de relacionamentos:** as 19 regras `exists:tabela,id` da área da empresa viraram `TenantRule::exists(...)`, que só aceita ID da própria empresa.
  - Alcance: OS, venda, compra, agenda, follow-up, contratos, indicadores de qualidade, equipamento do cliente e fotos.
  - Exemplo do risco: a venda aceitava `customer_id` de cliente de outra empresa.
- **`Model::unguard()` global:** foi mantido. Removê-lo exige revisar o `$fillable` de todos os models e cabe numa etapa própria. A brecha foi fechada pelas três camadas acima.

### SEC-02 — Fotos e assinaturas de OS (C-02)

- **Armazenamento:** fotos e assinatura vão para o disco privado `order_files`, em `{tenant_id}/{order_id}/images/…` e `{tenant_id}/{order_id}/signature.png`. Os nomes são aleatórios e a extensão vem do conteúdo, nunca do cliente.
- **Entrega:** só por **URL assinada e temporária** (60 min, `ORDER_FILES_URL_TTL_MINUTES`), gerada depois da autorização.
  - Rotas: `GET /files/orders/images/{image}` e `GET /files/orders/{order}/signature`.
  - A URL é emitida na tela interna da OS, na API do app e no portal público com o acesso liberado.
  - Qualquer alteração na URL invalida a assinatura.
  - As respostas levam `nosniff`, `sandbox` e só aceitam tipo de imagem rasterizada.
- **Upload pela API (base64):** passou a recusar conteúdo que não seja imagem. Antes, o nome `time().rand(1,50).png` podia colidir entre empresas.
- **Telas:**
  - As fotos internas (`images/index.tsx`), o portal e o recibo com assinatura usam o `url` assinado.
  - O portal montava `/storage/orders/{order.id}` e por isso nem exibia as fotos corretamente.
  - Na API (`/api/images/{n}`), cada foto agora traz o campo `url`.
- **Arquivos antigos:**
  - Fotos ainda não migradas continuam sendo lidas da pasta antiga, mas só quando o nome do arquivo é único entre todas as empresas.
  - **Assinaturas antigas nunca são lidas da pasta compartilhada**, porque ela é ambígua entre empresas.
- **Migração:** novo comando `php artisan vetoros:migrate-order-files`.
  - Simula por padrão; só copia com `--execute`.
  - Não apaga nem altera os originais, nunca sobrescreve arquivo já migrado e confere o SHA-256 de cada cópia.
  - Assinatura só é atribuída quando **uma única** OS com aquele número tem `customer_signature_captured_at`. Fotos com nome repetido entre empresas ficam como "ambíguas".
  - Gera relatório JSON em `storage/app/private`.

### SEC-03 — Portal público da OS (A-01)

- **Lista de campos permitidos:** o novo `PublicOrderPresenter` monta o retorno do acompanhamento, do recibo, do comprovante de pagamento e do comprovante fiscal.
- **Não saem mais:**
  - senha do aparelho, `manual_parts_cost`, observações e diagnóstico do técnico;
  - notas de recuperação de feedback, motivos de pausa de follow-up;
  - `tenant_id`, `user_id`, chave de acesso e as demais colunas do cliente (e-mail, nascimento, observações);
  - custo e estoque das peças no recibo.
- **Cliente e empresa:** a página de acompanhamento recebe do cliente só o nome. A empresa sai só com os campos que cada página exibe.
- **Recibo:** continua identificando o cliente (nome, CPF/CNPJ, telefone, endereço), como o papel entregue no balcão.
- **Chave de acesso:** sem mudança. Quando habilitada, bloqueia os dados até ser validada (coberto por teste).
- **Correção da auditoria:** `service_cost` não é custo interno; é o **total cobrado do cliente** e continua visível. O custo interno real é o `manual_parts_cost`, que deixou de sair.

### SEC-04 — Integridade financeira do PDV (A-04 e M-03)

- **Total no servidor:** calculado pelo preço atual das peças. As linhas da mesma peça são somadas para conferir o estoque, e as peças são travadas em ordem de ID para evitar deadlock.
  - Total enviado diferente do calculado (≥ R$ 0,01) dá 422, sem gravar venda nem mexer no estoque. Isso também pega preço desatualizado na tela.
- **Desconto:** o PDV não tem regra de desconto, então qualquer `discount_amount` é recusado (`prohibited`). O valor pago continua limitado ao total do servidor.
- **Cancelamento:** a venda é relida com `lockForUpdate` dentro da transação, e o status é verificado ali. Dois cancelamentos simultâneos, ou uma tela desatualizada, não devolvem o estoque duas vezes.

### Alta prioridade

- **Usuário inativo (A-02, M-02):**
  - Não entra pela web nem pela API.
  - A sessão web aberta cai na próxima requisição (`EnsureUserIsActive`).
  - Os tokens Sanctum são **apagados** ao inativar, e um token antigo é recusado mesmo que a inativação tenha sido feita fora do sistema (`EnsureTenantApiUser`).
  - Cadastros antigos com `status` nulo continuam ativos; só `status = 0` é inativo.
- **Login da API (A-03):** limite de 5 tentativas por e-mail+IP por minuto (429), mais `throttle:20,1` por IP. O contador zera após login correto.
- **Orçamentos (M-01):** novo gate `budgets.access` (permissão `budgets`) no CRUD web e no `BudgetsRequest`. Técnicos não criam, editam nem excluem. As consultas só de leitura usadas pelo app (`/api/orcamentos*`) foram mantidas.

## 2. Arquivos modificados

- **Novos:**
  - `app/Support/{TenantRule,TenantWriteGuard,OrderFiles,PublicOrderPresenter}.php`
  - `app/Http/Middleware/{EnforceTenantWrites,EnsureUserIsActive}.php`
  - `app/Http/Controllers/OrderFileController.php`
  - `app/Console/Commands/MigrateOrderFiles.php`
  - `config/order_files.php`
  - `phpstan.neon`, `phpstan-baseline.neon`
  - `tests/Feature/Security/*` (6 arquivos)
- **Alterados (segurança):**
  - `app/Tenantable.php`, `bootstrap/app.php`, `app/Providers/AppServiceProvider.php`, `app/Models/User.php`, `app/Models/App/{Image,Order}.php`;
  - `app/Support/OrderSignature.php`, `app/Services/SaleService.php`;
  - controllers `App/{Budget,Service,Sale,User,Image,Order,FollowUp,MaintenanceContract,QualityIndicator}Controller`, `OsController`, `Admin/{Period,Feature,Branch}Controller`;
  - requests `Budgets`, `Service`, `Order`, `PurchaseOrder`, `Schedule`, `CustomerEquipment`, `Auth/LoginRequest`;
  - `EnsureTenantApiUser`, `routes/{web,api}.php`, `config/filesystems.php`, `.env.example`;
  - `resources/js/pages/app/{images,serviceorders}/index.tsx`.
- **Testes antigos ajustados:** `PermissionsTest` (2) e `TechnicianScheduleApiTest` (1). Eles exigiam o comportamento inseguro (arquivo em `public/storage/orders` e conteúdo não-imagem aceito) e agora verificam o disco privado.
- **Dependências:**
  - `composer.json`/`composer.lock`: só a inclusão de `larastan/larastan` ^3.0 (dev), mais `phpstan/phpstan` e `iamcal/sql-parser` como dependências dela. Nenhum pacote existente mudou de versão.
  - `package-lock.json`: `npm audit fix` sem `--force` (somente atualizações compatíveis).
  - O `yarn.lock` alterado pelo npm foi revertido; o deploy usa `npm ci`.

## 3. Testes criados e executados

- **Novos (`tests/Feature/Security`, 42 testes, 245 asserções):**
  - `TenantIsolationTest` (7);
  - `InactiveUserAndLoginThrottleTest` (8);
  - `OrderFilesIsolationTest` (11), com duas empresas e **OS de mesmo número**;
  - `PublicOrderPortalTest` (5);
  - `MigrateOrderFilesCommandTest` (5);
  - `SaleIntegrityTest` (6).
- **Antes da correção:** todos os testes de reprodução falharam, pelo motivo da vulnerabilidade:
  - orçamento gravado no tenant da vítima;
  - total R$ 0,01 aceito;
  - estoque devolvido em dobro;
  - login de inativo aceito;
  - ausência de 429.
- **Suíte completa depois das correções:** **867 testes aprovados, 0 falhas**, 6.062 asserções (825 anteriores + 42 novos).
  - Os avisos vêm só da ausência de `.env` neste ambiente; usei uma `APP_KEY` descartável na execução.
- **PHPStan/Larastan nível 5 (`app/`):**
  - os 615 erros já existentes foram registrados em `phpstan-baseline.neon`;
  - os arquivos novos não acrescentam erros;
  - a análise final terminou `[OK] No errors`.
- **Frontend:** `tsc --noEmit` sem erros e `npm run build` concluído.
- **Dependências:** `composer audit` sem vulnerabilidades; `npm audit` com **0** (antes eram 25, sendo 2 críticas).
- **Pint:** aplicado só nos arquivos que estavam limpos antes. Ele mexeu apenas na ordem dos imports e nas linhas alteradas.
  - `BudgetController`, `SaleController`, `ServiceController` e `UserController` já tinham pendências antigas de estilo e **não** foram reformatados, para não misturar mudança cosmética com a correção.
- **ESLint/Prettier nos `.tsx` alterados:** Prettier ok; ESLint com a mesma contagem de antes (4 ocorrências já existentes em `serviceorders/index.tsx`).

## 4. Evidências de correção

- Orçamento com `tenant_id` da vítima fica na empresa do autor, e equipamento de outra empresa é recusado (`TenantIsolationTest`).
- **OS 15 de duas empresas:**
  - cada uma tem a própria assinatura e as próprias fotos;
  - a assinatura de uma não aparece na outra;
  - URL adulterada ou sem assinatura não entrega o arquivo;
  - o upload grava fora de `public/` (`OrderFilesIsolationTest`).
- O portal sem chave não contém senha, `manual_parts_cost` nem notas internas no JSON. O recibo não traz custo de peça (`PublicOrderPortalTest`).
- Totais R$ 0,01 / R$ 199,00 / R$ 1.000,00 para itens de R$ 200,00 retornam 422 com estoque intacto. O cancelamento com instância desatualizada devolve o estoque uma única vez (`SaleIntegrityTest`).
- Usuário inativo: login web recusado, API 403, sessão encerrada, tokens apagados e token antigo recusado. A 6ª tentativa errada retorna 429 (`InactiveUserAndLoginThrottleTest`).

## 5. Pendentes

- **Arquivos antigos ainda públicos:** `public/storage/orders` continua acessível até a migração e a retirada manual. É o risco residual mais importante (ver item 6).
- **App mobile (vetor-atendimento):** não está neste repositório. Se ele monta a URL `/storage/orders/{número}/{arquivo}` em vez de usar o novo campo `url`, **as fotos novas não aparecerão no app**. Precisa ser verificado e, se for o caso, atualizado. A assinatura já volta como URL assinada em `customer_signature_url`.
- **Fotos de agendamento** (`public/storage/schedules/{id}`): usam o ID global, então não colidem entre empresas, mas continuam públicas. Ficaram fora do escopo do SEC-02.
- **`Model::unguard()` global:** a remoção, com revisão do `$fillable` de todos os models, fica como etapa própria.
- **Expiração dos tokens Sanctum:** continua `null`. A revogação na inativação cobre o caso de desligamento, mas definir a expiração exige combinar o re-login com o app.
- **Desconto no PDV:** não existe. Se o negócio quiser desconto, ele precisa de regra, limite por perfil e trilha de auditoria; hoje é recusado.
- **Achados médios e baixos da auditoria não pedidos nesta etapa:**
  - M-04: tabela `services` sem migration;
  - M-05: agendamentos em UTC;
  - B-01: rotas registradas duas vezes;
  - B-02: `TenantSequence` O(n);
  - limpeza de obsoletos;
  - as 615 pendências antigas do PHPStan.
- **PHPStan:** os 615 erros da baseline (relações sem tipo genérico, por exemplo) devem ser reduzidos aos poucos.

## 6. Riscos de migração dos arquivos existentes

- **Persistência:** o disco privado fica em `storage/app/private/order-files` (`ORDER_FILES_ROOT`). Em produção, **precisa estar num volume persistente**, como o `FISCAL_STORAGE_ROOT`. Sem isso, os uploads novos se perdem a cada deploy.
- **Assinaturas antigas já sobrescritas não podem ser recuperadas.** Quando duas empresas colheram assinatura na OS de mesmo número, o arquivo da pasta antiga é de uma só delas e não há como saber qual. O comando lista esses casos como "ambíguos" e não os atribui. Até a revisão manual, essas OS aparecem sem assinatura, o que é preferível a mostrar a assinatura do cliente de outra empresa.
- **Fotos com nome repetido** (uploads da API no mesmo segundo) também ficam como ambíguas.
- **Remoção dos originais:** o comando não apaga nada. Retirar `public/storage/orders` do ar é uma ação destrutiva e manual, a ser autorizada depois da conferência do relatório (backup → `--execute` → conferência → retirada da pasta pública).
- **Antes da migração:** as fotos antigas não-ambíguas continuam visíveis pela URL assinada, lidas da pasta antiga.

## 7. Recomendações para homologação

1. **Configurar o volume:** definir `ORDER_FILES_ROOT` num volume persistente e fazer backup de `public/storage/orders`.
2. **Simular a migração:** rodar `php artisan vetoros:migrate-order-files` e revisar o relatório JSON, principalmente os "ambíguos".
3. **Migrar:** rodar com `--execute`, conferir as contagens e testar a OS e o portal de duas empresas com OS de mesmo número.
4. **Testar o app mobile:** login de usuário inativo (deve ser recusado), envio de foto, lista de fotos (campo `url`) e assinatura.
5. **Testar o PDV:** venda normal; alteração de preço de uma peça com a tela aberta (deve pedir para atualizar); cancelamento.
6. **Testar o portal:** com e sem chave de acesso, recibos (entrega, orçamento, checklist), comprovantes e fotos.
7. **Retirar a pasta pública:** só depois da conferência, retirar `public/storage/orders` do servidor web, com autorização.

## 8. Estado do Git

Branch local, sem commit, push ou deploy.

- **Arquivos rastreados alterados (47):**
  - os desta tarefa (itens 1 e 2);
  - os da EFI-TEST-01, preservados sem alteração: `EfiSettingsController`, `EfiPixClient`, `SaasPaymentService`, `efi.tsx`, `routes/admin-finance.php`;
  - `correio.md` e `executed.md`.
- **Não rastreados:**
  - os novos desta tarefa;
  - os da EFI-TEST-01 (controller, job, model, service, migration, modal e teste);
  - `docs/audits/`.
- Nenhuma migration nova nesta tarefa. A migration pendente continua sendo só a da EFI-TEST-01 (`2026_10_20_100000_create_efi_integration_tests_table.php`).

---

# VETOROS-AUDIT-01 — Resumo da auditoria geral

Data: 20/10/2026
SHA-256 de `correio.md`: `dfdde47abc8be889ded155af140f924da7c3d86d0ed9010113507eb48aae81c5`
Hash deste arquivo antes da atualização: `dd7447ac998dd6926c287d1d62fd0caa66025eb1dcf9749f2fcf648d0cf9f3e8`. O relatório da VETOROS-EFI-TEST-01 continua abaixo, porque ainda não foi commitado.
Relatório integral: `docs/audits/VETOROS-AUDIT-01.md`.

Diagnóstico apenas. Nenhum código de produção foi alterado. Não houve commit, push, deploy, migration nem chamada externa.

## Achados principais

- **Crítico C-01:** um usuário de uma empresa cria ou move Orçamentos para outra empresa.
  - Causa: `Model::unguard()` global + `$request->all()` em `BudgetController.php:188/230`; o `Tenantable` aceita o `tenant_id` enviado.
  - Reproduzido em teste.
- **Crítico C-02:** assinaturas e fotos de OS ficam em `public/storage/orders/{order_number}`, e o número da OS é sequencial por empresa.
  - A assinatura de uma empresa sobrescreve e aparece na OS de mesmo número de outra empresa.
  - Os arquivos são públicos e enumeráveis.
- **Alto A-01:** o portal público `/os/{token}` envia a senha do aparelho, custos e notas internas.
  - Com a chave de acesso desligada (padrão), qualquer pessoa com o link vê tudo; com ela ligada, ainda vazam custos e notas internas.
  - Reproduzido.
- **Alto A-02:** usuário "Inativo" continua entrando na web e na API. Reproduzido.
- **Alto A-03:** `/api/loginuser` sem limite de tentativas. Reproduzido.
- **Alto A-04:** a venda no PDV aceita qualquer total enviado pelo navegador. R$ 200,00 em peças foram vendidos por R$ 0,01. Reproduzido.
- **Médios:**
  - Orçamentos sem autorização por perfil;
  - tokens da API sem expiração;
  - corrida no cancelamento de venda (estoque devolvido em dobro);
  - módulo Serviços sem tabela `services`;
  - agendamentos em UTC (mensagens às 05h–08h30 de Brasília);
  - dependências npm vulneráveis.
- **Confirmados sem problema:** webhooks (Efí, Spedy, WAHA), credenciais criptografadas, isolamento no route binding, Modo Suporte, travas entre fiscal e financeiro, validação de upload. A Efí é usada só nas assinaturas SaaS.

## Verificações

- **Suíte:** 825 testes aprovados, 0 falhas. Os avisos vêm só da falta de `.env`.
- **Frontend:** `tsc` sem erros e build concluído.
- **Qualidade:**
  - Pint: 47 arquivos fora do padrão;
  - ESLint: 453 problemas;
  - Prettier: 2 arquivos.
- **Dependências:** `composer audit` limpo; `npm audit` com 25 vulnerabilidades (2 críticas).
- **Não executados:**
  - PHPStan (não instalado);
  - testes E2E e de navegador (sem `.env` nem banco);
  - subagentes ECC (criação recusada na sessão).

O plano de correção priorizado está na seção 9 do relatório integral.

---

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

# VETOROS-EQUIP-01 — Simplificação dos tipos de equipamento

Data: 23/10/2026

Removido o conceito de **Grupo** do cadastro de tipos de equipamento. Cadastro e edição agora usam só o nome, e o gráfico do dashboard continua configurável.

## Alterações

- **Backend:**
  - `EquipmentRequest`: regra `kind` e atributo `grupo` removidos; a única regra é `equipment` obrigatório.
  - `EquipmentController` (store/update): `kind` não é mais lido nem gravado.
  - `Equipment`: constantes `KIND_*` e `kinds()` removidas.
- **Frontend:**
  - `equipments/create-equipment.tsx` e `equipments/edit-equipment.tsx`: campo Grupo removido; ficam Nome, "Adicionar ao gráfico do dashboard", Cancelar e Salvar.
  - `equipments/index.tsx`: coluna Grupo removida da listagem.
  - `orders/equipment-types-modal.tsx` (cadastro rápido na OS): seletor de Grupo removido do formulário de criação, do modo de edição e da tabela.
  - `equipments/equipment-kinds.ts`: arquivo removido (sem outros usos).
  - `orders/customer-equipment-field.tsx`: tipo `kind` removido (não era usado).
- **Testes:** `tests/Feature/App/EquipmentControllerTest.php` reescrito com 4 casos:
  - cadastro só com o nome;
  - `kind` enviado pelo cliente é ignorado;
  - nome obrigatório;
  - edição altera só o nome e preserva o `kind` legado já gravado.

## Dados e banco

- A coluna `equipment.kind` (nullable, migration `2026_09_17_150000`) **foi mantida**. Nenhum dado foi apagado, nenhuma migration nova foi criada.
- Tipos antigos continuam com seu valor de `kind` no banco, sem exibição nem uso.
- Relacionamentos com OS, orçamentos, checklists e equipamentos do cliente não foram alterados.
- Remover a coluna exige migration própria e uma decisão separada; não foi feito aqui.

## Testes e verificações

- `php vendor/bin/phpunit` nos testes de equipamentos, equipamentos do cliente, OS/pedidos, eventos da OS e isolamento de tenant: **98 testes, 462 assertions, aprovados**.
- `tsc --noEmit` no projeto inteiro: **sem erros**.
- `npm run test:js`: **30/30 aprovados**.
- Prettier nos arquivos alterados: sem pendências.
- ESLint nos arquivos alterados: as 11 mensagens de erro e 3 avisos estão em linhas que não foram tocadas (ex.: `any` e `search`/`page` não usados em `create-equipment.tsx`, `edit-equipment.tsx` e `index.tsx`; avisos de `useEffect` no modal). A única linha adicionada é o grid do modal, sem novo problema.
- Pint: o controller acusa `fully_qualified_strict_types` e `ordered_imports`. O mesmo arquivo no `HEAD` acusa as mesmas regras; não foi corrigido para não ampliar o escopo.
- **Não executado:** testes de navegador (cadastro, edição e uso na OS pela tela). Não há `.env` nem banco configurados aqui para subir a aplicação. A cobertura automatizada cobre a API de cadastro/edição e a integração com OS.
- `npm ci` instalou `node_modules` a partir do lockfile. Ele também alterou `yarn.lock`; a alteração foi revertida.

## Pendências

- Testes de navegador do fluxo completo (criar "Notebook" só com o nome, usar na OS) devem ser feitos em homologação.
- A documentação gerada em `public/documentation` e `public-old/documentation` não foi revisada e pode ainda citar Grupo.
- Nenhum commit, push ou deploy foi feito.
