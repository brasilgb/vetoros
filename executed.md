# VETOR-RELEASE-01 — Preparação e publicação segura dos commits. Resultado: **PUBLICAÇÃO BLOQUEADA (nenhum push executado)**

- Data: 2026-10-09
- correio.md SHA-256: `ff08ccce3f35603f8210c35a62dafde824a5b0f94895fb8341e97ef3dd0c6ffc`
- **Não executados:** push, force push, deploy, migrations, alterações na VPS, mudança de visibilidade, rotação de credenciais e reescrita de histórico.
- Este relatório não contém valores secretos; os achados aparecem só por regra, arquivo e contagem.

## 1. Decisão
O correio só autoriza o push convencional "se todos os requisitos estiverem comprovadamente atendidos e não for necessária reescrita do histórico". Nenhuma das duas condições vale hoje:

| Requisito | Situação em 2026-10-09 | Atende? |
|---|---|---|
| Repositórios privados | `api.github.com/repos/brasilgb/vetoros` e `/infraabrasil` respondem **200 sem autenticação**: os dois continuam **públicos** | **Não** |
| Histórico sem segredos | gitleaks no histórico completo do VetorOS: **16 achados reais**, os mesmos do SEC-03 (11 `dotenv-sensitive-value`, 3 `mercadopago-credential`, 1 `laravel-app-key`, 1 `database-dump-file`). Para removê-los é preciso **reescrever o histórico e fazer force push** | **Não** |
| Credenciais expostas tratadas | Não há registro de rotação (Mercado Pago, webhook, n8n, Gemini, SMTP, `APP_KEY`). Daqui não dá para comprovar; depende do titular das contas | **Não comprovado** |
| Evidências preservadas (§1.4 do SEC-03) | Sem registro de execução | **Não comprovado** |

Por isso o push **não foi feito**. Publicar agora também colocaria no repositório público o código das proteções e da emissão fiscal enquanto o dump e as credenciais seguem acessíveis no mesmo repositório. O force push exige aprovação explícita; o plano está no §5.

## 2. Estado dos repositórios
- **VetorOS** (`brasilgb/vetoros`, `main`):
  - `git fetch` feito;
  - o local está **9 commits à frente e 0 atrás** de `origin/main`, então o push seria fast-forward, sem conflito.
- **Commits locais** (todos sem assinatura, `%G? = N`, como os anteriores):

| Commit | Correio | Arquivos |
|---|---|---|
| `4e100e3b` | estrutura do storage | 11 |
| `640645b1` | ROOT-FISCAL-02 | 32 |
| `27236802` | ROOT-FISCAL-02.1 | 7 |
| `1f9283a8` | SEC-03 (proteções) | 8 |
| `ac52519e` | FISCAL-05 | 21 |
| `dd6d5719` | FISCAL-05 (ajuste) | 5 |
| `ec9cf0e6` | FISCAL-05.3 | 26 |
| `b2bc07ed` | IMPORT-CSV-02 | 3 |
| `66a51abf` | IMPORT-CSV-02.1 | 3 |

- **Não commitados** (preservados, não tocados):
  - `.env.example` (só `APP_NAME=VetorOs`, de terceiros);
  - `composer.lock` (Laravel 12.69.3, de terceiros);
  - `correio.md` e `executed.md`;
  - `resources/js/pages/app/parts/import-parts-modal.tsx`: o botão "Fechar" após a importação, pedido direto, ainda aguarda confirmação para commit e **não faz parte desta publicação**.
- **Infra** (`brasilgb/infraabrasil`):
  - sincronizada com `origin/main` (`b038932`), 0 à frente e 0 atrás;
  - o ponteiro versionado do submódulo é `439edbf0`; localmente o submódulo está em `66a51abf` (com alterações não commitadas);
  - `executed.md` da infra está modificado e não versionado.
- Outros clones: `~/Projects/infra-vetor/vetoros` (outra frente) e a VPS não foram acessados.

## 3. Integridade dos commits
- `git fsck`: sem erros.
- `origin/main` é ancestral de `main`: não houve rebase nem amend nos commits locais.
- Commits fiscais e demais validados na execução de cada correio. A última validação no HEAD foi a do IMPORT-CSV-02.1: 613 testes passando, Pint, Prettier, tsc e build OK.

## 4. Varreduras de segredos
| Varredura | Resultado |
|---|---|
| `secret-guard tracked` | 1.122 arquivos, **nada encontrado** |
| `gitleaks git` só nos 9 commits locais (`origin/main..main`) | **0 achados** |
| `gitleaks git`, histórico completo | **16 achados** (os do SEC-03 §1.2; nada novo) |
| `gitleaks dir`, diretório de trabalho | 39 achados, **todos em arquivos não versionados e ignorados**: `.env` local (3) e `vendor/` (36, exemplos e fixtures de bibliotecas). Nenhum arquivo versionado. O SEC-03 mostrou 0 porque varreu uma cópia só com arquivos versionados |

Ferramenta: gitleaks 8.30.1 (o mesmo binário verificado no SEC-03), com o `.gitleaks.toml` do projeto e `--redact`.

## 5. O que falta para publicar (ordem; ⚠ exige aprovação explícita)
1. **Titular do GitHub:** tornar `vetoros` e `infraabrasil` privados e conferir que a API responde 404 sem autenticação. É reversível.
2. **Preservar as evidências cifradas** antes de qualquer reescrita (SEC-03 §1.4):
   - `git clone --mirror`;
   - `git bundle create --all`;
   - `sha256sum` registrado em ata;
   - cifrar com `age` ou `gpg --symmetric`;
   - guardar fora do GitHub e da VPS;
   - apagar o espelho em claro.
3. **Rotacionar as credenciais** pela tabela da Fase 3 do SEC-03. Para a `APP_KEY`, primeiro comparar por hash com a de produção; a recifragem precisa de correio próprio.
4. ⚠ **Reescrever o histórico** com `git filter-repo` (procedimento ensaiado no SEC-03 §Fase 4, árvore final idêntica).
   - **Riscos:**
     - todos os SHAs mudam, inclusive os 9 commits locais (por exemplo, `ec9cf0e6` ganha outro hash);
     - clones existentes ficam divergentes: este submódulo, `infra-vetor/vetoros` (com trabalho não commitado) e a VPS;
     - ponteiros antigos do submódulo na infra ficam órfãos;
     - o GitHub mantém cache de commits órfãos até um chamado ao Support.
   - **Rollback:** o bundle cifrado do passo 2 restaura o histórico original (`git push --force` a partir do bundle). Os clones locais continuam com o histórico antigo até serem realinhados.
   - Na ordem certa, os 9 commits locais entram no histórico reescrito antes do force push, e o push único publica tudo.
5. **Publicar** (push convencional ou o force push aprovado) e depois **atualizar o submódulo na infra**:
   ```bash
   git -C gateway/vetoros fetch origin && git -C gateway/vetoros checkout <main publicado>
   git add gateway/vetoros && git commit -m "Atualiza ponteiro do VetorOS (...)" && git push origin main
   ```
   - Hoje o ponteiro ficaria em `66a51abf`, que muda se houver reescrita.
   - Na VPS (fora deste correio): `git pull --ff-only`, `git submodule sync` e `git submodule update --init gateway/vetoros`. O deploy e a migration `2026_10_13_100000_add_contract_fiscal_schedule` precisam de correio próprio.
6. **Verificação posterior:** clone novo com gitleaks no histórico inteiro (só os 7 benignos), `git log --all -- backup/` vazio e os SHAs antigos inacessíveis no GitHub.

Alternativa sem reescrita, que precisaria de **decisão explícita do titular**: tornar os repositórios privados, rotacionar tudo e aceitar o histórico antigo no repositório privado. Nesse caso o push convencional dos 9 commits pode ser feito sem force push. Não foi escolhida aqui porque o correio exige que os requisitos estejam comprovados.

## 6. Pendências
- Ação do titular: visibilidade, rotação, preservação de evidências e decisão sobre a reescrita.
- O botão "Fechar" do modal de importação ainda não tem commit.
- Este `executed.md` não foi versionado (incidentes P0 abertos).

---

# VETOR-IMPORT-CSV-02.1 — Normalização automática do estoque mínimo

- Data: 2026-10-09
- correio.md SHA-256: `d9013c29f2558fd4536a50284816d6bb96c1a2df4073108ae2c378d1b2b5cf90`
- Commit local: `66a51abf` (sem push, sem deploy, sem migrations)

## 1. Regras implementadas (`app/Services/PartImportService.php`)
- Novo passo `adjust()`, no backend e **antes** de `validate()` e da persistência:
  - inteiro negativo em `estoque_minimo` (`-1`, ` -2 `, `-6`, `-1.000`) vira `0`;
  - gera um aviso, e não um erro: `estoque_minimo: valor -1 ajustado automaticamente para 0.`;
  - `-0` vira 0 sem aviso.
- Vazio → 0 e inteiros positivos preservados, como já fazia o 02.
- Espaços normais, NBSP, espaço estreito e tab são removidos antes da validação: `" 10 "` → 10.
- Continuam recusados: decimais (`1,5`, `-2,5`, sem arredondar), texto, notação científica e valores acima de 1.000.000.
- Só o estoque mínimo é ajustado. Estoque inicial negativo, custos e preços negativos continuam como erro (coberto por teste).
- O valor no aviso é seguro: depois do teste de inteiro, só pode ter sinal, dígitos e separadores.
- Prévia e importação são idênticas: a importação refaz a prévia com o mesmo arquivo. Um teste compara as linhas e o resumo das duas.

## 2. Relatório da importação
- Cada linha da resposta ganhou `warnings`. O resumo ganhou:
  - `rejected`: erros + duplicados;
  - `normalized`: quantidade de valores ajustados.
- Avisos e a contagem de normalizados valem só para as linhas que serão gravadas. Uma linha recusada mostra apenas o motivo da recusa.
- O vazio → 0 é o padrão da coluna e não conta como normalização.
- No fim, o modal mostra **Importação concluída** com:
  - Produtos importados;
  - Produtos rejeitados;
  - Valores normalizados.
- A prévia mostra os contadores e também os valores normalizados.
- Uma linha por aviso ou recusa, no formato `Linha 54 · Aviso · estoque_minimo: valor -1 ajustado automaticamente para 0.`
- O código do produto saiu da linha (o "Linha 54 · código 53" do 02 era confuso).

## 3. Testes (`tests/Feature/App/PartImportTest.php`, de 18 para 23)
- Linhas 54 a 62 do arquivo com:
  - `-1`, ` -2 `, `-6` e `-0`;
  - vazio, `5` e ` 10 `;
  - `1,5` e `abc`.
- Com isso são verificados os avisos, os status, o resumo (61 encontrados, 59 novos, 2 rejeitados, 3 normalizados) e os valores gravados.
- Só o estoque mínimo é ajustado; linha recusada não conta normalização.
- Prévia e importação devolvem as mesmas linhas e o mesmo resumo.
- Exportações do LibreOffice (UTF-8, aspas, NBSP antes do sinal) e do Excel (Windows-1252, CRLF) com valor negativo.
- Isolamento por tenant: o produto de mesmo código em outro tenant mantém o mínimo 7.
- Testes do 02 ajustados à nova regra:
  - o cenário da linha 48 passou a usar `-2,5` (decimal negativo continua erro);
  - o parser ganhou os casos `-1,5` e `-x`;
  - o resumo ganhou `rejected` e `normalized`.

## 4. Verificação
- Suíte completa: 613 testes passando (3722 asserções), em cópia descartável com o `composer.lock` versionado.
- Pint, Prettier, `tsc --noEmit` e `vite build`: OK.
- Não foram alterados `.env.example` e `composer.lock` (terceiros), nem migrations ou rootAdmin.

## 5. Pendências
- Push e deploy não foram feitos, conforme o correio.
- Este `executed.md` não foi versionado (incidentes P0 ainda abertos).
- O modal não foi conferido visualmente no navegador; a cobertura foi pelos testes e pelo build.

---

# Execução de `correio.md`: VETOR-FISCAL-05.3 (cobrança recorrente e emissão fiscal automática). Resultado: **IMPLEMENTADO E VALIDADO, COMMIT LOCAL `ec9cf0e6`, SEM PUSH, SEM DEPLOY**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado:** `06916079fd72476478724151eb26787615688b63bb0c50bb7c29d5cb0845eb75`. O anterior era o ajuste do VETOR-FISCAL-05 (`aa70911a…`), então este foi executado.
- **Repositório:** `infra-abrasil/gateway/vetoros`, `main`; base `dd6d5719`.
- **Não feito, conforme o correio:** push, force push, deploy, migrations em produção, rotação de credenciais, VPS, visibilidade, reescrita de histórico, RootAdmin, notas do SaaS.
- **Aprovação técnica não é liberação para produção:** os P0 do VETOR-SEC-03 continuam bloqueantes.

## 1. Auditoria do fluxo anterior (`ac52519e` + `dd6d5719`)

| Peça | Como estava |
|---|---|
| Cobrança (A) | `processBillingCycle` gerava a conta a receber no vencimento (`next_billing_date`), sem trava e sem competência |
| Emissão (B) | **Disparada pelo pagamento**: a quitação integral colocava `EmitMaintenanceContractInvoice` na fila; o job exigia cobrança paga. Emissão manual permitida antes da quitação (`dd6d5719`) |
| Recebimento (C) | `AccountReceivablePaymentService` com baixa manual (caixa), idempotência por `request_key` e evento integrado por referência, estorno auditável |
| Envio | `FiscalDocumentDeliveryService` enviava e-mail com PDF/XML **anexados** depois da autorização; registro em `fiscal_document_deliveries` |
| Arquivos | `NativeFiscalService::download()` lê a cópia do disco `fiscal`; rota autenticada `app.fiscal-documents.file`; não havia link para o cliente final |
| Agendador | `vetoros:process-maintenance-contracts`: expira, cobra, gera visitas; **sem tratamento de falha por contrato** |
| Reconciliação | `fiscal:sync-spedy` (10 min) e webhook, genéricos para qualquer documento |
| Links assinados | Só `signed` na verificação de e-mail (`routes/auth.php`); `URL::forceScheme` com `APP_URL` em HTTPS (`640645b1`) |

## 2. Problemas encontrados

1. **Gatilho errado:** a emissão dependia do pagamento, o oposto da regra central do correio.
2. **Competência deduzida do vencimento**, sem registro próprio nem configuração.
3. **Agendador frágil:** uma exceção em um contrato interrompia o processamento de todos; a geração da cobrança não tinha trava (agendadores simultâneos colidiriam no índice único).
4. **Anexos no e-mail** em vez de links protegidos; sem fatura com dados da cobrança.
5. **Alerta fiscal no estorno**, que passa a ser indevido quando a nota é pela prestação e não pelo pagamento.
6. **Botão Pagar sem ponto de extensão** para recebimento automático.

## 3. Decisões arquiteturais

- **Três processos sobre os mesmos registros**, sem tabela nova de cobrança nem de nota:
  - **A** (cobrança) grava `competence_start`/`competence_end` e `fiscal_scheduled_for` na `accounts_receivable`;
  - **B** (emissão) é o agendador (`queueScheduledInvoices`) mais o job, que reutiliza `NativeFiscalService::emit()`;
  - **C** (recebimento) é o `AccountReceivablePaymentService`, que **não** emite mais nada.
