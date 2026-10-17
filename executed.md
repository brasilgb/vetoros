# VETOR-STABILIZE-03 — Estabilidade, SMTP e acesso administrativo aos tenants. Resultado: **IMPLEMENTADO, TESTADO E PUBLICADO (VetorOS `1bd11b0c`, CI VERDE; infra-abrasil `fd02834`); SEM DEPLOY E SEM MIGRATIONS EM PRODUÇÃO**

Data: 2026-10-17. Executor: estação de desenvolvimento local (WSL). Nenhum container de produção foi reiniciado e nenhum banco de produção foi acessado.

## Comparação do correio

O `correio.md` mudou desde a execução anterior (VETOR-STABILIZE-02). A instrução atual é a **VETOR-STABILIZE-03**. O relatório anterior foi removido deste arquivo e está no histórico do git.

## 1. Estabilidade dos dashboards (Financeiro, Vendas, Compras, Agendamentos)

**Auditoria:** as quatro abas gravavam `response.data`/`response.data.kpis` sem validar.
- Com falha da API, os indicadores apareciam como **R$ 0,00** (`KpiFinancial` e cartões).
- Uma resposta que não fosse lista quebraria os gráficos.

**Correção:**
- **`resources/js/lib/dashboard-payload.ts`** (puro, testado no Node):
  - `parseKpisPayload`: exige `{ kpis: {...} }`;
  - `parseSeriesPayload`: exige lista;
  - `parsePurchasesPayload`: indicadores obrigatórios; rankings e tendência ausentes viram listas vazias.
  - Resposta fora do formato vira **erro**.
- **`resources/js/hooks/use-dashboard-resource.ts`:** carregando → pronto ou erro, "Tentar novamente" e descarte de respostas atrasadas.
- **`ChartLoadState`** (STABILIZE-02) ganhou altura ajustável, para ocupar o lugar dos indicadores.
- **Abas:**
  - **Financeiro → Ordens:** indicadores e os dois gráficos.
  - **Vendas:** indicadores, meios de pagamento, pendências e cancelamentos (dependem da mesma resposta) e o gráfico do mês.
  - **Compras:** uma chamada alimenta a aba inteira; carregando/erro ocupa a aba.
  - **Agendamentos:** as duas fileiras de indicadores, os alertas e o gráfico de status.
- **Preservado:** gráficos, cartões, regras e chamadas à API (mesmos endpoints e parâmetros). O backend dos dashboards não mudou.

## 2. SMTP institucional da plataforma

**Auditoria:**
- O código já separava os e-mails:
  - **9 e-mails da plataforma** usam o mailer institucional (`AppliesSystemMailConfig`): cobrança e fatura do contrato SaaS, NFS-e do SaaS, avisos de vencimento/assinatura, pagamento confirmado, pesquisa de satisfação, boas-vindas e melhorias;
  - **6 e-mails das empresas** usam o SMTP próprio da empresa (`AppliesTenantMailConfig`): OS, orçamento, cobrança e NFS-e de contratos.
- **O problema era a configuração:**
  - o institucional lia `MAIL_*`, enquanto o `.env.example` documentava `ABRASIL_MAIL_*`, que ninguém lia;
  - em produção, o compose passava o `.env.example` como ambiente, e as `MAIL_*` vinham vazias ou com valores de exemplo;
  - recuperação de senha e verificação de e-mail (mailer padrão) ficavam sem servidor.

**Correção (VetorOS):**
- **`config/mail.php`:**
  - O institucional lê **`ABRASIL_MAIL_*`**, com `MAIL_*` como alternativa; valor vazio conta como ausente.
  - Porta 465 ou `ssl` usam **SSL/TLS direto (`smtps`)**.
  - O mailer padrão e o remetente padrão usam o mesmo institucional.
  - O SMTP de cada empresa (`others`, `TenantMailConfig::tenantMailer`) não foi tocado e não cai no institucional.
- **`.env.example`:** `smtp.hostinger.com`, 465, `ssl`, `contato@abrasilsistemas.com.br`; `ABRASIL_MAIL_PASSWORD=` vazio, com aviso de nunca preencher.
- **`phpunit.xml`:** `ABRASIL_MAIL_PASSWORD/HOST/USERNAME` forçados vazios nos testes.