- **Data fiscal:** `MaintenanceContract::fiscalScheduleFor()` devolve o vencimento como padrão e é o único ponto a mudar se o município exigir outra data. **Competência:** `competenceFor()` usa o mês do vencimento (padrão) ou o mês anterior (pós-pago), configurável por contrato (`invoice_competence`).
- **Transição segura:** a emissão automática vale para ciclos programados a partir de `auto_issue_enabled_at`. Cobranças antigas (sem `fiscal_scheduled_for`) e ciclos anteriores à ativação nunca são emitidos sozinhos.
- **Uma emissão automática por ciclo:**
  - o agendador só considera cobranças **sem nenhum documento fiscal**;
  - a reserva por `fiscal_queued_at` é atômica (`UPDATE … WHERE`);
  - uma reserva sem documento é refeita após 1 hora (job perdido);
  - rejeição e falha seguem para reprocessamento manual, que reusa o `integrationId`.
- **Envio** reaproveita o serviço de entregas, com fatura de manutenção e **links assinados e temporários**; a dependência "envio automático exige emissão automática" foi mantida.
- **Ponto de extensão** `ReceivablePaymentChannel` (implementação atual `ManualOnlyPaymentChannel`); nenhum gateway foi implementado.
- **Estorno:** só reabre a cobrança e registra `has_authorized_invoice`, sem alerta de revisão.

## 4. Arquivos alterados (commit `ec9cf0e6`, 26 arquivos)

- **Novos:**
  - `SharedFiscalDocumentController` (link público assinado);
  - `MaintenanceInvoiceMail` com o template `emails/maintenance-invoice`;
  - `Support/Fiscal/FiscalDocumentLinks`;
  - `Services/Payments/ReceivablePaymentChannel` e `ManualOnlyPaymentChannel`;
  - a view `fiscal/shared-link-unavailable`;
  - a migration.
- **Removidos:** `FiscalDocumentMail` e `emails/fiscal-document` (substituídos pela fatura).
- **Alterados:**
  - `ProcessMaintenanceContracts`: isolamento de falhas e etapa fiscal;
  - `MaintenanceContractService`: cobrança com trava e competência, `queueScheduledInvoices`, `isAutomaticallyInvoiceable`, `auto_issue_enabled_at`;
  - `EmitMaintenanceContractInvoice`: regras da programação, sem exigir pagamento;
  - `AccountReceivablePaymentService`: não emite; canal automático; estorno sem alerta;
  - `FiscalDocumentDeliveryService`: fatura com links;
  - `SpedyPayloadBuilder`: competência gravada e ciclo na descrição;
  - `MaintenanceContractChargeController`: situações separadas e mensagens;
  - `MaintenanceContractController`: `invoice_competence`;
  - models `AccountReceivable` e `MaintenanceContract`;
  - `AppServiceProvider` (binding do canal), `config/services.php` (`fiscal_links.days`), `routes/web.php` (rota `fiscal-documents.shared`);
  - as telas `maintenance-contracts/index.tsx` e `charges.tsx`;
  - `MaintenanceContractInvoiceTest`, reescrito.

## 5. Migration

`2026_10_13_100000_add_contract_fiscal_schedule` (aditiva, reversível):
- `maintenance_contracts.invoice_competence` (padrão `due_month`) e `auto_issue_enabled_at`, preenchido com `updated_at` nos contratos que já tinham a opção ligada;
- `accounts_receivable.competence_start`, `competence_end`, `fiscal_scheduled_for` e `fiscal_queued_at`, mais o índice `(source_type, fiscal_scheduled_for)`.

**Validação no MySQL 8.4.11:**
- um contrato antigo com as duas opções ligadas as **preservou** e recebeu `auto_issue_enabled_at = updated_at`;
- a cobrança antiga ficou sem data fiscal, e o agendador **enfileirou 0**;
- o rollback remove as colunas e mantém o contrato; a reaplicação funciona.

**Cobranças históricas:** ficam sem data programada e nunca são emitidas automaticamente. Ciclos já emitidos por pagamento nos commits anteriores (só em desenvolvimento) já têm documento fiscal e são ignorados pelo agendador.

## 6. Novo fluxo recorrente (`vetoros:process-maintenance-contracts`)

1. Expira contratos vencidos.
2. Para cada contrato ativo com cobrança devida: `processBillingCycle` trava o contrato, reconfere o ciclo e gera a cobrança (competência + data fiscal + log `billed`). Falha de um contrato é registrada e o laço segue ("Falhas: N" na saída).
3. Gera as visitas.
4. `queueScheduledInvoices()`: cobranças de contrato não canceladas, com `fiscal_scheduled_for` até hoje, sem documento fiscal e com reserva livre (ou vencida há mais de 1 hora), de contrato ativo com emissão automática e ciclo a partir da ativação → reserva atômica → log `invoice_queued` → job na fila.
5. O job reconfere tudo, chama `emitForContractReceivable(..., requirePaid: false)` e registra o resultado.
6. A autorização chega pela resposta, pelo webhook ou por `fiscal:sync-spedy`; o envio automático é acionado.

**Reexecução, inclusive simultânea:** não duplica cobrança, emissão nem envio.

## 7. Comportamento da emissão fiscal

- **Emitente:** empresa do tenant, com a credencial Spedy do tenant (`fiscal_settings.api_token`); nunca a do RootAdmin.
- **Tomador:** cliente do contrato (CPF/CNPJ obrigatório).
- **Serviço:** "Contrato de manutenção nº N, ciclo K: descrição. Competência MM/AAAA (início a fim). Vencimento dd/mm/aaaa." O valor é o da cobrança; LC 116, ISS, tributação e município vêm das configurações.
- **Documento:** `integration_id` estável, vínculo com a cobrança (`documentable`), situação, número, código, protocolo, PDF e XML no disco fiscal.
- **Timeout ou 5xx:** a nota fica "Em processamento", com o mesmo `integrationId`, e a reconciliação conclui. Reexecuções não criam outra nota.
- **Rejeição:** "Rejeitada", sem nova tentativa automática; o reprocessamento manual atualiza o mesmo documento.
- **Contrato suspenso, cancelado ou expirado:** não emite automaticamente (`invoice_skipped` no histórico); a emissão manual continua disponível.
- **Emissão desabilitada:** a cobrança segue normal e só há emissão manual.
- **Pagamento antecipado:** não antecipa a emissão programada.

## 8. Envio das faturas

- Só depois da **autorização**; com a nota ainda em processamento, a tela mostra "Aguardando autorização" e o reenvio é recusado.
- **Assunto:** "Fatura de manutenção — Contrato nº N — MM/AAAA".
- **Corpo:**
  - saudação;
  - dados da cobrança (empresa, cliente, contrato, competência, valor, vencimento, situação do pagamento);
  - nota emitida e autorizada (número e código);
  - botões **Visualizar NFS-e (PDF)** e **Baixar XML da NFS-e**;
  - validade dos links;
  - aviso **"A emissão da nota fiscal não representa confirmação do pagamento."**
- Enviada pelo SMTP do tenant, aplicado antes do `Mail::to()`. Cada tentativa é registrada (Enviado/Falhou com motivo seguro), e reenviar não cria nota nem duplica o envio automático.

## 9. Segurança dos links PDF/XML

- **Formato:** `URL::temporarySignedRoute('fiscal-documents.shared', +N dias)`, com N = `FISCAL_SHARED_LINK_DAYS`, padrão 30. A assinatura cobre o id e o formato, não há caminho interno de armazenamento e nada é permanente ou previsível.
- **Público:** o cliente abre sem login.
- **Link expirado ou adulterado:** página "Documento fiscal indisponível", com HTTP 403 (expirado), 404 (inexistente) ou 503 (indisponível no momento).
- **Escopo:** só serve notas autorizadas ou canceladas **de cobrança de contrato**; documento de OS ou venda não vira link público, mesmo assinado.
- **Origem do arquivo:** a cópia do disco fiscal (no teste, nenhuma chamada à Spedy).
- **Cabeçalhos:** `Cache-Control: private, no-store` e `X-Robots-Tag: noindex`.
- **Acessos:** registrados no histórico do contrato (`invoice_file_accessed`, formato; sem IP).
- **Renovação:** quando o link vence, a empresa reenvia e novos links são gerados.
- **Isolamento:** a rota autenticada da equipe (`app.fiscal-documents.file`) continua isolada por tenant (404 para outro tenant).

## 10. Regras de pagamento manual e automático

- **Pagar** (ou **Parcial — Pagar saldo**) aparece só sem meio automático ativo. O bloqueio vale no backend (`register` manual recusa quando `ReceivablePaymentChannel` informa canal) e na tela ("Aguardando pagamento").
- Hoje o binding é `ManualOnlyPaymentChannel`: recebimento manual sempre operacional.
- **Confirmação:** valor, data e forma; `request_key` impede duplicidade; o valor entra no caixa aberto.
- **Integração futura:** `register(..., SOURCE_INTEGRATION, ref)` é idempotente, não usa o caixa e **não emite nota**. A autenticação do evento fica com o webhook do provedor.
- Pagamento parcial ou integral posterior à emissão só altera valores e situação; a nota é mantida.

## 11. Resultados dos testes

| Verificação | Resultado |
|---|---|
| `MaintenanceContractInvoiceTest` (reescrito) | **30 testes, 262 asserções, todos passando** |
| Suíte completa, SQLite | **601 passaram (3.605 asserções)**, 0 falhas |
| Suíte completa, **MySQL 8.4.11** (container descartável) | **601 passaram** |
| **Concorrência real no MySQL** contra uma Spedy falsa local (resposta atrasada em 1,5 s) | 4 processos emitindo o mesmo ciclo → **1 POST**, 1 nota autorizada, cobrança **em aberto**, 0 falhas. 3 agendadores simultâneos numa cobrança nova → **1 enfileiramento**. 3 gerações de cobrança simultâneas → **1 cobrança** |
| TypeScript, Prettier, Pint (novos e arquivos que já passavam) e `vite build` | OK |

**Cobertura dos 25 itens do §14:**
1–2 (desabilitada/habilitada); 3–4 (vencimento e emissão sem pagamento); 5 (envio após autorização e não antes); 6–7 (pagamento integral/parcial posterior); 8 (nota emitida antes, sem duplicar); 9 (reexecução); 10 (concorrência: teste e MySQL real); 11 (timeout); 12–13 (rejeição e reprocessamento); 14 (falha de SMTP); 15 (reenvio); 16 (outro tenant); 17 (link expirado, adulterado e sem assinatura); 18 (competência ≠ vencimento); 19 (suspenso); 20 (`request_key`); 21 (integração simulada); 22 (Pagar bloqueado no backend); 23 (migration no MySQL e cobranças históricas); 24 (estorno após emissão); 25 (`NativeFiscalEmissionTest`, admin fiscal e suíte completa sem regressão).

## 12. Evidências da validação visual

Feita com Chromium headless (Playwright do projeto), numa **cópia isolada** (porta 8123, SQLite próprio, build de produção, dados de demonstração). O ambiente local do usuário não foi tocado, e o servidor e o banco de demonstração foram apagados ao final.

- **Tela de cobranças (1440 px):**
  - colunas exatamente **Vencimento | Valor | Pagamento | NFS-e | Envio ao cliente**, sem coluna de ações;
  - Pagamento: "Pagar" (3), "Parcial — Pagar saldo" (1), "Pago" verde (1), indicador "Vencida";
  - NFS-e: "Programada para …", "Em processamento", "Autorizada" (nº, id, data, PDF/XML), "Rejeitada" (erro + Reprocessar);
  - Envio: "Aguardando autorização", "Falhou (automático)" com motivo, "Enviado (automático)" com Reenviar;
  - **0 erros de JavaScript**.
- **Pagar → "Confirmar pagamento efetuado":** valor pré-preenchido, data e hora, `Select` de forma, observações. Ao confirmar, "Pago" passou de 1 para 2 e "Pagar" de 3 para 2. A cobrança programada paga antecipadamente continuou "Programada" (sem emissão antecipada).
- **Formulário do contrato:** "Emitir NFS-e automaticamente a cada ciclo", "Enviar fatura e nota fiscal automaticamente ao cliente", "Competência faturada em cada ciclo" e o motivo de indisponibilidade. No ambiente de demonstração não havia credencial da plataforma Spedy, o que é esperado.
- **Celular (390 px):** a tabela rola dentro do cartão, sem rolagem horizontal da página.
- As capturas ficaram na scratchpad da sessão (não versionadas).

## 13. Riscos e pendências

1. **Obrigação legal e confirmação contábil:**
   - a data de emissão = vencimento é só o **padrão técnico**; cada tenant precisa confirmar com a contabilidade a regra do seu município (data, competência, prazo);
   - se for diferente, ajustar `fiscalScheduleFor()` ou expor uma opção (a arquitetura já separa as datas);
   - a competência "mês do vencimento" ou "mês anterior" também depende de orientação contábil.
2. **Spedy:** o contrato da API não informa prazo de autorização nem limite de requisições para lotes; o agendador emite um ciclo por job. Para muitos contratos no mesmo dia, avaliar `--limit` e a escala do worker.
3. **Links:** quem tiver o link pode abrir o documento até o vencimento (padrão 30 dias). A janela é configurável e não há revogação individual antes do prazo.
4. **E-mail:** usa o SMTP do tenant pelo `TenantMailConfig`; a centralização (VETOR-MAIL-01) continua pendente.
5. **Recebimento automático:** só o ponto de extensão existe; o webhook com validação fica para correio próprio.
6. **Pré-existentes:** Pint acusa `AccountReceivable.php` (não reformatado); não versionados: `.env.example` e `composer.lock` de terceiros, `correio.md` e este `executed.md`.
7. **P0 (VETOR-SEC-03):** continuam bloqueando a publicação.

## 14. Plano de homologação (ambiente de teste, Spedy sandbox)

1. Backup e `php artisan migrate` (só migrations aditivas). Conferir que os contratos existentes mantêm as opções.
2. Tenant de teste com NFS-e liberada, certificado, LC 116, ISS, tributação e confirmação contábil; SMTP ok; worker e agendador ativos; `APP_URL` em HTTPS.
3. Contrato de R$ 350,00, vencimento hoje, "Emitir NFS-e automaticamente a cada ciclo" e "Enviar fatura…" ligados.
4. Rodar `php artisan vetoros:process-maintenance-contracts`:
   - a cobrança aparece "Em aberto" e a NFS-e "Programada" → "Em processamento" → "Autorizada";
   - a fatura chega ao e-mail com valor, vencimento, situação "Em aberto" e os dois links;
   - os links abrem sem login.
5. Rodar o comando de novo: nada novo (cobrança, nota e e-mail).
6. Clicar em **Pagar** e confirmar: a cobrança vira "Pago", a NFS-e continua a mesma e não há novo POST na Spedy.
7. Abrir um link alterado ou vencido: página "Documento fiscal indisponível".
8. Estornar o pagamento: a cobrança volta a "Em aberto" e a nota continua válida.
9. Repetir com competência "mês anterior", com contrato suspenso (não emite) e com SMTP inválido (envio "Falhou"; "Reenviar" funciona sem nova nota).

## 15. Rollback

- **Código:** `git revert ec9cf0e6`. Volta o fluxo de `dd6d5719` (emissão disparada pelo pagamento); se isso não for desejado, reverter também `dd6d5719` e `ac52519e`.
- **Banco:** `php artisan migrate:rollback --step=1` remove só as colunas desta migration. Competência e datas programadas gravadas se perdem; cobranças, recebimentos e notas permanecem.
- **Operacional:** desligar "Emitir NFS-e automaticamente" nos contratos interrompe a emissão programada imediatamente, sem rollback.

## 16. Commit local

`ec9cf0e6 Separa cobrança, emissão fiscal programada e recebimento nos contratos (VETOR-FISCAL-05.3)`

Preservados: `dd6d5719`, `ac52519e`, `1f9283a8`, `27236802`, `640645b1`, `4e100e3b`. Nada enviado.

---

# Execução de `correio.md`: VETOR-FISCAL-05 — Recebimento manual e emissão NFS-e (ajuste definitivo da tabela). Resultado: **IMPLEMENTADO E VALIDADO, COMMIT LOCAL `dd6d5719`, SEM PUSH, SEM DEPLOY**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado:** `aa70911ae83bd2de6119d80eebb3aa8606958d134484444612d37ab1320e3364`. O anterior era a primeira versão do VETOR-FISCAL-05 (`c61a682b…`), então este foi executado.
- **Base:** `ac52519e` (implementação do VETOR-FISCAL-05). Nenhum gateway, Pix automático ou maquininha; nada no rootAdmin; sem migrations novas.

## 1. Auditoria (sobre a entrega anterior)

Os 12 itens de "Regras" já estavam atendidos pelo `ac52519e`, com três exceções, corrigidas aqui:

| Regra | Situação encontrada | Ajuste |
|---|---|---|
| 5. Sem confirmação duplicada | Uma baixa **integral** repetida era recusada ("já quitada"). Duas confirmações **parciais** iguais (duplo clique ou reenvio do formulário) passariam, porque o saldo ainda comportava | Cada abertura do diálogo gera uma chave (`request_key`, UUID). A baixa manual passa a usar a mesma idempotência por referência do evento integrado (`account_receivable_payments.external_reference`, única por tenant e origem). Repetir a chave devolve o recebimento já gravado, sem novo valor nem nova entrada no caixa |
| Cuidados: obrigações fiscais quando a nota é devida independentemente do recebimento | A emissão manual exigia a cobrança quitada | A ação manual "Emitir NFS-e" passa a funcionar **antes da quitação**, com confirmação explícita ("obrigação fiscal por competência; o pagamento continua em aberto"). A emissão **automática** continua só na quitação integral. Se a nota já foi emitida antes, a quitação não gera outra (o job vira no-op) |
| Cuidados: falha da Spedy não reverte o registro financeiro | Já garantido (a emissão roda em job separado, depois do commit da baixa), mas sem teste | Teste com recusa da Spedy (HTTP 422): pagamento continua quitado e não estornado; nota `failed`; `invoice_failed` no histórico |

Os demais itens seguem como na entrega anterior:
- baixa no financeiro existente (`accounts_receivable` + caixa);
- permissão financeira do contrato;
- disparo da NFS-e na quitação com contrato configurado, usando só a credencial e os dados fiscais da empresa do tenant;
- envio ao cliente após a autorização;
- histórico em `maintenance_contract_logs`, `account_receivable_payments` e `fiscal_document_deliveries`;
- reprocessamento com o mesmo `integrationId`;
- origem `integration` pronta para provedores futuros, sem gateway.

## 2. Ajuste definitivo da tabela (`maintenance-contracts/charges.tsx`)

- **Colunas:** Vencimento · Valor · **Pagamento** · NFS-e · Envio ao cliente. A coluna **Ações saiu**. Observação: o ícone `BadgeCheck` citado no correio não existia nessa tela; só aparece em `admin/features`, que não foi tocado.
- **Pagamento** (coluna única):
  - cobrança pendente ou parcial: botão **Pagar**; a parcial mostra também "Recebido R$ … · saldo R$ …";
  - quitada: indicador verde **Pago** (`Badge` + `CheckCircle2`) com a data;
  - cancelada: "Cancelada".
- **Pagar** abre **"Confirmar pagamento efetuado"** (`Dialog`) com valor (o saldo vem preenchido), data e hora, forma de pagamento (`Select` do shadcn/ui, que substitui o `<select>` nativo) e observações. O botão "Confirmar pagamento" fica desabilitado durante o envio.
- **Status fiscal separado:** a coluna NFS-e mostra a situação, o número, o id, a data e o erro, e reúne Emitir/Reprocessar, Consultar, PDF e XML. A coluna Envio ao cliente mostra a situação e tem Enviar/Reenviar.
- Os recebimentos aparecem abaixo de cada cobrança ("confirmado por …", estorno com motivo), com a ação **Estornar**.
- O aviso de revisão fiscal agora só aparece para nota autorizada em cobrança **com recebimento estornado**; nota emitida por competência antes do pagamento não gera alerta.

## 3. Arquivos alterados (commit `dd6d5719`, 5 arquivos)

- `AccountReceivablePaymentService`: idempotência por referência também na baixa manual (`findByReference`).
- `MaintenanceContractChargeController`:
  - `request_key` obrigatório (UUID) e mensagem para confirmação repetida;
  - emissão manual com `requirePaid: false`;
  - `can_emit` libera cobrança não cancelada.
- `NativeFiscalService::emitForContractReceivable(..., bool $requirePaid = true)`: recusa cobrança cancelada sempre e não quitada só no modo automático.
- `charges.tsx`: tabela e diálogo, conforme §2.
- `MaintenanceContractInvoiceTest`: helper com `request_key` e 3 testes novos.

Nenhuma migration nova. A estrutura usada é a do `ac52519e`.

## 4. Testes

| Verificação | Resultado |
|---|---|
| `MaintenanceContractInvoiceTest` | **23 passaram (163 asserções)**, 20 anteriores + 3 |
| Novos | mesma confirmação enviada duas vezes → 1 recebimento, 1 entrada no caixa e `request_key` inválido recusado; recusa da Spedy (422) não reverte o pagamento; emissão manual antes da quitação seguida da quitação → 1 nota, 1 POST à Spedy |
| Suíte completa, SQLite | **594 passaram (3.506 asserções)**, 0 falhas |
| TypeScript / Prettier / Pint / `vite build` | OK |
| MySQL | Não repetido nesta etapa: sem mudança de schema e sem SQL novo. A suíte completa e a concorrência real da baixa foram validadas no MySQL 8.4.11 no `ac52519e` |

## 5. Pendências e riscos

1. **Validação visual no navegador** ainda não foi feita (roteiro de homologação do `ac52519e`, passos 4 a 12; trocar "Receber" por **Pagar**).
2. A emissão manual antes da quitação depende de decisão do tenant e da contabilidade (competência). O sistema só pede confirmação explícita.
3. Pendências anteriores continuam: webhook de provedor futuro, SMTP central (VETOR-MAIL-01) e P0 do VETOR-SEC-03.
4. `.env.example` e `composer.lock` de terceiros, `correio.md` e este `executed.md` seguem fora dos commits (não publicar o `executed.md` enquanto o repositório for público).

## 6. Commits

- `dd6d5719 Ajusta cobranças do contrato: coluna Pagamento, confirmação única e emissão por competência (VETOR-FISCAL-05)`, local.
- Preservados: `ac52519e`, `1f9283a8`, `27236802`, `640645b1`, `4e100e3b`.
- Nada enviado.

---

# Execução de `correio.md`: VETOR-FISCAL-05 (NFS-e automática em contratos de manutenção). Resultado: **IMPLEMENTADO E VALIDADO, COMMIT LOCAL `ac52519e`, SEM PUSH, SEM DEPLOY**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado:** `c61a682b9b2521d3faf223a00ad2056e9a182afee374c6867f9d75e21113f899`. O anterior era o VETOR-SEC-03 (`7f727ba2…`), então este foi executado.
- **Repositório:** `infra-abrasil/gateway/vetoros` (submódulo `brasilgb/vetoros`, branch `main`); estrutura conferida antes de alterar.
- **Não feito, conforme o correio:** deploy, migrations em produção, mudanças no rootAdmin, regras de cobrança do SaaS, financeiro paralelo.

## 1. Resultado da auditoria

| Item | Encontrado |
|---|---|
| Contratos e cobranças recorrentes | `maintenance_contracts`. O comando agendado `vetoros:process-maintenance-contracts` chama `MaintenanceContractService::processBillingCycle`, que gera **uma conta a receber por ciclo** (`accounts_receivable`, `source_type = maintenance_contract`, `source_id = contrato`) e registra `billed` em `maintenance_contract_logs` |
| Integração com contas a receber | `accounts_receivable` guarda total, pago, saldo, status (`pending`/`partial`/`paid`/`cancelled`), forma e `last_paid_at`. Pagamentos de OS e vendas sincronizam essa tabela (`FinancialReceivableService`) |
| **Baixa de pagamentos** | **Não havia como baixar uma cobrança de contrato**: nenhuma rota, tela ou serviço. As cobranças apareciam só como "Próx. cobrança" e no saldo do cliente |
| Recebimento parcial, estorno, cancelamento | Previstos nos status da conta, mas sem histórico de recebimentos nem estorno para contratos |
| Caixa | `cash_session_movements` aceita entradas com `source_type`/`source_id` e cancelamento (`cancelled_at`), e as entradas compõem o saldo esperado e o fechamento. **Precedente:** o pagamento local do técnico (`ScheduleController`) já entra no caixa assim |
| Pagamentos integrados | O Mercado Pago atende só a assinatura do SaaS. **Não existe provedor integrado para cobranças de contrato** |
| Emissão Spedy | `NativeFiscalService::emit()` reserva o documento com `lockForUpdate` no registro de origem, recusa uma segunda nota em `processing`/`contingency`/`authorized`, reaproveita o `integrationId` em reenvio, trata falha transitória (mantém "em processamento") e grava PDF/XML no disco `fiscal` (`StoreFiscalDocumentFiles`). Webhook e `fiscal:sync-spedy` (a cada 10 min) são genéricos para qualquer documento |
| Configuração fiscal | `fiscal_settings` por tenant (credencial cifrada, liberações do RootAdmin, LC 116, ISS, tipo de tributação). O bloqueio já existia em `NativeFiscalService::blocker()` |
| Filas e agendamentos | Fila `database` com `vetoros-worker`; agendador `vetoros-scheduler` |
| Idempotência e auditoria | Reserva fiscal (acima), `maintenance_contract_logs`, `operational_audits` |
| Problemas encontrados no contrato | (a) `customer_id` validado com `exists:customers,id` **sem filtrar o tenant**: dava para apontar cliente de outra empresa. (b) Listagem com `FIELD()`, que só existe no MySQL, motivo pelo qual não havia testes de contratos. **Os dois foram corrigidos** |

**Decisões:**
1. A baixa usa a própria `accounts_receivable` mais um histórico de recebimentos e o caixa existente; não há financeiro paralelo.
2. A emissão reaproveita o `emit()`, com a cobrança como registro de origem (`documentable`).
3. O envio ao cliente é feito pelo VetorOS (e não pela Spedy, `sendEmailToCustomer = false`), para registrar entregas e falhas e permitir reenvio sem nova nota.

## 2. Arquivos e estruturas alterados (commit `ac52519e`, 21 arquivos)

- **Novos:**
  - `AccountReceivablePaymentService` (baixa manual ou integrada, estorno, recálculo, disparo da emissão);
  - `Fiscal/FiscalDocumentDeliveryService` (envio e registro);
  - jobs `EmitMaintenanceContractInvoice` e `SendFiscalDocumentToCustomer`;
  - models `AccountReceivablePayment` e `FiscalDocumentDelivery`;
  - `FiscalDocumentMail` com o template `emails/fiscal-document`;
  - `MaintenanceContractChargeController`;
  - a tela `maintenance-contracts/charges.tsx`;
  - a migration;
  - `MaintenanceContractInvoiceTest`.
- **Alterados:**
  - `NativeFiscalService`: `emitForContractReceivable`, `contractInvoiceBlocker` e registro no histórico do contrato e envio automático na autorização;
  - `SpedyPayloadBuilder`: `contractServiceInvoice`, e as checagens de NFS-e extraídas para `nfseSettingProblems`, usadas também pela OS;
  - `MaintenanceContractController`: opções, bloqueio fiscal, cliente por tenant, `CASE` no lugar de `FIELD()`;
  - `MaintenanceContractService`: as opções entram no log de alteração;
  - `FiscalEmissionController`: autorização do cancelamento de nota de cobrança pelo contrato;
  - models `AccountReceivable` (relações) e `MaintenanceContract` (campos e relação `receivables`);
  - `routes/app.php` (5 rotas);
  - a tela de contratos (opções e botão "Cobranças").

## 3. Migration criada

`2026_10_12_100000_add_maintenance_contract_invoice_automation` (aditiva):
- `maintenance_contracts.auto_issue_invoice` e `auto_send_invoice`, boolean, **default false**: contratos existentes ficam desligados;
- `account_receivable_payments`: valor, data, forma, origem (`manual`/`integration`), `external_reference` com chave única por tenant e origem, quem recebeu, movimento de caixa, estorno (data, autor, motivo);
- `fiscal_document_deliveries`: nota, e-mail, status, origem (`automatic`/`manual`), erro seguro e autor.

**Validação no MySQL 8.4.11 descartável:**
- `migrate:fresh` → rollback desta migration → um contrato pré-existente é inserido → `migrate`: o contrato fica com as duas opções em `0`;
- as tabelas são criadas, o rollback remove colunas e tabelas, e a reaplicação funciona.

**Rollback:** `php artisan migrate:rollback --step=1` (remove o histórico de recebimentos e entregas; fazer backup antes).

## 4. Integração com o fluxo financeiro

- **Baixa manual** (tela Cobranças → Receber):
  - exige a permissão financeira do contrato e o **caixa aberto**;
  - valor maior que zero e até o saldo; data não futura; forma (Pix, cartão, dinheiro, transferência, boleto);
  - cria o recebimento e uma **entrada no caixa** (`source_type = account_receivable_payment`), recalcula pago, saldo e situação e registra no histórico do contrato;
  - a trava `lockForUpdate` na cobrança serializa baixas simultâneas.
- **Parcial:** situação `partial`, sem emissão. A emissão só acontece quando a conta passa para `paid`.
- **Vencida:** vencer não gera recebimento nem emissão. O job recusa cobrança não quitada (`invoice_skipped`).
- **Integrado:** `register(..., SOURCE_INTEGRATION, externalReference)` é idempotente (consulta e chave única; a corrida devolve o mesmo registro) e não entra no caixa, porque o dinheiro vai ao banco. **A validação de autenticidade do evento cabe ao webhook, que ainda não existe** (§7).
- **Estorno** (com motivo):
  - marca o recebimento como estornado, sem apagar, e recalcula a situação;
  - se o caixa da entrada ainda está aberto, a entrada é cancelada; se já fechou, a devolução vira **saída** no caixa aberto atual;
  - se a cobrança tinha NFS-e autorizada, **a nota não é cancelada automaticamente**: a tela e o histórico pedem avaliação da contabilidade (`invoice_review_required`).

## 5. Integração com a Spedy

1. A quitação integral com o contrato marcado coloca `EmitMaintenanceContractInvoice` na fila (`afterCommit`).
2. O job recarrega a cobrança e o contrato (sem depender da sessão) e confere a opção e a quitação.
3. `NativeFiscalService::emitForContractReceivable()` usa as camadas de bloqueio da NFS-e do tenant e a credencial da **empresa do tenant** (`fiscal_settings.api_token`; nada de credencial no contrato).
4. Payload `contractServiceInvoice`:
   - emitente = empresa do tenant;
   - tomador = cliente do contrato (CPF/CNPJ obrigatório);
   - valor = total da cobrança;
   - descrição com número do contrato, competência (mês do vencimento) e vencimento;
   - LC 116, ISS, tributação e município das configurações;
   - `sendEmailToCustomer = false`.