**Correção (infra-abrasil, commit `fd02834`):**
- **`docker-compose.yml`:** o serviço `vetoros` (e, por `extends`, worker e scheduler) recebe `MAIL_MAILER=smtp` e `ABRASIL_MAIL_*` **do `.env` da VPS**, com padrões não secretos. A senha vem **só** de `ABRASIL_MAIL_PASSWORD`. Variáveis em `environment` têm precedência sobre o `env_file`.
- **`.env.example` do infra:** documenta `ABRASIL_MAIL_PASSWORD=` (vazio).
- **Não alterado:** o `env_file: ./gateway/vetoros/.env.example` continua para valores não secretos. Substituí-lo por um arquivo fora do repositório fica como recomendação.

**Validação:**
- **Testes:** o novo `InstitutionalMailConfigTest` (4 testes) cobre:
  - prioridade de `ABRASIL_MAIL_*` sobre `MAIL_*` e SSL na 465;
  - alternativa `MAIL_*` e vazio contando como ausente;
  - `.env.example` sem senha;
  - avisos de cobrança, vencimento, assinatura e documentos fiscais no SMTP institucional, e e-mails das empresas no SMTP próprio.
  - Os 16 testes existentes de transporte (separação RootAdmin ↔ empresas, em fila e síncrono) continuam passando.
- **Envio real de ponta a ponta**, num Mailpit descartável em container, com variáveis apontando para ele: o `UserRegisteredMail` saiu como `contato@abrasilsistemas.com.br` (institucional), e o SMTP da empresa saiu como o remetente da empresa.
- **A conexão SSL real com a Hostinger depende da senha e só pode ser confirmada na VPS** (comando no item 5).

## 3. Modo Suporte (acesso do RootAdmin ao painel das empresas)

### Auditoria

- **Autenticação:** guard `web` com sessão; o RootAdmin é usuário com papel root e `tenant_id` nulo (`isRootAdmin()`).
- **Resolução do tenant:** `resolveCurrentTenantId()` usa o `tenant_id` do usuário logado (e a sessão só sem usuário).
  - **Risco encontrado:** com o `tenant_id` nulo do root, o `TenantScope` não filtraria nada em `/app`.
  - Há 45 pontos do código que leem `user()->tenant_id`.
- **Middlewares:**
  - `app` exige `tenant_id`;
  - `admin`/`root.admin` exigem RootAdmin;
  - `check.subscription` manda empresas bloqueadas para o checkout;
  - o route model binding (`Tenantable::resolveRouteBinding`) usa `resolveCurrentTenantId()`.
- **Policies e gates:** baseadas em `hasPermission()`; o root tem a lista completa de permissões de administrador.

### Desenho (sem autenticação paralela)

- **Entrada:**
  - RootAdmin → Empresas → **"Acessar painel"** abre um modal com empresa, CNPJ, assinatura, ambiente, **motivo** (10–500 caracteres) e a **senha do próprio RootAdmin** (reautenticação no momento, `current_password`, limite de 5 tentativas por minuto).
  - A senha do cliente nunca é pedida nem alterada.
- **Registro:**
  - grava `support_sessions` (root, empresa, motivo, IP, user agent, início e expiração);
  - **regenera o id da sessão**;
  - guarda na sessão **só o id do registro**.
- **Middleware `ApplySupportSession`**, que roda antes do `SubstituteBindings`:
  - valida a cada requisição: registro aberto, dono = usuário logado, usuário root e sem empresa **no banco**;
  - expirado → encerra (`expired`) e volta ao RootAdmin;
  - aplica a empresa **só em memória** (`tenant_id` marcado como original, nunca gravado);
  - a empresa vem do banco, nunca da URL; parâmetros como `?tenant_id=` são ignorados.