5. Envio síncrono no job; o resultado é acompanhado por resposta, webhook e `fiscal:sync-spedy`.
6. `integration_id`, `provider_reference`, número, código de verificação, protocolo e PDF/XML são guardados no `fiscal_documents` existente.
7. **Sem duplicidade:**
   - reserva com trava e bloqueio de segunda nota;
   - novas execuções do job viram no-op silencioso;
   - reprocessamento de nota rejeitada reutiliza o mesmo `integrationId` e o mesmo documento.
8. **Falhas:**
   - dado fiscal inválido → `failed` sem chamar a Spedy;
   - rejeição → `rejected` com o motivo;
   - indisponibilidade (5xx/timeout) → "em processamento" até a reconciliação;
   - tudo registrado no histórico do contrato (`invoice_failed`, `invoice_rejected`, `invoice_authorized`…).
9. **Envio ao cliente:**
   - na primeira autorização, `SendFiscalDocumentToCustomer` envia se o contrato pedir e se ainda não houver envio automático bem-sucedido;
   - PDF e XML vão anexados, a partir da cópia guardada;
   - usa o SMTP do tenant, aplicado antes do `Mail::to()`;
   - cada tentativa é registrada (enviada, ou falha com motivo seguro: sem e-mail, SMTP não configurado, arquivo indisponível, falha de envio);
   - o reenvio manual nunca emite nota.

**Interface** (Contratos → Cobranças):
- situação financeira, valor recebido e saldo;
- situação da NFS-e, número, id do provedor, data de autorização e erro;
- envio ao cliente (status, destinatário, data, erro);
- ações: Receber, Emitir/Reprocessar NFS-e, Consultar status, PDF, XML, Enviar/Reenviar, Estornar;
- histórico financeiro e fiscal do contrato.
- Nas opções do contrato, o checkbox de emissão fica bloqueado, com o motivo, quando a NFS-e do tenant não está pronta.

## 6. Testes executados e resultados

| Verificação | Resultado |
|---|---|
| `MaintenanceContractInvoiceTest` | **20 testes, 142 asserções, todos passando** |
| Suíte completa, SQLite | **591 passaram (3.486 asserções)**: 571 anteriores + 20 |
| Suíte completa, **MySQL 8.4.11** (container descartável, rede interna) | **591 passaram** |
| **Concorrência real no MySQL** (processos PHP simultâneos) | 4 baixas de R$ 350 com eventos diferentes numa cobrança de R$ 350 → **1 recebimento**, 3 recusas "Esta cobrança já está quitada"; 4 processos com o mesmo evento → **1 recebimento**, os 4 devolvem o mesmo registro |
| TypeScript, build, Prettier e Pint dos arquivos novos ou que já passavam | OK |

**Cenários do §9 cobertos:**
- emissão desabilitada e habilitada;
- cobrança não paga e vencida (o comando de processamento não gera emissão);
- pagamento integral (payload, credencial do tenant, e-mail com 2 anexos, entrada no caixa e histórico);
- pagamento parcial;
- baixa manual autorizada (técnico negado, caixa fechado, valor acima do saldo, valor zero, data futura);
- evento de pagamento duplicado;
- jobs repetidos e concorrentes (1 nota, 1 chamada);
- rejeição e reprocessamento com o mesmo `integrationId`;
- indisponibilidade da Spedy;
- CPF/CNPJ ausente;
- falha de e-mail e reenvio sem nova nota (1 emissão e PDF/XML baixados uma vez);
- cliente sem e-mail;
- estorno com caixa aberto e com caixa fechado;
- isolamento entre tenants (tela e baixa negadas; só a credencial do tenant emitente é usada);
- configuração fiscal ausente ou incompleta bloqueando a ativação;
- envio automático exige emissão automática;
- cliente de outro tenant recusado;
- contratos novos começam desligados;
- tela de cobranças.
- Todos com Spedy, e-mail e fila simulados; nenhuma nota real foi emitida.

## 7. Pendências e riscos

1. **Obrigação fiscal por competência:** a automação usa o pagamento como gatilho, como pedido. Na maioria dos municípios, o fato gerador do ISS é a **prestação do serviço**, e a NFS-e pode ser devida na competência mesmo sem pagamento. Cada tenant precisa confirmar com a contabilidade se o contrato pode usar o gatilho financeiro; a tela e este relatório deixam claro que o vencimento não emite nota.
2. **Pagamento integrado:** não existe provedor para cobranças de contrato. O serviço já aceita eventos integrados com idempotência, mas o webhook (com validação de assinatura) precisa de correio próprio quando houver provedor (por exemplo, Pix/Mercado Pago do tenant).
3. **Estorno com nota emitida:** a decisão de cancelar a NFS-e é manual (Notas fiscais → Cancelar nota); o sistema apenas sinaliza.
4. **SMTP do tenant:** o envio usa o `TenantMailConfig` atual (configuração global aplicada antes do `Mail::to()`, na ordem correta). A centralização proposta no VETOR-MAIL-01 continua pendente.
5. **Concorrência da emissão:** coberta pela trava de linha e pela reserva (testadas em sequência e por repetição). A concorrência real entre processos foi validada no MySQL **para a baixa**; a chamada concorrente à Spedy não foi exercitada contra o provedor real.
6. **Envio automático:** é tentado uma vez (`tries = 1`), para não duplicar e-mails; falhas exigem reenvio manual.
7. **Validação visual:** a tela não foi validada no navegador nesta execução (ver homologação).
8. **Arquivos fora do escopo:**
   - pré-existente: o Pint acusa `AccountReceivable.php` e `routes/app.php` (não reformatados);
   - o Prettier reajustou 2 linhas existentes de `maintenance-contracts/index.tsx`.
9. **Não commitados** (como antes): `.env.example` e `composer.lock` de terceiros, `correio.md` e este `executed.md` (não publicar enquanto o repositório for público).

## 8. Commits produzidos

`ac52519e Emite NFS-e automática nos contratos de manutenção após a quitação (VETOR-FISCAL-05)` (local). Os commits anteriores foram preservados: `1f9283a8`, `27236802`, `640645b1`, `4e100e3b`. Nada foi enviado.

## 9. Procedimento de homologação (ambiente de teste, Spedy **sandbox/homologação**)

1. Backup do banco e `php artisan migrate` (só a migration aditiva desta entrega e as pendentes já conhecidas).
2. Tenant de teste com NFS-e liberada pelo RootAdmin, emitente cadastrado, certificado A1, LC 116, ISS, tipo de tributação e confirmação da contabilidade; SMTP configurado com o teste SMTP ok; worker e agendador rodando.
3. Cliente com CPF/CNPJ e e-mail próprio da equipe; contrato com valor baixo.
4. Conferir que o contrato novo começa com as duas opções desligadas. Ligar "Emitir…" e "Enviar…". Sem configuração fiscal, o checkbox deve aparecer bloqueado com o motivo.
5. Gerar a cobrança: `php artisan vetoros:process-maintenance-contracts` com `next_billing_date` de hoje. Ela aparece em Cobranças como "Em aberto". Deixar vencer **não** emite.
6. Abrir o caixa. Registrar um recebimento **parcial**: situação "Parcial", nenhuma nota.
7. Registrar o saldo: situação "Quitada"; no histórico, "NFS-e enviada para a fila". Depois de o worker rodar: "NFS-e autorizada", número e data. Conferir o e-mail com PDF e XML e o envio "Enviada (automático)".
8. Clicar em "Reenviar": novo envio registrado e **nenhuma nova nota** em Notas fiscais.
9. Estornar o recebimento com caixa aberto: saldo restaurado, entrada cancelada no caixa e aviso de nota autorizada para avaliação. Cancelar a nota em Notas fiscais, se a contabilidade orientar.
10. Repetir com SMTP inválido (envio "Falhou" com motivo; a nota segue autorizada) e com dados fiscais incompletos (a emissão falha com a mensagem no histórico; corrigir e usar "Reprocessar NFS-e").
11. Usuário técnico e usuário de outro tenant não conseguem abrir nem baixar as cobranças.
12. Fechar o caixa e conferir que o saldo esperado inclui os recebimentos de contrato.

---

# Execução de `correio.md`: VETOR-SEC-03 (contenção de incidentes e proteção dos repositórios). Resultado: **PREPARAÇÃO CONCLUÍDA; PROTEÇÕES IMPLEMENTADAS (COMMIT LOCAL `1f9283a8`); AÇÕES DESTRUTIVAS AGUARDAM APROVAÇÃO**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado:** `7f727ba224020d7facb1ab6bfe3d638989a082aabf8e8c81e458edc62f3e09dd`. O anterior era o VETOR-ROOT-FISCAL-02.1 (`33871aa8…`), então este foi executado.
- **Não feito, conforme o correio:** deploy, push, force push, migrations em produção, rotação de credenciais, alterações na VPS, mudança de visibilidade dos repositórios.
- **Nenhum valor secreto ou dado pessoal neste relatório:** credenciais aparecem só pelo nome da variável; o dump, só por contagens já registradas.
- **Ferramentas** (scratchpad local, fora dos repositórios): gitleaks **8.30.1** (checksum oficial conferido) e git-filter-repo **2.47.0** (wheel do PyPI com SHA-256 conferido). Os relatórios intermediários e os espelhos de ensaio foram apagados depois do uso.

## Fase 1 — Inventário e preservação

### 1.1 Repositórios e referências

| Repositório | Visibilidade | Referências remotas | Forks | Achados no histórico |
|---|---|---|---|---|
| `brasilgb/vetoros` | **pública** | só `main` (sem tags, sem `refs/pull/*`) | 0 | **16 reais**, todos alcançáveis por `main` |
| `brasilgb/infraabrasil` | **pública** | só `main` | 0 | **0** (25 commits; regras padrão e do projeto) |

Varredura com o `.gitleaks.toml` do projeto (§2): 306 commits do VetorOS, cerca de 225 MB. As regras padrão do gitleaks sozinhas **não** detectavam o token do Mercado Pago nem o dump; por isso foram criadas regras próprias.

### 1.2 Achados reais (VetorOS, branch `main`)

| Tipo | Arquivo | Introduzido | Removido da árvore | Commits de entrada |
|---|---|---|---|---|
| Dump de banco com dados pessoais (P0-1) | `backup/db_backup.sql` | 21–22/05 | 22/05 (`e12e843a`) | `8643db95` |
| `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD` | `.env.example` | 04/04 (`22a5a86a`) | 18/04 (`6247b5d3`) | 3 commits |
| `MP_ACCESS_TOKEN` e `MP_PUBLIC_KEY` (formato de produção), `MP_WEBHOOK_TOKEN` | `.env.example` | 28/04 (`e7e19dc0`) | 18/09 (`189f4b1d`) | 7 commits |
| **segundo `MP_ACCESS_TOKEN`** (o token foi trocado em 07/05; os dois ficaram expostos) | `.env.example` | 07/05 (`6668d596`) | 18/09 | — |
| `GEMINI_API_KEY`, **`N8N_WEBHOOK_SECRET`**, **`VETOROS_N8N_TOKEN`** | `.env.example` | 13/08 (`157fa84c`, `5c52c0cc`) | 28/08 (`4ae434dc`) | 3 commits |

Itens **novos** em relação ao inventário anterior: `N8N_WEBHOOK_SECRET`, `VETOROS_N8N_TOKEN` e o segundo `MP_ACCESS_TOKEN`.

**Revisados e sem dado sensível** (ficam no `.gitleaksignore`):
- `database/firebird/schema.sql`: só estrutura; 47 `CREATE TABLE`, nenhum `INSERT`; "password" aparece apenas como nome de coluna.
- `mysql-init/init.sql`: só `CREATE DATABASE`.
- 9 senhas fictícias em testes, cobertas por exceção restrita à regra genérica.

### 1.3 Situação local

- **VetorOS** (`gateway/vetoros`):
  - `main` está **4 commits à frente** de `origin/main`, todos preservados e não enviados: `4e100e3b` (storage), `640645b1` (ROOT-FISCAL-02), `27236802` (ROOT-FISCAL-02.1) e `1f9283a8` (este);
  - não commitados: `.env.example` e `composer.lock` (de terceiros), `correio.md` e `executed.md`.
- **Infra:** sincronizada com `origin/main` (`b038932`, ponteiro do submódulo em `439edbf0`, já publicado).
- **Clones conhecidos do VetorOS:**
  - `~/Projects/Laravel/infra-abrasil/gateway/vetoros` (este);
  - `~/Projects/infra-vetor/vetoros` (em `d589a6f5`, **com alterações não commitadas de outra frente**);
  - a VPS (`/opt/infra-abrasil/gateway/vetoros`).
  - `~/Projects/Lazarus/vetoros` é outro repositório (`vetoros-lazarus`) e não foi varrido.
- Nenhuma nova cópia dos dados expostos foi criada fora da scratchpad, e os espelhos de ensaio foram apagados.

### 1.4 Procedimento de preservação de evidências (preparado, não executado)

1. Em máquina de confiança, **antes** de tornar o repositório privado ou reescrever: `git clone --mirror https://github.com/brasilgb/vetoros.git vetoros-evidencia.git`.
2. `git -C vetoros-evidencia.git bundle create ../vetoros-evidencia.bundle --all`; gerar `sha256sum` do bundle e registrar data e hora, autor e hash numa ata.
3. Cifrar o bundle (por exemplo, `age` ou `gpg --symmetric`) e guardar em armazenamento **privado e restrito** (fora do GitHub, fora da VPS de produção, com acesso só do responsável e do jurídico). Apagar o espelho em claro.
4. Registrar na ata: commits e datas da tabela 1.2, período em que o repositório esteve público (criado em 04/04/2026), contagem de titulares do dump e lista de credenciais.
5. **Não** anexar o dump a tickets, e-mails ou relatórios; citar apenas o hash do bundle.

## Fase 2 — Prevenção (implementado, commit local `1f9283a8`)

| Proteção | Arquivo | Detalhe |
|---|---|---|
| `.gitignore` | `.gitignore` | **Defeito corrigido:** a linha `.env.productionbackup/*.sql` juntava duas regras, e **`.env.production` não era ignorado**. Agora são ignorados `.env.production`, `.env.*.local`, `backup/`, `backups/`, `*.sql`, `*.sql.gz`, `*.dump`, `*.bak`, `*.sqlite`, `*.sqlite3`, `*.pfx`, `*.p12` e `*.pem`. Nenhum arquivo versionado passou a ser ignorado (`git ls-files -ci` vazio) |
| Varredura de segredos | `.gitleaks.toml` | Regras padrão do gitleaks + `mercadopago-credential`, `laravel-app-key`, `dotenv-sensitive-value` (variável sensível preenchida em `.env*`), `database-dump-file` e `certificate-or-private-key-file`. Em `tests/`, só a regra genérica é dispensada |
| Exceções revisadas | `.gitleaksignore` | Só os 7 achados históricos benignos (schema Firebird e `init.sql`). Os achados reais **não** foram ignorados de propósito: o histórico continua acusando 16 até a reescrita |
| Verificação do `.env.example` e bloqueio de arquivos | `scripts/security/SecretGuard.php` e `secret-guard.php` | PHP puro: arquivos proibidos, variável sensível preenchida no `.env.example` e formatos de credencial (Mercado Pago, `APP_KEY`, chave privada, AWS, Google, GitHub). Mensagens só com arquivo, linha e nome, **sem valor**. Modos `staged`, `tracked` e `files` |
| Bloqueio de commits | `scripts/git-hooks/pre-commit` | Roda o secret-guard e, se instalado, `gitleaks git --staged`. **Ativação manual por clone:** `git config core.hooksPath scripts/git-hooks`. Não foi ativado automaticamente |
| CI | `.github/workflows/ci.yml`, job `secrets` | secret-guard na árvore inteira e gitleaks 8.30.1 (versão e SHA-256 fixados) no intervalo do push ou PR. Não varre o histórico inteiro porque ele ainda contém os achados antigos |
| Testes | `tests/Unit/Security/SecretGuardTest.php` | 8 testes, 70 asserções: caminhos proibidos, `.env.example` preenchido, formatos de credencial, ausência do valor nas mensagens, `.env.example` do repositório limpo, `.gitignore` num repositório temporário e **hook bloqueando commit real** (dump e `.env.example` preenchido) |

Recomendação adicional, de configuração do GitHub e não de código: ativar **Secret scanning** e **Push protection** no repositório.

## Fase 3 — Inventário de rotação (preparado; nenhuma credencial alterada)

| Credencial | Serviço responsável | Possível uso atual | Dependências | Substituição | Verificação pós-rotação | Recuperação |
|---|---|---|---|---|---|---|
| `MP_ACCESS_TOKEN` (2 versões) e `MP_PUBLIC_KEY` | Mercado Pago (conta da ABrasil) | `config/services.php` → `MercadoPagoService`, `PaymentController` (Pix da assinatura), `WebhookController`. Valor vem do `.env` da infra | **VetorOS e VetorPet** (o compose repassa `MP_ACCESS_TOKEN` aos dois) | Gerar novas credenciais de produção no painel; atualizar `MP_ACCESS_TOKEN`/`MP_PUBLIC_KEY` no `.env` da infra; recriar `vetoros` e `vetorpet`; **revogar** as antigas depois de validar | Gerar um Pix de assinatura de teste (valor mínimo) e confirmar o webhook; conferir no painel pagamentos, estornos e chargebacks de 28/04 até hoje | Manter as credenciais antigas ativas até a validação das novas; em falha, restaurar o `.env` anterior e recriar os serviços |
| `MP_WEBHOOK_TOKEN` | VetorOS (validação do webhook) e Mercado Pago (URL de notificação) | `services.mercadopago.webhook_token` | Configuração da notificação no Mercado Pago | Gerar valor aleatório novo; atualizar o `.env` da infra e a URL ou segredo no painel | Notificação de teste aceita e notificação com token antigo recusada | Restaurar o valor anterior |
| `GEMINI_API_KEY` | Google AI Studio | **Não usada** no código atual (nem em `config/`) | Desconhecida; pode estar em outro sistema | Revogar a chave e criar outra só se algum sistema precisar | Painel de consumo sem uso inesperado | Nova chave |
| `N8N_WEBHOOK_SECRET` | n8n da infra | Usada pelo VetorOS só entre 13/08 e 28/08 (`services.n8n.webhook_secret`, removida) | Workflows do n8n que ainda validem esse segredo | Listar workflows que usam o valor; trocar no n8n | Workflows executando; chamadas com o valor antigo recusadas | Restaurar no n8n |
| `VETOROS_N8N_TOKEN` | n8n / integrações | **Nunca usada** no código do VetorOS | Possivelmente workflows do n8n ou outros sistemas da ABrasil | Inventariar no n8n; revogar ou trocar | Idem | Idem |
| `DB_PASSWORD` e `MAIL_PASSWORD` de abril | Ambiente anterior (HostGator) | Provavelmente sem uso (produção atual usa MySQL da infra e `VETOROS_DB_PASSWORD`) | Conta de banco e SMTP do provedor antigo | Trocar se ainda existirem; desativar contas sem uso | Login antigo recusado | — |
| SMTP de tenants (`others.mail_password`; 1 senha no dump) | Provedor de e-mail de cada tenant | E-mails de OS e cobrança | Tenant afetado | O tenant troca a senha no provedor e regrava em Sistema e módulos | Teste SMTP da tela | Senha anterior, se o tenant ainda a tiver |
| Senhas de usuários (9 hashes no dump) e sessões | VetorOS | Login | Usuários do dump | Forçar redefinição de senha e invalidar sessões e tokens Sanctum desses usuários | Login com senha antiga recusado | Fluxo de recuperação de senha |
| **`APP_KEY`** (publicada em abril) | VetorOS | Ver abaixo | Ver abaixo | **Não substituir antes da recifragem validada** | Ver abaixo | Ver abaixo |

**`APP_KEY` em detalhe**
- **Comparação:** primeiro, comparar **por hash** a `VETOROS_APP_KEY` de produção com a chave publicada, sem imprimir (`printf %s "$VALOR" | sha256sum` nos dois lados). Se forem diferentes, a rotação deixa de ser urgente.
- **Dados persistidos que dependem dela:**
  - `orders.public_access_key` (`Crypt`);
  - `others.mail_password` (`Crypt`);
  - `spedy_platform_settings.owner_api_key` e `webhook_secret`;
  - `admin_fiscal_settings.api_token` e `webhook_secret`;
  - `fiscal_settings.api_token`, `webhook_secret` e `nfce_csc` (casts `encrypted`).
  - `orders.public_access_key_hash` é bcrypt e **não** depende da chave.
- **Também dependem dela:**
  - sessões e cookies (todos os usuários saem);
  - o link assinado de verificação de e-mail (`routes/auth.php`);
  - tokens de redefinição de senha pendentes.
- **Lacuna:** `security:audit-app-key` cobre `orders`, `others` e `spedy_platform_settings`, mas **não** `fiscal_settings` nem `admin_fiscal_settings`. Ampliar antes da rotação.
- **Procedimento proposto:**
  1. backup verificado;
  2. auditoria ampliada com a chave atual (100% legível);
  3. em homologação, nova chave em `APP_KEY` e a antiga em `APP_PREVIOUS_KEYS` (o Laravel 12 decifra com as anteriores e cifra com a nova, sem quebrar leituras);
  4. comando de recifragem idempotente, com `--dry-run` e transação por tabela, regravando cada valor com a chave nova;
  5. auditoria com **só** a chave nova (100% legível);
  6. repetir em produção na mesma ordem e remover `APP_PREVIOUS_KEYS`.
  - O comando de recifragem **ainda não existe** e deve ser implementado e validado em correio próprio.
- **Recuperação:** voltar a chave antiga (os dados continuam legíveis enquanto ela estiver em `APP_PREVIOUS_KEYS`) ou restaurar o backup.

## Fase 4 — Histórico Git (preparado e **ensaiado em espelho descartável**; nada executado no remoto)

1. **Tornar privado** (manual, reversível): GitHub → `brasilgb/vetoros` → Settings → General → Danger Zone → Change visibility → Private. Repetir em `brasilgb/infraabrasil`, que é pública e referencia o submódulo. Conferir depois: `curl -s https://api.github.com/repos/brasilgb/vetoros` deve responder 404 sem autenticação. O CI continua funcionando (Actions em repositório privado usa minutos da conta).
2. **Preservar evidências:** §1.4, **antes** do passo 3.
3. **Reescrever** (destrutivo; exige aprovação):
   ```bash
   git clone --mirror https://github.com/brasilgb/vetoros.git vetoros-limpo.git && cd vetoros-limpo.git
   git filter-repo --force --path backup/ --invert-paths --replace-text ../replacements.txt
   git for-each-ref --format='delete %(refname)' refs/replace | git update-ref --stdin
   git reflog expire --expire=now --all && git gc --prune=now --aggressive
   gitleaks git --config <.gitleaks.toml> --redact --log-opts=--all .   # esperado: só os 7 benignos (schema/init)
   git push --force origin 'refs/heads/*:refs/heads/*'                  # NÃO usar --mirror
   ```
   - **`replacements.txt`** (sem nenhum valor secreto; SHA-256 `defd62c3ca0c746d…`):
     - esvazia `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY`, `MP_WEBHOOK_TOKEN`, `APP_KEY`, `DB_PASSWORD`, `MAIL_PASSWORD`, `GEMINI_API_KEY`, `N8N_WEBHOOK_SECRET` e `VETOROS_N8N_TOKEN` em linhas `VAR=valor`, preservando placeholders (`...`, `${...}`, `<...>`);
     - como rede de segurança, troca qualquer `APP_USR-…` ou `base64:` de 44 caracteres por `REMOVIDO-VETOR-SEC-03`.
   - **Resultado do ensaio**, num espelho local do `main` atual (330 commits):
     - 329 commits; o commit que só apagava o dump ficou vazio e saiu;
     - **árvore final idêntica** à atual;
     - autor, data e mensagem preservados nos commits 02, 02.1 e SEC-03 (`640645b1 → 16cda4b3`, `27236802 → 6c201658`, `1f9283a8 → b73e05c9`; os hashes mudam);
     - **0 commits** com `backup/`, nenhuma variável sensível preenchida em nenhuma versão do `.env.example` e gitleaks só com os 7 benignos;
     - depois de `reflog expire` e `gc --prune=now`, o **blob do dump e o commit `8643db95` ficam ausentes**; o pacote cai de 236 MB para 125 MB. **Sem o `gc`, o blob continuou no espelho:** esse passo é obrigatório.
   - Depois do push, regenerar o `.gitleaksignore` (as impressões digitais dos 7 benignos mudam com os novos hashes).
4. **Clones e colaboradores afetados:**
   - forks: 0 (API pública);
   - colaboradores: só o titular é visível sem autenticação; conferir em Settings → Collaborators.
   - **Clones que precisam ser refeitos ou realinhados** (o histórico antigo continua neles):
     - `~/Projects/infra-vetor/vetoros`, com trabalho não commitado: `git stash`, `git fetch`, `git reset --hard origin/main` e `git stash pop`, revisando conflitos; o commit base `d589a6f5` vira `3d148ec5`;
     - este submódulo;
     - a VPS;
     - qualquer outra máquina ou integração (n8n, CI de terceiros) que tenha clonado.
   - Depois de realinhar, rodar `git reflog expire --expire=now --all && git gc --prune=now` em cada clone para tirar o histórico antigo do disco.
5. **Submódulos:** a infra aponta `gateway/vetoros` para `439edbf0`, que vira `f6729237` (mapa completo `commit-map` gerado no ensaio; é determinístico para o mesmo histórico).
   - Depois do push reescrito: `git -C gateway/vetoros fetch && git -C gateway/vetoros checkout <novo main>`, `git add gateway/vetoros` e commit na infra.
   - Ponteiros **antigos** no histórico da infra ficam órfãos (commits anteriores da infra deixam de fazer checkout do submódulo). Aceitável e documentado; não reescrever a infra, que não tem segredos.
   - Na VPS: `git pull --ff-only`, `git submodule sync` e `git submodule update --init --force gateway/vetoros`.
6. **Verificação posterior:**
   - `git clone` novo do remoto mais `gitleaks git --log-opts=--all` (só os 7 benignos, ou zero com o `.gitleaksignore` regenerado);
   - `git log --all -- backup/` vazio;
   - `git ls-remote` só com `refs/heads/main`;
   - os SHAs antigos (`8643db95`, `e7e19dc0`) **não** devem abrir em `https://github.com/brasilgb/vetoros/commit/<sha>`. O GitHub mantém commits órfãos em cache: abrir chamado no **GitHub Support** ("Removing sensitive data from a repository") pedindo a remoção das referências em cache e o GC.
   - A reescrita **não substitui** a rotação da Fase 3: o conteúdo já foi público.

## Fase 5 — Validação

| Verificação | Resultado |
|---|---|
| Testes das proteções (`tests/Unit/Security`) | **8 passaram (70 asserções)** |
| Suíte completa, SQLite (regressão) | **571 passaram (3.343 asserções), 0 falhas** (563 anteriores + 8) |
| `secret-guard tracked` na árvore | 1.098 arquivos, nada encontrado |
| `gitleaks dir` na árvore atual | **0 achados** |
| `gitleaks git` no histórico atual | **16 achados reais** (os da §1.2), nenhum falso positivo |
| Simulação do job `secrets` nos 3 commits não enviados | 0 achados |
| YAML do CI | válido (jobs `secrets` e `test`) |
| Pint nos arquivos novos | OK |
| Commits 02 e 02.1 | preservados (`640645b1`, `27236802`), sem rebase nem amend |

## Plano operacional (ordem de execução; ⚠ = destrutivo, exige aprovação explícita)

| # | Ação | Quem | Reversível |
|---|---|---|---|
| 1 | Tornar `vetoros` e `infraabrasil` **privados** | Titular do GitHub | Sim |
| 2 | Preservar evidências (§1.4) e abrir a ata do incidente | Responsável + jurídico | — |
| 3 | Avaliação LGPD (cerca de 7.094 titulares, cerca de 830 CPFs, exposição de 22/05 até a privatização) e decisão sobre comunicação à ANPD e aos titulares | Encarregado/jurídico | — |
| 4 | Rotação do Mercado Pago (VetorOS + VetorPet), webhook, n8n e Gemini (Fase 3) | Responsável técnico | Sim, enquanto as antigas não forem revogadas |
| 5 | Redefinição de senha e invalidação de sessões dos usuários do dump; aviso ao tenant do SMTP | Suporte | — |
| 6 | Comparar a `APP_KEY` por hash; se igual, correio próprio para implementar e validar a recifragem antes de trocar | Responsável técnico | Sim (`APP_PREVIOUS_KEYS`) |
| 7 | ⚠ Reescrita do histórico (Fase 4.3) e force push **só de `refs/heads/*`** | Responsável técnico, com aprovação | **Não** (evidência no bundle) |
| 8 | ⚠ Realinhar os clones (4.4) e atualizar o submódulo na infra (4.5) | Responsável técnico | Parcial |
| 9 | Chamado no GitHub Support para purgar caches | Titular | — |
| 10 | Ativar o hook (`git config core.hooksPath scripts/git-hooks`) nos clones de trabalho; ativar Secret scanning e Push protection no GitHub | Equipe | Sim |
| 11 | Push dos commits locais (`4e100e3b`, `640645b1`, `27236802`, `1f9283a8`), **depois** da reescrita (eles entram com os novos hashes) | Responsável técnico | — |

## Pendências

1. Aprovação e execução manual dos passos 1 a 11.
2. Ampliar `security:audit-app-key` e criar o comando de recifragem (correio próprio), antes de qualquer troca de `APP_KEY`.
3. Varrer os demais repositórios da ABrasil (`vetorpet`, `desgarrados`, `abrasilsistemas`, `vetoros-lazarus`) com o mesmo `.gitleaks.toml`.
4. `.env.example` e `composer.lock` de terceiros continuam não commitados e intocados.
5. **Não commitar este `executed.md` enquanto o repositório for público.**

---

# Execução de `correio.md`: VETOR-ROOT-FISCAL-02.1 (homologação e preparação para implantação). Resultado: **APROVADO NOS CRITÉRIOS TÉCNICOS; IMPLANTAÇÃO AGUARDA A LIBERAÇÃO DOS P0 PELO RESPONSÁVEL**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado:** `33871aa8ec0feadc9305cce48fbb83b4943912eead5cab7bd109410fe9f6be95`. O anterior era o VETOR-ROOT-FISCAL-02 (`b45ec3d4…`), então este foi executado.
- **Base:** `640645b1`. **Commit desta execução:** `27236802 Valida nomes fiscais pelos limites comprovados e estabiliza testes JSON (VETOR-ROOT-FISCAL-02.1)` (7 arquivos), commit local.
- **Não feito, conforme o correio:** push, deploy, migrations em produção, rotação de credenciais, alteração da `APP_KEY`.