- **Identidade:** o RootAdmin continua sendo ele mesmo (`auth.user.id` é o dele), então registros e logs saem com o id real dele, e não como funcionário da empresa.
- **Escrita bloqueada por padrão:**
  - só GET/HEAD/OPTIONS e as rotas listadas em **`config/support.php`** (dados da empresa, Sistema e módulos e teste de e-mail, mensagens de WhatsApp, recibos, checklists, equipamentos e serviços);
  - **DELETE nunca**;
  - financeiro, fiscal, usuários, segurança e pagamentos ficam bloqueados. Incluir uma rota na lista é a "autorização específica", e fica registrado no git.
- **Isolamento:**
  - durante o suporte, o painel do RootAdmin fica inacessível, o que **impede sessões encadeadas**;
  - iniciar outra sessão é recusado.
- **Empresa bloqueada por assinatura:** o suporte entra assim mesmo, sem passar pelo checkout; os usuários da empresa continuam bloqueados.
- **Encerramento:**
  - "Encerrar suporte" (`POST /support/end`) fecha o registro (`manual`), limpa a sessão e volta para RootAdmin → Empresas;
  - **logout** também encerra (`logout`), e o fim também ocorre por expiração (`expired`) e por sessão inválida (`invalid`);
  - duração padrão: 60 minutos (`SUPPORT_SESSION_TTL_MINUTES`).
- **Faixa:** "**Modo Suporte — Empresa: [nome]** · acesso administrativo até HH:MM", com "Encerrar suporte", fixa no topo do painel e no tema do VetorOS (claro e escuro).
- **Auditoria:**
  - `support_sessions` registra início, fim, motivo, IP, user agent e responsável;
  - `support_session_actions` registra **cada escrita, permitida ou bloqueada**, com método, rota, caminho, status e se foi bloqueada. **Nada de senha, token ou corpo da requisição.**
  - **Retenção:** 365 dias (`SUPPORT_SESSION_RETENTION_DAYS`), com `model:prune` diário.

### Arquivos

- **Novos:**
  - `app/Http/Middleware/ApplySupportSession.php`
  - `app/Services/SupportSessionService.php`
  - `app/Http/Controllers/Admin/SupportSessionController.php`
  - `app/Listeners/EndSupportSessionOnLogout.php`
  - `app/Models/Admin/SupportSession.php`
  - `app/Models/Admin/SupportSessionAction.php`
  - `config/support.php`
  - migration `2026_10_17_200000_create_support_sessions_table.php`
  - `resources/js/components/admin/support-access-dialog.tsx`
  - `resources/js/components/support-mode-banner.tsx`
- **Alterados:**
  - `bootstrap/app.php` (middleware e prioridade)
  - `routes/admin.php`, `routes/web.php` e `routes/console.php` (limpeza)
  - `AppServiceProvider` (logout)
  - `CheckSubscriptionStatus`
  - `HandleInertiaRequests` (prop `support`)
  - `TenantController` (ambiente)
  - `layouts/app-layout.tsx`
  - `pages/admin/tenants/index.tsx`

## 4. Testes

- **Novo `tests/Feature/Admin/SupportModeTest.php`** (10 testes, 76 asserções), um para cada cenário do correio:
  1. RootAdmin entra na empresa escolhida, sem tocar na senha do cliente e mantendo a identidade.
  2. Senha errada e motivo curto são recusados.
  3. Usuário comum não consegue usar a funcionalidade.
  4. Sessão limitada à empresa: busca só traz os dados dela, registro de outra empresa dá 404, `?tenant_id=` não troca a empresa, `/admin` fica bloqueado e a sessão encadeada é recusada.
  5. Encerrar volta ao RootAdmin e corta o acesso.
  6. Sessão expirada perde o acesso.
  7. Correção permitida (cadastro de equipamento) é registrada com o administrador real, e o root continua sem empresa no banco.
  8. Operações críticas (abrir caixa, excluir cliente, criar usuário, pagar a assinatura) são bloqueadas, nada muda e as 4 tentativas ficam registradas.
  9. Logout encerra o acesso.
  10. Empresa bloqueada: o suporte entra, e o usuário dela continua bloqueado.