## Condições de liberação

| Condição | Situação |
|---|---|
| Suíte aprovada nos dois bancos | **OK**: 563/563 no SQLite e 563/563 no MySQL 8.4.11 |
| Nenhuma regressão fiscal | **OK**: suíte fiscal completa verde; os únicos comportamentos novos são bloqueios explícitos acima de limites comprovados (§1) |
| Política de nomes fiscais documentada | **OK** (§1) |
| Plano de implantação revisado | **OK** (§6) |
| Incidentes P0 tratados e liberados pelo responsável | **PENDENTE.** Depende de ação manual (plano na execução VETOR-ROOT-FISCAL-02). **Não implantar antes disso** |

## 1. Política de tratamento dos nomes fiscais (item 1 e 2)

**Fonte do contrato:** OpenAPI oficial da Spedy (`https://api.spedy.com.br/swagger/v1/swagger.json`, baixado em 09/10/2026, 565 KB, SHA-256 `8265bc61b690a4eb…`) e `https://api.spedy.com.br/llms.txt`.

| Campo | Contrato Spedy | Outra restrição comprovada | Antes | Agora |
|---|---|---|---|---|
| Emitente `legalName` (`CompanyEditingDto`, POST/PUT `/v1/companies`) | **maxLength 80** | — | bloqueio > 80 (02) | bloqueio > 80, tenant e ABrasil |
| Emitente `name` (nome curto ou fantasia) | **maxLength 80** | — | **truncado em 80** | **bloqueio > 80**, tenant e ABrasil |
| Emitente `email` | **maxLength 80** | — | enviado sem checagem (rejeição da Spedy) | **bloqueio > 80**, tenant e ABrasil |
| NF-e `receiver.name` (`CreateProductInvoiceDto`) | sem maxLength | layout da NF-e (SEFAZ): `dest/xNome` 2–60 | **truncado em 60** | **bloqueio > 60** com orientação para abreviar no cadastro do cliente |
| NF-e/NFC-e `items[].description` | sem maxLength | layout NF-e/NFC-e: `prod/xProd` 1–120 | **truncado em 120** | **bloqueio > 120** |
| NFS-e `receiver.name` (`CreateServiceInvoiceDto`), OS do tenant e SaaS | sem maxLength | nenhuma comprovada no contrato | **truncado em 60** | **enviado inteiro**; uma recusa do município volta como rejeição explícita da Spedy, visível em Notas fiscais |
| Endereço (`street`, `district`, `number`, `additionalInformation`) | **100 / 100 / 10 / 150** | — | truncado nos limites do contrato | mantido; não são dados de identificação |
| `integrationId` | 36 | — | UUID (36) | inalterado |

**Decisão registrada:** nenhum dado fiscal obrigatório de identificação (nome do emitente, tomador ou produto) é truncado.
- Onde o limite é comprovado (contrato da Spedy ou layout da SEFAZ), a emissão é bloqueada **antes** de chamar o provedor, com mensagem que explica o limite e onde corrigir.
- Onde não há limite comprovado, o valor vai inteiro e prevalece a validação do provedor.
- As constantes ficam em `SpedyPayloadBuilder` (`MAX_LEGAL_NAME`, `MAX_ISSUER_NAME`, `MAX_ISSUER_EMAIL`, `MAX_NFE_RECEIVER_NAME`, `MAX_NFE_ITEM_DESCRIPTION`), com a origem de cada limite documentada no código.
- **Impacto hoje:** nenhum tenant com nome de empresa acima de 80 (o limite era 50). Clientes com nome acima de 60 ou produtos com nome acima de 120 que emitem NF-e passam a ver a mensagem em vez de uma nota com o nome cortado.

Novos testes: `NativeFiscalEmissionTest` (4: tomador da NF-e, descrição de item na NF-e e NFC-e, tomador da NFS-e inteiro, nome curto e e-mail do emitente) e `CompanyFiscalIdentityTest` (1: nome fantasia e e-mail do emitente do SaaS). O teste do tomador do SaaS passou a exigir o nome inteiro.

## 2. Testes JSON de `OrderFinancialIntegrityTest` (item 3)

As duas asserções comparavam metadados de `order_events` com `assertSame` e dependiam da ordem das chaves, que o JSON do MySQL normaliza. Agora usam `assertSameMetadata()`: ordena as chaves dos dois lados e mantém `assertSame`, preservando valores **e tipos** (`true` não vira `1`). Não foi usado `assertEqualsCanonicalizing`, que reordenaria os valores e esconderia `previous` e `new` invertidos.

## 3. Suítes (item 4)

| Banco | Resultado |
|---|---|
| SQLite (cópia descartável, `vendor` do `composer.lock` commitado, Laravel 12.69.2) | **563 passaram (3.273 asserções), 0 falhas** |
| MySQL **8.4.11** (container descartável, rede `--internal`, `tmpfs`, senha gerada e apagada) | **563 passaram (3.273 asserções), 0 falhas** |
| SQLite com o `composer.lock` não commitado de terceiros (Laravel 12.69.3) | **563 passaram** |
| `tsc --noEmit` / Prettier / Pint (arquivos alterados) / `vite build` | OK / OK / OK / OK |

## 4. Migration aditiva diante de dados existentes (item 5)

`2026_10_11_100000_widen_company_identity_fields` no MySQL 8.4.11, a partir de um banco com a migration revertida e dados reais inseridos:
- **dados preservados byte a byte** no `up` e no rollback: 50 caracteres multibyte (`Ç`), emoji (`🚗`), travessão, `NULL` e string vazia;
- **definição preservada**: `utf8mb4`/`utf8mb4_unicode_ci`, `NULL` permitido e default `NULL` iguais antes e depois; só o tamanho muda (12 colunas conferidas em `information_schema`);
- rollback com todos os valores até 50 caracteres: colunas voltam a 50 sem perda;
- rollback com um valor de 51: **interrompido** ("Rollback interrompido: 1 registro(s) em companies.street…"), e a coluna continua em 150;
- operação de metadados (`ALTER … MODIFY` aumentando `varchar`), cerca de 0,7 s no banco de teste. Em produção o tempo depende do tamanho de `companies`/`tenants` (tabelas pequenas, uma linha por tenant).

## 5. Revisão dos commits e dos arquivos não commitados (item 6)

- **Commits locais**, nenhum enviado: `4e100e3b` (estrutura do storage), `640645b1` (VETOR-ROOT-FISCAL-02) e `27236802` (este).
- `640645b1` foi revisto:
  - políticas de acesso (`isRootAdmin` nos dois middlewares e na `UserPolicy`);
  - `AdminUserRequest` sem atribuição em massa;
  - API exigindo tenant;
  - observer de auditoria disparando só para CNPJ e razão social;
  - `forceScheme` condicionado ao `APP_URL` em HTTPS.
  - Nenhum problema novo; o único ajuste veio desta execução (política de nomes).
- **`.env.example`** (alterado por terceiros às 10:44, **não tocado**): só `APP_NAME=TechOs` para `APP_NAME=VetorOs`. Nenhuma variável sensível preenchida (verificado só por nome). Observações:
  - a grafia da marca é **VetorOS**;
  - como o compose usa esse arquivo como `env_file`, o valor aparece em produção (remetente e assunto de e-mails). Decidir a grafia antes de commitar.
- **`composer.lock`** (alterado por terceiros às 10:44, **não tocado**): `composer update` de patches em 34 pacotes, incluindo `laravel/framework` 12.69.2 → 12.69.3, `symfony/*` 7.4.19/20 e `nesbot/carbon` 3.14.2. `composer.json` inalterado e `content-hash` igual. A suíte passa com ele (§3). Pode ser commitado em separado, por quem fez a atualização.
- `correio.md` e este `executed.md` continuam fora dos commits. **Não commitar o `executed.md` enquanto o repositório for público** (ele descreve os P0).

## 6. Checklist de implantação (item 7)

**Pré-requisitos (bloqueantes)**
- [ ] P0-1 e P0-2 tratados e **liberados pelo responsável** (repositório privado, evidências preservadas, credenciais rotacionadas, avaliação LGPD).
- [ ] Decisão sobre a limpeza do histórico tomada **antes** do push.
- [ ] Push do VetorOS (`4e100e3b`, `640645b1`, `27236802` e, se aprovado, o `composer.lock`) e da infra com o ponteiro atualizado.
- [ ] Janela combinada (alguns minutos de indisponibilidade dos 3 serviços do VetorOS).

**1. Verificação do ambiente** (VPS, somente leitura)
```bash
cd /opt/infra-abrasil
git status --short && git log -1 --format='%h %s'
stat -c '%a %U:%G' .env                                    # esperado 600 root:root
umask                                                      # esperado 0022 (pendência de 08/10)
docker compose ps vetoros vetoros-worker vetoros-scheduler nginx mysql
docker compose exec vetoros php artisan about --only=environment
docker compose exec vetoros php artisan migrate:status | tail -5
df -h / | tail -1
```

**2. Backup** (antes de qualquer alteração)
```bash
mkdir -p backups/root-fiscal-02
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers vetoros' \
  | gzip > backups/root-fiscal-02/vetoros_$(date +%Y%m%d_%H%M%S).sql.gz
gunzip -t backups/root-fiscal-02/*.sql.gz && zcat backups/root-fiscal-02/*.sql.gz | tail -1   # "Dump completed"
for s in vetoros vetoros-worker vetoros-scheduler; do docker tag infra-abrasil-$s:latest infra-abrasil-$s:rollback-root-fiscal-02; done
```

**3. Código e build**
```bash
git pull --ff-only origin main && git submodule update --init gateway/vetoros
git -C gateway/vetoros log -1 --format='%h'                # deve ser o commit aprovado
find gateway/vetoros -type f -perm 600 | head               # deve sair vazio (umask)
docker compose build vetoros vetoros-worker vetoros-scheduler
```

**4. Migrations** (revisar antes de aplicar)
```bash
docker compose run --rm --no-deps vetoros php artisan migrate:status
docker compose run --rm --no-deps vetoros php artisan migrate --pretend    # conferir só os ALTER de varchar
docker compose run --rm --no-deps vetoros php artisan migrate --force
```
Também ficam pendentes as migrations INTEL (`2026_10_08_*` a `2026_10_10_*`), se ainda não aplicadas. O `--pretend` lista todas.

**5. Troca dos serviços**
```bash
docker compose up -d --no-deps vetoros vetoros-worker vetoros-scheduler && docker compose exec nginx nginx -s reload
```

**6. Health checks**
- [ ] `curl -sS -o /dev/null -w '%{http_code}' https://vetoros.com.br/up` → 200; `/login` → 200; `/admin` sem sessão → 302 para o login.
- [ ] `docker compose ps`: os 3 serviços `healthy`; `docker compose logs --since 10m vetoros vetoros-worker | grep -i -E "error|exception"` vazio.
- [ ] Login do RootAdmin → `/admin` abre; um usuário de tenant em `/admin` volta para `/app`.
- [ ] App técnico/atendimento: login de usuário de tenant funciona; `/api/clientes` responde só os clientes da empresa.
- [ ] RootAdmin → Fiscal → Notas do SaaS: o bloco "Tomador da nota" aparece; emitir **em homologação** uma nota de teste, se o emitente estiver em homologação.
- [ ] Tela Empresa de um tenant: salvar sem alterar dados; conferir em `fiscal_admin_audits` que **não** houve registro (só CNPJ e razão social geram).
- [ ] `SpedyPlatformSetting::webhook_url` começa com `https://`.
- [ ] Scheduler executando `fiscal:sync-spedy` (logs).

**7. Rollback**
- **Aplicação:** `for s in vetoros vetoros-worker vetoros-scheduler; do docker tag infra-abrasil-$s:rollback-root-fiscal-02 infra-abrasil-$s:latest; done && docker compose up -d --no-deps vetoros vetoros-worker vetoros-scheduler`.
- **Banco** (só se necessário):
  - `docker compose run --rm --no-deps vetoros php artisan migrate:rollback --step=1`. Recusa reduzir colunas que já tenham valores com mais de 50 caracteres; nesse caso manter as colunas largas, porque o código antigo funciona com elas;
  - em último caso, restaurar o dump do passo 2.
- **Código:** `git revert 27236802 640645b1` (e `4e100e3b`, se necessário) e novo build.

**Pós-implantação**
- [ ] Procedimento de ambiente da execução VETOR-ROOT-FISCAL-02 (C4: `APP_ENV=production`, `LOG_LEVEL=warning`, `APP_NAME`, `VETOROS_APP_URL` em HTTPS) em janela própria.
- [ ] Atualizar o ponteiro do submódulo na infra e registrar a implantação no `executed.md` da infra.

---

# Execução de `correio.md`: VETOR-ROOT-FISCAL-02 (segurança e aperfeiçoamento do rootAdmin). Resultado: **IMPLEMENTADO E VALIDADO, COMMIT LOCAL `640645b1`, SEM PUSH, SEM DEPLOY**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado:** `b45ec3d4dde3909112624a1a0dc4ad22a633ebeb885cb56e1e6b46cbf0254b71`. O anterior era o complemento do VETOR-ROOT-FISCAL-01 (`72b2ad74…`), então este foi executado.
- **Base:** `4e100e3b` (`main`). **Commit:** `640645b1 Restringe o rootAdmin e prepara o cadastro fiscal B2B (VETOR-ROOT-FISCAL-02)`, 32 arquivos.
- **Não feito, conforme o correio:** push, deploy, migrations em produção, troca da `APP_KEY`, alteração de credenciais. Nenhum segredo foi exibido.
- **Fora do commit:**
  - este `executed.md` e o `correio.md`;
  - `.env.example` e `composer.lock`, alterados por terceiros às 10:44 e não tocados.
  - **Não commitar este `executed.md` enquanto o repositório for público:** ele descreve os incidentes P0.

## Fase A — Segurança prioritária

| Item | O que foi feito |
|---|---|
| A1 `AdminAccessMiddleware` | Nova política única `User::isRootAdmin()` (sem tenant **e** papel root), usada também em `RootAdminOnly` e `UserPolicy`. Usuário de tenant continua indo para `/app`; qualquer outro usuário recebe **403** "Acesso restrito ao RootAdmin." (JSON quando pedido). Antes, qualquer usuário sem tenant entrava |
| A2 `Admin\UserController` | `AdminUserRequest` substitui `$request->all()`: grava só nome, e-mail, telefones, função, empresa, status e senha. Função validada contra a lista; **RootSystem é o único papel sem empresa** e os demais exigem um tenant existente. **Conceder RootSystem** (criar ou promover) exige a **senha do RootAdmin**. O RootAdmin não pode remover o próprio acesso nem se excluir, e não é possível excluir o último RootAdmin. RootApp antigo sem empresa pode ser editado sem perder o acesso. As telas `create-user` e `edit-user` desabilitam a empresa para RootSystem e pedem a senha |
| A3 Testes | `RootAdminAccessTest` (13 testes): política; papéis comuns sem tenant negados em 11 rotas do `/admin` e em `/admin/fiscal`; usuários de tenant redirecionados e sem escrita; visitante vai para o login; root entra; empresa obrigatória; papel desconhecido; RootSystem com empresa; senha para conceder; campos extras ignorados (`can_view_all_orders`, `commission_percentage`); promoção; edição sem nova senha; autoproteção |
| A4 Outras rotas | `routes/admin-fiscal.php` já usava `RootAdminOnly`. **Achado novo e corrigido:** a API dos apps (`auth:sanctum`) não exigia tenant. Sem tenant o `TenantScope` não filtra, e `GET /api/clientes` (`Customer::get()`), `/api/allorder` e outras rotas devolveriam dados de **todas as empresas** a um token de usuário sem tenant (inclusive o root). Foi criado o `EnsureTenantApiUser` (403) no grupo, e `/api/loginuser` não emite token para usuário sem tenant. `TenantApiAccessTest` (3 testes). Rotas `web`/`app` já exigem tenant (`AppAccessMiddleware`) |
| A5 Plano P0 | Seção "Plano de resposta aos incidentes P0" abaixo |
| A6 | Nenhum segredo revelado; `APP_KEY` não alterada |