- **Sem regressão nos fluxos de Efí, Spedy e assinatura:** suíte completa verde.
- **Resultados:**
  - PHP: `php artisan test --parallel` → **800 passaram** (5577 asserções).
  - JavaScript: `npm run test:js` → **27 passaram** (13 de valores + 6 do gráfico + 8 dos validadores dos dashboards).
  - `tsc --noEmit` sem erros e `npm run build` ok.
  - Prettier ok, ESLint sem nenhum problema novo e Pint ok.
- **Migration nova em MySQL 8.4.11** (container descartável): aplicada, revertida e reaplicada.
- **Testes visuais** no Chromium e no Firefox, com servidor isolado, SQLite descartável e respostas simuladas:

| Aba | Real | Indicadores: HTML / sem `kpis` / 500 | Gráfico: objeto / 500 | R$ 0,00 com erro | Erros JS |
|---|---|---|---|---|---|
| Financeiro | dados | erro e recupera | erro | 0 | 0 |
| Vendas | dados | erro e recupera | erro e recupera | 0 | 0 |
| Compras | dados | erro e recupera | — | 0 | 0 |
| Agendamentos | dados | erro e recupera | erro e recupera | 0 | 0 |

  Os R$ 0,00 nos cenários reais são zeros verdadeiros, porque a empresa de teste não tem faturamento. No Financeiro, o mesmo endpoint alimenta dois gráficos, e cada um tem o próprio "Tentar novamente".

- **Modo Suporte, nos dois navegadores, claro e escuro:**
  - o modal mostra empresa, CNPJ, assinatura e ambiente;
  - a senha errada mostra "Senha incorreta." e não entra;
  - a entrada leva a `/app` com a faixa;
  - `/admin` redireciona para o painel;
  - "Encerrar suporte" volta a `/admin/tenants`, e depois disso `/app` leva a `/admin`;
  - **0 erros de JavaScript**.

## 5. Publicação e deploy

### Git

- **VetorOS:**
  - `git status` revisado; todas as alterações eram desta entrega.
  - `secret-guard` e `gitleaks` 8.30.1, **nada encontrado**.
  - Commit **`1bd11b0c4c35ac293a97414207ddb409e1e241aa`** (33 arquivos).
  - Push `5ad1fa7f..1bd11b0c`.
  - **CI verde** (`https://github.com/brasilgb/vetoros/actions/runs/39070441887`: `test` e `secrets` success).
- **infra-abrasil:**
  - commit **`fd02834ef6b0f06ff4d575ca082bf3b3f012baaa`**, só com `docker-compose.yml` e `.env.example`;
  - push `abf4201..fd02834`; o repositório não tem CI.
  - **Não incluídos:** o ponteiro `gateway/vetoros` (fica para o deploy), as 29 alterações do desgarrados-next, `gateway/vetorleads/` e `gateway/desgarrados-old/` (outras frentes).

### Migrations acumuladas para a VPS (produção em `423195d9`)

São 20 commits e **6 migrations**, todas aditivas:
- `2026_10_15_120000_create_saas_commercial_contracts`
- `2026_10_16_120000_add_anchor_and_scheduled_revert_to_saas_contracts`
- `2026_10_16_130000_create_efi_saas_payments`
- `2026_10_16_140000_remove_provider_switch_from_saas_payment_settings` (o `dropColumn` é de tabela criada no mesmo lote)
- `2026_10_17_100000_create_standalone_invoices_table`
- **`2026_10_17_200000_create_support_sessions_table`** (nova; só cria `support_sessions` e `support_session_actions`)

### Antes do deploy

- **Senha do SMTP:** definir **`ABRASIL_MAIL_PASSWORD`** no `.env` do `infra-abrasil` na VPS. Sem ela, os e-mails da plataforma continuam sem enviar, como hoje.
- **Trava de banco:** confirmar que a VPS **não** define `DB_ALLOW_DESTRUCTIVE_COMMANDS`; em produção ela é ignorada de qualquer forma.

### Comandos (executar só com autorização, na VPS, em `/opt/infra-abrasil`)