## Fase B — Cadastro fiscal e empresas

| Item | O que foi feito |
|---|---|
| B1 Limites | Migration aditiva `2026_10_11_100000_widen_company_identity_fields`: `companies.shortname`, `companyname`, `street`, `site` e `email` → 150; `district`, `city` e `complement` → 100; `tenants.street` → 150, `district`, `city` e `complement` → 100. Justificativa: a razão social na Receita tem até 150; logradouro e complemento do NFS-e Nacional/NF-e cabem nesses tamanhos. Validações ajustadas na tela de Empresa, no cadastro de tenant do admin (`company` max 150) e no cadastro público. **Correção de bug:** o cadastro público aceitava razão social de 255 e a gravava em `companyname varchar(50)`, o que fazia o cadastro falhar com nomes longos |
| B2/B3 | `companies` continua sendo a empresa do tenant e `admin_fiscal_settings` o emitente da ABrasil. Nenhuma tabela nova |
| B4 Auditoria | `CompanyIdentityObserver` (`#[ObservedBy]` em `Company` e `Tenant`) registra em `fiscal_admin_audits` (`company.identity_changed`) as mudanças de CNPJ e razão social: origem (`companies`/`tenants`), de/para, usuário e IP. Cobre a tela do tenant, o RootAdmin e a sincronização entre as tabelas |
| B5 Tomador | Notas do SaaS mostram o bloco **"Tomador da nota (como será enviado ao emissor)"**, com nome, CPF/CNPJ, e-mail e endereço exatamente como no payload, um aviso quando o nome passa de 60 caracteres e será cortado e um aviso da última mudança de CNPJ ou razão social (data e autor). A confirmação da emissão mostra o CPF/CNPJ |
| B6 Spedy | Limites centralizados em `SpedyPayloadBuilder::MAX_LEGAL_NAME` (80) e `MAX_RECEIVER_NAME` (60). Razão social do emitente acima de 80 caracteres (tenant ou ABrasil) agora **bloqueia** a emissão com mensagem clara, em vez de enviar o nome truncado. Hoje nenhum tenant é afetado, porque o limite era 50. Endereços continuam truncados como antes |

Testes: `CompanyFiscalIdentityTest` (7 testes); `CompanyControllerTest` foi atualizado para o novo limite (151 caracteres em vez de 51).

## Fase C — Infraestrutura e webhooks

- **C1 Auditoria do esquema:**
  - o nginx da infra entrega o VetorOS por **FastCGI** (`include fastcgi_params`), não por proxy HTTP;
  - o `fastcgi_params` padrão repassa `HTTPS=on` nas conexões 443, então o Laravel já identifica HTTPS pela própria requisição;
  - vetoros.com.br na porta 80 redireciona para HTTPS;
  - fora de requisição (filas, agendador, e-mails), o esquema vem do `APP_URL`;
  - o ambiente real de produção não foi acessado.
- **C2 Webhook:** a guarda passou de `app()->isProduction()` para `PublicUrl::isSecureOrLocal()`. HTTP só é aceito para `localhost`, `127.0.0.1`, `::1`, `*.localhost` e `*.test`, independentemente de `APP_ENV` (produção roda como `local`).
- **C3 Proxy e URLs:**
  - **Não foi adicionado `trustProxies`:** sem proxy HTTP na frente, confiar em faixas privadas permitiria falsificar `X-Forwarded-Proto` e o IP. Se o nginx usar o proxy userland do Docker, o `REMOTE_ADDR` vira o gateway 172.x;
  - em vez disso, quando `APP_URL` é HTTPS, `URL::forceScheme('https')` garante HTTPS em todas as URLs geradas.
- Testes: `PublicUrlSchemeTest` (3 testes, incluindo webhook HTTP recusado com `APP_ENV=local`).
- **C4 Procedimento de produção** (separado, a executar com aprovação; não altera credenciais):
  1. Na VPS: `stat -c '%a %U:%G' /opt/infra-abrasil/.env` (esperado `600 root:root`) e `docker compose exec vetoros php artisan about --only=environment`.
  2. No `.env` da infra: `APP_ENV=production`, `VETOROS_APP_URL=https://vetoros.com.br` e, no compose ou no `.env.example` usado como `env_file`, `LOG_LEVEL=warning` e `APP_NAME=VetorOS`. Hoje o compose usa `APP_ENV: ${APP_ENV:-local}` e `env_file: gateway/vetoros/.env.example`.
  3. Recriar só `vetoros`, `vetoros-worker` e `vetoros-scheduler`; validar `/up` e o login.
  4. Conferir `SpedyPlatformSetting::webhook_url` (deve ser `https://vetoros.com.br/api/webhooks/spedy`); se não for, reconfigurar pelo RootAdmin → Fiscal → Integração.
  5. Rollback: restaurar o `.env` anterior e recriar os 3 serviços.

## Fase D — Validação

| Verificação | Resultado |
|---|---|
| Suíte completa, SQLite (cópia descartável via `git ls-files`, `vendor` do `composer.lock` commitado) | **558 passaram (3.264 asserções), 0 falhas.** Base anterior: 532; 26 testes novos |
| Testes desta entrega e de admin/API/empresa no **MySQL 8.4.11** descartável (rede interna, `tmpfs`) | **50 passaram** |
| Suíte completa no MySQL 8.4.11 | 555 passaram e 3 falharam, todas por ordem de chaves JSON (o MySQL normaliza). 1 era no teste novo e foi corrigida (`assertEquals`); **2 já existiam** em `OrderFinancialIntegrityTest` (`9c69101a`), sem relação com esta entrega |
| Migration no MySQL | `migrate` (`companies.companyname` 50 → 150 e demais colunas conferidas em `information_schema`), `migrate:rollback --step=1` (volta a 50) e `migrate` de novo: OK. Com um valor de 120 caracteres gravado, o rollback é **interrompido** ("Rollback interrompido: 1 registro(s) em companies.companyname…") e a coluna continua com 150 |
| TypeScript (`tsc --noEmit`) | sem erros |
| Prettier (arquivos alterados) | OK |
| Pint (arquivos alterados) | OK, exceto `App/UserController.php`, que **já falhava antes** (verificado na versão commitada); não foi reformatado |
| Build (`vite build`, cópia descartável) | OK, 257 entradas no manifest. O aviso de chunk > 500 kB já existia |
| Emissão fiscal dos tenants | preservada (`FiscalCentralAdministrationTest` e testes fiscais passando); a única mudança é o bloqueio de razão social > 80 |
| NFS-e manual do SaaS | modelo inalterado; só foi acrescentada a conferência do tomador |

**Migrations e rollback:** a única migration é aditiva. Em produção, rodar só depois de backup:
- **aplicar:** `php artisan migrate --force`;
- **reverter:** `php artisan migrate:rollback --step=1`, que se recusa a reduzir colunas com dados maiores que 50;
- **reverter o código:** `git revert 640645b1`.

## Plano de resposta aos incidentes P0

Os dois repositórios são públicos. **P0-1:** dump real (`backup/db_backup.sql`, 7.094 clientes, 9 hashes de senha, sessões, 1 senha SMTP cifrada) no histórico desde 22/05. **P0-2:** credenciais no histórico do `.env.example`: Mercado Pago produção (28/04 a 18/09), Gemini (13/08 a 28/08), `APP_KEY`, `DB_PASSWORD` e `MAIL_PASSWORD` (04/04 a 18/04).

### Ação manual imediata (não é código)
1. **Conter:** tornar `brasilgb/vetoros` e `brasilgb/infraabrasil` privados.
2. **Preservar evidências** antes de qualquer limpeza:
   - clone espelho (`git clone --mirror`) guardado offline com acesso restrito;
   - registrar os commits `8643db95`/`e12e843a` (dump) e `e7e19dc0` a `1032bcd9`, `157fa84c` a `d8e005b8` e `22a5a86a` a `51e80104` (credenciais), as datas e o fato de que o repositório estava público;
   - se disponível, exportar o tráfego e os clones do repositório (GitHub Insights).
3. **Inventário e rotação:**

| Credencial | Onde é usada | Ação |
|---|---|---|
| `MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY` (produção) | VetorOS/VetorPet (infra `.env`) | Gerar novas no painel do Mercado Pago e revogar as antigas; conferir pagamentos e estornos não reconhecidos de 28/04 até hoje |
| `MP_WEBHOOK_TOKEN` | webhook de pagamento | Trocar e reconfigurar a URL de notificação |
| `GEMINI_API_KEY` | (não usada no código atual) | Revogar no Google AI Studio e conferir consumo |
| `DB_PASSWORD`, `MAIL_PASSWORD` de abril | ambiente anterior (HostGator) | Trocar se ainda existirem; desativar a conta SMTP se não for mais usada |
| Senha SMTP do tenant presente no dump | SMTP desse tenant | Pedir ao tenant que troque a senha no provedor e regrave em Sistema e módulos |
| Hashes bcrypt dos 9 usuários do dump | login do VetorOS | Forçar troca de senha desses usuários e encerrar sessões |
| `APP_KEY` publicada | — | **Não trocar ainda.** Primeiro comparar, por hash, com a de produção. Se for igual, planejar a troca com recifragem (abaixo) |

4. **Dependências da `APP_KEY`** (para quando a troca for decidida):
   - senhas SMTP dos tenants (`others.mail_password`, `Crypt`);
   - chave pública das OS;
   - segredos da Spedy (`spedy_platform_settings`, `fiscal_settings.api_token`/`webhook_secret`/`nfce_csc`, `admin_fiscal_settings.api_token`/`webhook_secret`);
   - sessões e cookies (todos os usuários saem).
   - Procedimento: backup → `security:audit-app-key` → script que decifra com a chave antiga e recifra com a nova, em transação → trocar → `--verify-hash` → validar emissão fiscal e e-mail. Rollback: chave antiga + backup.
5. **Avaliação LGPD:**
   - registrar o incidente (natureza dos dados, cerca de 7.094 titulares, cerca de 830 CPFs, período de exposição);
   - avaliar com o encarregado ou jurídico a comunicação à ANPD e aos titulares (art. 48: risco ou dano relevante);
   - identificar o controlador dos dados (tenant do dump) e comunicá-lo.
6. **Limpar o histórico** depois da preservação: `git filter-repo --path backup/ --invert-paths` mais a substituição dos valores do `.env.example`, seguido de force push com o repositório ainda privado; avisar quem tiver clones. A limpeza **não substitui** a rotação.

### O que pode ser corrigido no código
- Pre-commit/CI com varredura de segredos (gitleaks ou trufflehog) e bloqueio de `*.sql`, `backup/` e `.env*` reais (exceto `.env.example` vazio).
- `.gitignore` com `backup/`, `*.sql`, `*.sql.gz` e `*.dump`.
- Teste automatizado que falha se o `.env.example` tiver valor em variáveis sensíveis.
- Recifragem guiada da `APP_KEY` como comando artisan (complemento do `security:audit-app-key`), com dry-run e verificação.
- Remover `public/qrcode.jpeg` e `public/auth-images.png`, sem uso.

Esses itens não foram implementados nesta execução: o correio limitou a fase A ao plano.

## Arquivos alterados (commit `640645b1`)

- **Novos:**
  - `app/Http/Middleware/EnsureTenantApiUser.php`, `app/Http/Requests/Admin/AdminUserRequest.php`, `app/Observers/CompanyIdentityObserver.php`, `app/Support/PublicUrl.php`;
  - a migration `2026_10_11_100000_widen_company_identity_fields.php`;
  - os testes `RootAdminAccessTest`, `CompanyFiscalIdentityTest`, `PublicUrlSchemeTest` e `TenantApiAccessTest`.
- **Alterados:**
  - middlewares `AdminAccessMiddleware` e `RootAdminOnly`;
  - models `User`, `Company` e `Tenant`, e a `UserPolicy`;
  - controllers `Admin/UserController`, `App/UserController` (loginuser), `App/CompanyController`, `Auth/RegisteredUserController`, `Admin/Fiscal/FiscalIntegrationController` e `Admin/Fiscal/SaasInvoiceController`, e o request `Admin/TenantRequest`;
  - serviços `SaasInvoiceService` e `SpedyPayloadBuilder`, o `AppServiceProvider` e `routes/api.php`;
  - telas `admin/users/create-user`, `admin/users/edit-user`, `admin/fiscal/saas`, `admin/fiscal/fiscal-tabs` e `app/company/index`;
  - o teste `CompanyControllerTest`.

## Pendências

1. **P0:** ações manuais do plano acima (privatizar, preservar, rotacionar, LGPD).
2. Push de `4e100e3b` e `640645b1`, **depois** de decidir a limpeza do histórico.
3. Ponteiro do submódulo na infra (ainda em `439edbf0`).
4. Executar o procedimento de produção da fase C4 e a migration, com backup.
5. As 2 falhas preexistentes de ordem de chaves JSON em `OrderFinancialIntegrityTest` no MySQL.

---

# Execução de `correio.md`: VETOR-ROOT-FISCAL-01 + complemento (reaproveitamento do cadastro de Empresa e auditoria de segurança). Resultado: **AUDITORIA SOMENTE LEITURA CONCLUÍDA, COM 2 ACHADOS P0**

- **Data:** 2026-10-09 (America/Sao_Paulo).
- **SHA-256 do `correio.md` executado** (complemento): `72b2ad743a1c9890fa4fd2ff41accb75eb960245fce1629cf7c5b49df9175322`. O correio anterior deste arquivo (CRM-WA-30.1) era outro, então este foi executado.
- **Observação:** o correio principal VETOR-ROOT-FISCAL-01 estava neste arquivo às 09:56 e foi substituído pelo complemento às 10:38. Não há cópia dele. Esta execução cobre o complemento inteiro e o objetivo do principal que ficou visível: auditar o rootAdmin e preparar a emissão de NFS-e da ABrasil Sistemas aos tenants B2B, preservando a emissão própria de cada tenant. **Se o correio principal tinha outros itens, reenvie-o.**
- **Código auditado:** VetorOS `439edbf0` (`main`).
- **Nada foi alterado:** nenhuma implementação, migration, deploy, commit ou acesso à produção. Nenhum segredo foi reproduzido: valores foram tratados só por nome, tamanho, prefixo de formato e hash.

## 0. Achados P0 (exposição confirmada)

Os dois repositórios são **públicos** no GitHub (`api.github.com/repos/brasilgb/vetoros` e `.../infraabrasil` respondem 200 sem autenticação).

| # | Achado | Evidência | Situação |
|---|---|---|---|
| **P0-1** | **Dump real de banco versionado no histórico público.** `backup/db_backup.sql` (1,5 MB, banco `ande3377_sigmaos`, MySQL 8.0.45) foi adicionado em `8643db95` e removido em `e12e843a`, os dois em 22/05/2026 e alcançáveis por `main`. Contém **7.094 clientes** (cerca de 830 com CPF), **9 usuários com hash bcrypt**, 8 sessões, 2 tenants, 20 OS, 2 pagamentos e 3 linhas de `others`, 1 delas com senha SMTP cifrada | contagem agregada; nenhum dado pessoal foi exibido. A cópia temporária usada na contagem foi apagada | **Exposição de dados pessoais (LGPD) e de material de credencial.** A senha SMTP desse dump deve ser considerada comprometida, porque uma `APP_KEY` também esteve publicada no histórico (P0-2) |
| **P0-2** | **Credenciais reais no histórico de `.env.example`.** `MP_ACCESS_TOKEN` e `MP_PUBLIC_KEY` em formato de **produção do Mercado Pago (`APP_USR-`)** e `MP_WEBHOOK_TOKEN` em 7 commits, de `e7e19dc0` (28/04) a `1032bcd9` (18/09). `GEMINI_API_KEY` em 3 commits (13/08 a 28/08). `APP_KEY` (`base64:`), `DB_PASSWORD` e `MAIL_PASSWORD` em 3 commits (04/04 a 18/04) | varredura do histórico só por nome, formato e tamanho | O `.env.example` atual está limpo. **Não foi possível comparar com os valores em uso:** não há `.env` local da infra, e a comparação deve ser feita na VPS, por hash. Pelo critério do correio, tratar como **P0** e rotacionar |

**Ações recomendadas (manuais, fora desta auditoria):**
1. **Rotacionar imediatamente**: token, chave pública e segredo de webhook do Mercado Pago; chave Gemini; senha SMTP do tenant presente no dump; e qualquer senha de banco ou SMTP de abril que ainda esteja em uso.
2. Verificar se a `APP_KEY` de produção é igual à que foi publicada. Se for, gerar uma nova e recifrar os dados que dependem dela (senhas SMTP dos tenants, chaves públicas das OS, segredos da Spedy). O comando `security:audit-app-key` ajuda a medir o impacto.
3. Avaliar com o responsável de dados a comunicação do incidente (LGPD) envolvendo os 7.094 clientes do dump.
4. Tornar os repositórios privados ou reescrever o histórico (`git filter-repo` removendo `backup/` e os valores do `.env.example`), seguido de force push. Reescrever o histórico **não substitui** a rotação: o conteúdo já foi público e pode ter sido copiado.

## 1. Cadastro de Empresa (`fd11a0e9`)

| Verificação | Resultado |
|---|---|
| 1. Model/tabela | `App\Models\App\Company` / `companies` (uma por tenant, trait `Tenantable`). O tenant também guarda dados próprios em `tenants` (`company`, `cnpj`, `email`, `phone`, `whatsapp`, endereço) |
| 2. Dono do cadastro | **Exclusivo do tenant.** `companies.tenant_id` é nulo apenas no schema; no dump, 5 empresas, todas com tenant. Não existe registro de Empresa para a ABrasil |
| 3. Campos | `companies`: `shortname`, `companyname`, `cnpj`, `logo`, endereço (`zip_code`, `state`, `city`, `district`, `street`, `number`, `complement`), `telephone`, `whatsapp`, `site`, `email`, **todos `varchar(50)`**. Inscrição estadual, inscrição municipal e regime tributário **não** ficam em `companies`, e sim em `fiscal_settings` (`state_registration`, `municipal_registration`, `company_tax_regime`) |
| 4. Uso fiscal | `SpedyPayloadBuilder::company()` e `companyAddress()` usam CNPJ, razão social, nome fantasia, e-mail, telefones e endereço de `companies`, com fallback para `tenants`. IE, IM e regime vêm de `fiscal_settings`. O webhook confere o CNPJ do emissor por `companies` e, na falta, por `tenants` |
| 5. Código e testes | `CompanyController` (`index`, `update`, com validação inline e sem FormRequest), página `resources/js/pages/app/company/index.tsx`, `CompanyControllerTest` (3 testes). A gravação atualiza `companies` e `tenants` na mesma transação (`companyname→company`, `telephone→phone`) |
| 6. Perfil fiscal da ABrasil | **Já existe e é independente de tenant:** `AdminFiscalSetting` / `admin_fiscal_settings` (singleton). Guarda razão social, nome fantasia, CNPJ, inscrição municipal, endereço, regime, IBGE, item LC 116, ISS, tipo de tributação, série, e-mail, cadastro na Spedy, certificado e ambiente. A chave da Spedy fica cifrada e oculta. Tela RootAdmin → Fiscal → Notas do SaaS (`saas.tsx`) |
| 7. Reaproveitamento | **Não criar tabela, model nem tela nova de empresa para a ABrasil.** O emitente do SaaS é `admin_fiscal_settings`, e o tomador (tenant) é `tenants`, sincronizado com `companies`. Ajustes sugeridos na §3 |
| 8. Isolamento | Adequado: emitente do SaaS (`admin_fiscal_settings`, `admin_fiscal_documents`, rotas `root.admin`) separado das empresas dos tenants (`companies`, `fiscal_settings`, `fiscal_documents`, escopo de tenant). O webhook Spedy procura primeiro a nota do tenant e depois a do SaaS, sempre conferindo o CNPJ do emissor. As credenciais ficam em colunas cifradas distintas (`fiscal_settings.api_token`, `admin_fiscal_settings.api_token`) |

## 2. rootAdmin: inventário

- **Rotas `admin`** (`routes/admin.php`, middleware `AdminAccessMiddleware`): painel, relatórios, tenants (com prévia e envio de e-mails de assinatura), filiais, planos, recursos, períodos, configurações (só nome e logo), usuários, avaliações, ajustes, documentos fiscais antigos e manual de ajuda (importação do JSON).
- **Rotas `admin/fiscal`** (`RootAdminOnly`): integração Spedy (chave, diagnóstico, webhook), empresas emissoras (liberação, cadastro, certificado), monitoramento e Notas do SaaS (emitente, certificado, emissão a partir de pagamento aprovado, envio por e-mail, cancelamento, PDF/XML).
- **NFS-e da ABrasil aos tenants:** implementada em modo **manual**. O root escolhe o pagamento aprovado e emite com o valor cobrado; há uma nota ativa por pagamento, e o e-mail de fatura paga anexa a nota quando ela existe. Tomador = `tenants` (CNPJ/CPF, nome, e-mail, endereço).

## 3. Problemas e recomendações do rootAdmin/fiscal B2B

| # | Gravidade | Problema | Recomendação |
|---|---|---|---|
| R1 | **Alta** | `AdminAccessMiddleware` libera `/admin` para **qualquer usuário sem tenant**, sem exigir papel root. O `Admin\UserController::store` cria usuários a partir de `$request->all()` com `tenant_id` opcional. Um usuário criado sem tenant por engano (técnico, operador) ganha o RootAdmin completo. Nenhum controller de `/admin` faz checagem própria | Exigir `isRoot()` no middleware, igual ao `RootAdminOnly`; validar papel e tenant obrigatórios no cadastro. No dump, hoje existe só 1 usuário sem tenant (root, papel 99), então o risco é latente |
| R2 | Média | `companies.companyname` e o restante têm limite de 50 caracteres (validação e coluna), enquanto razões sociais passam disso com frequência. A sincronização leva o nome truncado para `tenants.company`, que é o tomador da NFS-e do SaaS | Ampliar os campos (migration aditiva) e a validação para 150 caracteres |
| R3 | Média | O tomador da NFS-e do SaaS (`tenants`) pode ser alterado pelo próprio tenant na tela de Empresa, e o root também edita `tenants`. Não há trilha dessas mudanças nem conferência antes da emissão | Mostrar ao root, antes de emitir, os dados do tomador com a data da última alteração; auditar alterações de CNPJ e razão social |
| R4 | Média | A guarda "webhook precisa ser HTTPS em produção" depende de `APP_ENV=production`. Com `local` em produção, ela não vale, e não há `trustProxies` nem `forceScheme`: atrás do nginx, `route('webhook.spedy')` pode gerar `http://` | Conferir `spedy_platform_settings.webhook_url` na VPS; corrigir `APP_ENV` (§4) |
| R5 | Baixa | A emissão do SaaS é só manual; não há emissão automática ao aprovar o pagamento nem lote mensal | Se desejado, emitir automaticamente quando o pagamento for aprovado, mantendo o manual como alternativa |
| R6 | Baixa | "Configurações" do admin guarda só nome e logo; não há SMTP central (VETOR-MAIL-01) | Ver VETOR-MAIL-01 no `executed.md` da infra |

## 4. Auditoria de segurança complementar

| Item | Resultado |
|---|---|
| Segredos reais em `.env.example` | **Atual: limpo** (14 variáveis sensíveis, todas vazias). **Histórico: P0-2** |
| `APP_ENV=local` / `LOG_LEVEL=debug` em produção | Confirmado pela configuração: o compose usa `env_file: gateway/vetoros/.env.example` (`APP_ENV=local`, `LOG_LEVEL=debug`, `APP_NAME=TechOs`) e `APP_ENV: ${APP_ENV:-local}`. `APP_DEBUG` fica `false`. O código não tem `Log::debug`, então o nível `debug` afeta só logs do framework e dos pacotes. Efeito prático do `local`: desliga a guarda HTTPS do webhook (R4). **Recomendação:** `APP_ENV=production`, `LOG_LEVEL=warning` e `APP_NAME=VetorOS` pelo `.env` da infra |
| Permissões do `.env` | **Não verificável daqui.** Não há `.env` local e a VPS não foi acessada. O `.env` está no `.gitignore` e nunca foi versionado na infra. Verificar com `stat -c '%a %U:%G' /opt/infra-abrasil/.env` (esperado `600 root:root`). Lembrete da execução de 08/10: o umask restritivo já causou `600` em arquivos de código |
| Arquivos antigos e backups | Versionados hoje: nenhum `.sql`, `.zip`, `.bak`, `.apk` ou chave. Em `public/`: `qrcode.jpeg` (QR estático de 04/04/2026, sem referência no código) e `auth-images.png` (sem referência; o layout usa `resources/js/images/auth-images.jpg`), ambos candidatos a remoção. **Histórico:** `backup/db_backup.sql` (**P0-1**), `public/apk/vetor-imagem.apk` (18/06 a 12/07) e `mysql-init/init.sql` (só `CREATE DATABASE`, sem senhas). Infra: histórico sem dumps nem segredos |
| Falhas de testes preexistentes | **Resolvidas.** A suíte completa rodou em cópia descartável (`git archive` de `439edbf0`, SQLite em memória, `vendor` do mesmo `composer.lock`): **532 passaram (2.966 asserções), 0 falhas**. A rodada inicial falhou só por ambiente (autoload apontando para outro checkout e manifest do Vite vazio). As 36 falhas antigas foram corrigidas em `470db9bb` |

## 5. Verificações sugeridas na VPS (somente leitura)

```bash
stat -c '%a %U:%G' /opt/infra-abrasil/.env
docker compose exec vetoros php artisan about --only=environment
docker compose exec vetoros php artisan tinker --execute="echo App\Models\Admin\SpedyPlatformSetting::query()->value('webhook_url');"
# comparar por hash (sem imprimir) o MP_ACCESS_TOKEN e a APP_KEY atuais com os publicados no histórico
```

## Pendências e decisões

1. **P0:** rotação das credenciais (P0-2), decisão sobre a `APP_KEY`, tratamento LGPD do dump (P0-1) e limpeza ou privatização dos repositórios.
2. Reenviar o correio principal VETOR-ROOT-FISCAL-01, se ele tinha itens além do objetivo coberto aqui.
3. Aprovar as correções R1 a R4 (R1 é prioritária).

---

# Resultado — Fechamento das pendências do VetorOS (props lazy do Inertia + tela de Empresa + commits)

Data: 2026-10-01. Status: **commits feitos e imagem candidata pronta. Deploy em produção NÃO executado (bloqueado pelo controle de permissões do Claude Code), para ser feito pelo usuário.**

O relatório anterior (CRM-WA-30.1, Fases 1–5 e TESTE B) está no histórico do Git deste arquivo.

## Commits (branch `main`, local, sem push)

| Commit | Conteúdo |
|---|---|
| `de2f6744` | Endpoint `POST /api/integrations/registration-check` do CRM-WA-30 (middleware, controller, testes, rota, config, `.env.example`). Já estava em produção desde 30/09. |
| `fd11a0e9` | Tela de Empresa: validação igual ao tamanho das colunas (50), logo com nome UUID removido só após gravar, empresa + tenant na mesma transação. |
| `d41f3e85` | `HandleInertiaRequests`: props compartilhadas com consulta viraram closures (lazy), `customers` removido; toast não trata erro de JS como falha de conexão; `SharedInertiaPropsTest`. |

## Validação

- Nenhum componente do frontend lê o `customers` compartilhado (a busca de clientes usa `app.customers.search`).
- `tsc --noEmit` sem erros e `vite build` ok.
- Pest com MySQL 8.4 temporário: `SharedInertiaPropsTest`, `CompanyControllerTest` e `RegistrationCheckControllerTest` passam. Suíte completa: 267 ok, 36 falhas, **todas já falham no HEAD anterior** (pré-existentes, sem regressão).
- Tela de Empresa: o maior valor atual em produção para os campos limitados a 50 é 29 caracteres.

## Imagem candidata

- `infra-abrasil-vetoros:inertia-lazy-candidate` (`sha256:72b48494…`), gerada de uma cópia limpa do working tree (código idêntico ao commit `d41f3e85`, sem `public-old/` e `public_vetoros.zip`). Sintaxe ok, sem `customers` compartilhado, manifest do Vite presente, `public/apk` presente.
- Snapshot de rollback (imagem em produção `crm-wa-30.1-candidate`, `sha256:eb1df41c…`): `infra-abrasil-{vetoros,vetoros-worker,vetoros-scheduler}:rollback-inertia-lazy`. Compose salvo em `backups/docker-compose.yml.pre-inertia-lazy`.
- A imagem antiga `perf-vetoros-02-candidate` (30/09 18:30, de uma sessão interrompida) não foi usada.

## Deploy (pendente, executar em `/opt/infra-abrasil`)

```sh
for s in vetoros vetoros-worker vetoros-scheduler; do docker tag infra-abrasil-vetoros:inertia-lazy-candidate infra-abrasil-$s:latest; done
docker compose up -d --no-deps --no-build vetoros vetoros-worker vetoros-scheduler
docker compose ps vetoros vetoros-worker vetoros-scheduler nginx   # aguardar healthy
docker compose exec -T nginx nginx -s reload
```

Rollback: o mesmo, usando `infra-abrasil-$s:rollback-inertia-lazy` no lugar da candidata.

Sem migrations. Sem alteração de `.env`/compose.

O comando de deploy foi negado inteiro antes de rodar, então produção continua na imagem `crm-wa-30.1-candidate`. A consulta de conferência logo depois também foi negada, por isso o estado não foi reconferido nesta execução. A conferência pós-deploy (health, logs, páginas, ausência de `customers`) fica para depois que o usuário subir.

## Pendências fora do escopo (não alteradas)

- Lead 121 do CRM com trial vencido (25/05): ao ligar o scheduler do CRM, a automação de teste vencido pode mandar WhatsApp para ele.
- Possíveis segredos reais no `.env.example` versionado; produção com `APP_ENV=local`/`LOG_LEVEL=debug`; `/opt/infra-abrasil/.env` com permissão 644.
- `public-old/` e `public_vetoros.zip` (164 MB) soltos e não versionados no repositório.