```sh
# 0. Conferências (somente leitura)
git status --short && git -C gateway/vetoros status --short
git -C gateway/vetoros rev-parse --abbrev-ref HEAD   # deploy/vetor-intel-20261008
git -C gateway/vetoros log --oneline -1              # 423195d9
grep -c '^ABRASIL_MAIL_PASSWORD=.\+' .env            # 1 = senha definida
docker compose config --images | grep vetoros        # nomes das imagens para o rollback
df -h . && docker compose ps vetoros vetoros-worker vetoros-scheduler mysql

# 1. Backup do banco e das imagens
TS=$(date +%Y%m%d_%H%M%S)
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --events vetoros' > backups/vetoros_pre_deploy_$TS.sql
tail -1 backups/vetoros_pre_deploy_$TS.sql && grep -c "CREATE TABLE" backups/vetoros_pre_deploy_$TS.sql
for s in vetoros vetoros-worker vetoros-scheduler; do docker tag "$(docker compose images -q $s | head -1)" "vetoros-rollback-$s:pre-$TS"; done

# 2. Código (infra com o compose novo + VetorOS)
git pull --ff-only                                   # traz fd02834
git -C gateway/vetoros fetch origin && git -C gateway/vetoros merge --ff-only origin/main
git -C gateway/vetoros log --oneline -1              # 1bd11b0c ou o commit do relatório, que vem depois

# 3. Build e subida só do VetorOS (recria os containers com o ambiente novo)
docker compose build vetoros vetoros-worker vetoros-scheduler
docker compose up -d --no-deps vetoros vetoros-worker vetoros-scheduler

# 4. Migrations (6 pendentes)
docker compose exec vetoros php artisan migrate:status --pending
docker compose exec vetoros php artisan migrate --force
docker compose exec vetoros php artisan migrate:status --pending   # "No pending migrations"
docker compose exec vetoros php artisan optimize
docker compose restart vetoros-worker vetoros-scheduler

# 5. Validação
curl -s -o /dev/null -w '%{http_code}\n' https://vetoros.com.br/up
docker compose ps vetoros vetoros-worker vetoros-scheduler
docker compose logs --since 10m vetoros vetoros-worker vetoros-scheduler | grep -iE "error|exception" | tail
# SMTP institucional real (troque o destinatário por uma caixa interna):
docker compose exec vetoros php artisan tinker --execute='App\Support\TenantMailConfig::systemMailer()->raw("Teste do SMTP institucional (deploy STABILIZE-03).", fn ($m) => $m->to("contato@abrasilsistemas.com.br")->subject("Teste SMTP VetorOS"));'
```

- **Depois:** commitar **só** o ponteiro `gateway/vetoros` no `infra-abrasil` e dar push.
- **Validar manualmente:**
  - dashboard: abas Operacional, Financeiro, Vendas, Compras e Agendamentos;
  - "Acessar painel" numa empresa de teste: faixa, bloqueio de uma operação financeira e "Encerrar suporte";
  - registros em `support_sessions`.

### Rollback

- **Código:** `git -C gateway/vetoros reset --hard 423195d9` no branch de deploy; retag das imagens `vetoros-rollback-*:pre-$TS` para os nomes do passo 0; `docker compose up -d --no-deps --no-build vetoros vetoros-worker vetoros-scheduler`.
- **Compose:** `git revert fd02834` no `infra-abrasil`, se for preciso voltar o ambiente do e-mail.
- **Banco:** as migrations são aditivas e o código antigo convive com elas. Se for preciso desfazer o schema, **restaurar o dump**, porque `migrate:rollback` é recusado em produção pela trava da STABILIZE-01. Isso perde o que foi gravado depois do deploy e exige autorização.

## 6. Pendências

- **Autorizar o deploy:** 6 migrations, recriação dos containers do VetorOS e `ABRASIL_MAIL_PASSWORD` na VPS.
- **Confirmar na VPS** o envio pelo SMTP da Hostinger (comando do item 5).
- **Recomendação de infraestrutura:** trocar `env_file: ./gateway/vetoros/.env.example` por um arquivo de ambiente fora do repositório.
- **Ampliar a lista de correções do Modo Suporte** (`config/support.php`) somente com decisão explícita.
