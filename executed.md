# VETOR-STABILIZE-02 — Correção do dashboard e preparação de deploy. Resultado: **CORRIGIDO, TESTADO E PUBLICADO (`21a8b330`, CI VERDE); DEPLOY PREPARADO, NÃO EXECUTADO**

Data: 2026-10-17. Executor: estação de desenvolvimento local (WSL). Nenhum container foi reiniciado e nenhum banco de produção foi acessado.

## Comparação do correio

O `correio.md` mudou desde a execução anterior (VETOR-UX-MONEY-02, aprovada). A instrução atual é a **VETOR-STABILIZE-02**. O relatório anterior foi removido deste arquivo e está no histórico do git.

## 1. Correção do gráfico do dashboard

**Causa** (`components/Charts/chart-area-dashboard.tsx`):
- `setLines(response.data.lines)` gravava o que viesse. Com `lines` ausente, ou com a API devolvendo HTML (por exemplo, uma página de login), `lines.forEach` quebrava a página.
- Erro de rede virava uma promessa rejeitada sem tratamento.

**Correção:**
- **`resources/js/lib/equipment-chart.ts`** (puro, testado no Node):
  - `parseEquipmentChart`: `lines`/`data` ausentes, `null` ou vazios viram "sem dados"; itens malformados são descartados.
  - Resposta que não é objeto JSON, ou com `lines`/`data` de tipo errado, é **erro**, e não vira um gráfico vazio silencioso.
  - `buildEquipmentBars`: a mesma regra de barras de antes (soma por equipamento, agrupa nomes repetidos, ignora zerados).
- **No componente:** estados de **carregando**, **erro** (mensagem e "Tentar novamente", sem valores fictícios), **vazio** ("Sem equipamentos recebidos no período") e **gráfico**, preservado quando há dados. Respostas atrasadas de um período anterior são descartadas.
- **`components/Charts/chart-load-state.tsx`:** carregando/erro compartilhados.
- **Mesmo defeito, na mesma aba (operacional)**, achado na validação no Firefox:
  - **Fluxo de Ordens:** quebrava com `orders.reduce is not a function`.
  - **Orçamentos:** mostrava "0 gerados / 0 aprovados" quando a API falhava.
  - Os dois receberam a mesma validação e os mesmos estados.
- **Não alterado:** backend, regras financeiras, fiscais e de assinatura.
- **Outros painéis com o mesmo padrão** (`setX(response.data)` sem validação): abas Financeiro (ordens e vendas), Compras e Agendamentos. Não apresentaram erro e ficaram como estão (item 6).

## 2. Testes

- **Novo `tests/js/equipment-chart.test.ts`:** 6 testes, que cobrem:
  - resposta vazia (`{lines: [], data: []}` e `{}`);
  - incompleta (sem `lines`, `lines: null`, `data: null`, itens malformados);
  - inválida (HTML, `null`, número, lista, tipos errados), que é **erro**;
  - válida, preservando os dados;
  - montagem das barras.
- **Novos testes PHP** em `DashboardControllerTest`:
  - `chartEquipments` sem equipamento marcado devolve `lines: []` e os dias;
  - com equipamento e OS, devolve a linha e a contagem diária.
- **Resultados:**
  - PHP: `php artisan test --parallel` → **786 passaram** (5456 asserções).
  - JavaScript: `npm run test:js` → **19 passaram** (13 de valores + 6 do gráfico).
  - `tsc --noEmit` sem erros e `npm run build` ok.
  - Prettier, ESLint (nenhum problema novo) e Pint ok.
- **Testes visuais** no Chromium e no Firefox, com Playwright contra servidor isolado e SQLite descartável. Respostas simuladas com interceptação da API no navegador.

| Gráfico | Válida (API real) | Vazia | Incompleta / tipo errado | HTML | Erro 500 | "Tentar novamente" |
|---|---|---|---|---|---|---|
| Equipamentos recebidos | barras | estado vazio | estado vazio (sem `lines`, `lines: null`) | erro | erro | volta a mostrar as barras |
| Fluxo de Ordens | gráfico | estado vazio | erro (objeto no lugar da lista) | erro | erro | volta a mostrar o gráfico |
| Orçamentos | gráfico | estado vazio | — | erro | erro | volta a mostrar o gráfico |

Erros de JavaScript na página: **0** nos dois navegadores. Antes da correção, o Firefox registrava `orders.reduce is not a function`.

## 3. Git

- **`git status` revisado.** O Prettier tinha reformatado `chart-quality-trend.tsx`, que não tem relação com esta entrega; o arquivo foi restaurado (`git checkout`) antes do commit.
- **Verificação de segredos** nos arquivos em stage: `secret-guard` e `gitleaks` 8.30.1, **nada encontrado**.
- **Commit:** `21a8b33079260031a549940bc1b6293248ad02d4`, "Corrige o erro fatal do dashboard quando os gráficos recebem dados ausentes ou inválidos (VETOR-STABILIZE-02)", com 7 arquivos.
- **Push:** `origin/main` `e4718500..21a8b330`.
- **CI** (`https://github.com/brasilgb/vetoros/actions/runs/39067655929`): **`test` success** e **`secrets` success**.
- O commit deste relatório contém só `correio.md` e `executed.md`.

## 4. Preparação da VPS (sem executar nada em produção)

### Situação conferida

- **Host e caminho:** `srv1990862`, em `/opt/infra-abrasil` (último registro de deploy, de 2026-10-15).
- **Submódulo `gateway/vetoros`:**
  - URL: `https://github.com/brasilgb/vetoros.git`, branch `main` (`.gitmodules`).
  - Na VPS, o submódulo fica no branch local `deploy/vetor-intel-20261008`, que acompanha `origin/main` e avança com `--ff-only`.
- **Código hoje em produção:** VetorOS **`423195d9`** (deploy de 15/10).
- **Remoto:** `origin/main` do VetorOS em `21a8b330` (mais o commit deste relatório). São 18 commits à frente de `423195d9`.
- **Ponteiro no `infra-abrasil`:** local e remoto apontam para `2e518224`; **ainda não aponta para `21a8b330`**.
- **Alterações locais no `infra-abrasil`** (nesta estação): 29 arquivos do `desgarrados-next` e `gateway/vetorleads/` (não versionado), de outras frentes. **Não incluir** no deploy nem no commit do ponteiro.
- **Serviços** (`docker-compose.yml`):
  - `vetoros`, `vetoros-worker` e `vetoros-scheduler`, construídos de `./gateway/vetoros`;
  - banco no serviço `mysql` (`mysql:8.4`, volume `./volumes/mysql`, banco `vetoros`);
  - arquivos públicos em `./volumes/vetoros`.

### Migrations

- **Desta entrega (STABILIZE-02): nenhuma.**
- **Para levar a `main` atual à VPS**, porém, entram as **5 migrations** das entregas anteriores ainda não implantadas:
  - `2026_10_15_120000_create_saas_commercial_contracts`
  - `2026_10_16_120000_add_anchor_and_scheduled_revert_to_saas_contracts`
  - `2026_10_16_130000_create_efi_saas_payments`
  - `2026_10_16_140000_remove_provider_switch_from_saas_payment_settings`
  - `2026_10_17_100000_create_standalone_invoices_table`
- **Todas aditivas:**
  - O `dropColumn('active_provider')` atua numa tabela criada pela migration anterior do mesmo lote, então não há dado de produção nela.
  - `payments.gateway` só muda o valor padrão; as linhas existentes não mudam.
- **Validação anterior:** na STABILIZE-01, as cinco foram aplicadas sem erro em **MySQL 8.4.11** sobre o dump da VPS.

### Ambiente (`.env`)

- **`.env.example` em produção:** o serviço `vetoros` usa `env_file: ./gateway/vetoros/.env.example`, então esse arquivo **entra no ambiente de produção**. Entre `423195d9` e a `main`:
  - saíram `MP_*`, `MAIL_HOST/PORT/USERNAME/PASSWORD/ENCRYPTION/FROM_ADDRESS` e `ADMIN_EMAIL`;
  - entraram `ABRASIL_MAIL_*`.
  - **O código não lê `ABRASIL_MAIL_*`**: `config/mail.php` usa `MAIL_*`.
- **Atenção (situação que já existia):** hoje a produção recebe `MAIL_HOST=127.0.0.1` e `MAIL_PORT=1025`, valores de exemplo. Depois do deploy, as `MAIL_*` deixam de vir. Nos dois casos, **o e-mail de sistema da plataforma não está configurado na VPS**. O deploy não piora isso, mas vale conferir, antes ou depois, os avisos por e-mail da assinatura e da NFS-e do SaaS. O e-mail de cada tenant (SMTP próprio, em Sistema e módulos) não é afetado.
- **Efí:** as credenciais ficam no banco (RootAdmin), e não no `.env`. O Mercado Pago foi retirado do código; as variáveis `MP_*` que o compose ainda passa são ignoradas.
- **Trava de banco:** confirmar que a VPS **não** define `DB_ALLOW_DESTRUCTIVE_COMMANDS`. Em produção ela é ignorada de qualquer forma (`DatabaseSafety`).

### Backup e rollback (plano)

- **Antes de tudo:**
  - dump do banco `vetoros` com `--single-transaction --routines --triggers --events`, conferindo o "Dump completed" e o número de tabelas;
  - **tag das imagens atuais** de `vetoros`/`vetoros-worker`/`vetoros-scheduler`, para voltar sem rebuild.
- **Rollback de código:** voltar o submódulo para `423195d9` e subir com as imagens marcadas (`--no-build`).
- **Rollback de banco:** as migrations são aditivas e o código antigo convive com tabelas e colunas novas, então em geral **não é preciso desfazer o schema**. Se for preciso, **restaurar o dump**, porque `migrate:rollback` é recusado em produção pela trava da STABILIZE-01. Isso perde o que foi gravado depois do deploy e exige autorização específica.

### Comandos preparados (executar só com autorização, na VPS, em `/opt/infra-abrasil`)

```sh
# 0. Conferências (somente leitura)
git status --short && git -C gateway/vetoros status --short
git -C gateway/vetoros rev-parse --abbrev-ref HEAD   # deploy/vetor-intel-20261008
git -C gateway/vetoros log --oneline -1              # 423195d9
df -h . && docker compose ps vetoros vetoros-worker vetoros-scheduler mysql

# 1. Backup do banco e das imagens
TS=$(date +%Y%m%d_%H%M%S)
docker compose exec -T mysql sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --events vetoros' > backups/vetoros_pre_deploy_$TS.sql
tail -1 backups/vetoros_pre_deploy_$TS.sql            # "-- Dump completed ..."
grep -c "CREATE TABLE" backups/vetoros_pre_deploy_$TS.sql
for s in vetoros vetoros-worker vetoros-scheduler; do img=$(docker compose images -q $s | head -1); docker tag "$img" "vetoros-rollback-$s:pre-$TS"; done

# 2. Código
git pull --ff-only
git -C gateway/vetoros fetch origin && git -C gateway/vetoros merge --ff-only origin/main
git -C gateway/vetoros log --oneline -1              # 21a8b330 ou o commit do relatório, que vem depois

# 3. Build e subida só do VetorOS
docker compose build vetoros vetoros-worker vetoros-scheduler
docker compose up -d --no-deps vetoros vetoros-worker vetoros-scheduler

# 4. Migrations (5 pendentes)
docker compose exec vetoros php artisan migrate:status --pending
docker compose exec vetoros php artisan migrate --force
docker compose exec vetoros php artisan migrate:status --pending   # "No pending migrations"
docker compose exec vetoros php artisan optimize
docker compose restart vetoros-worker vetoros-scheduler

# 5. Validação
curl -s -o /dev/null -w '%{http_code}\n' https://vetoros.com.br/up      # 200
docker compose ps vetoros vetoros-worker vetoros-scheduler              # healthy
docker compose logs --since 10m vetoros vetoros-worker vetoros-scheduler | grep -iE "error|exception" | tail
```

Depois, no `infra-abrasil`, commitar **só** o ponteiro: `git add gateway/vetoros && git commit -m "Atualiza ponteiro do VetorOS (…)" && git push`, sem incluir as alterações do desgarrados.

```sh
# Rollback de código (se necessário)
git -C gateway/vetoros checkout deploy/vetor-intel-20261008 && git -C gateway/vetoros reset --hard 423195d9
for s in vetoros vetoros-worker vetoros-scheduler; do docker tag "vetoros-rollback-$s:pre-$TS" "$(docker compose config --images | grep -m1 "$s")"; done
docker compose up -d --no-deps --no-build vetoros vetoros-worker vetoros-scheduler
```

O passo das tags depende de como as imagens se chamam na VPS (`docker compose config --images`). Conferir os nomes no passo 0 antes de usar.

## 5. Validação pós-deploy sugerida

- `/up`, `/login`, dashboard (os três gráficos da aba operacional), Sistema e módulos (bloco da licença), nota avulsa em homologação (`docs/homologacao-fiscal-avulsa.md`), campos de valor (por exemplo, caixa e OS).
- **Confirmar com o RootAdmin que a Efí está configurada:** credenciais, certificado, ambiente e webhook. Sem isso, o painel de pagamento mostra "Pagamento online indisponível".

## 6. Pendências

- **Autorizar** o deploy (com as 5 migrations acumuladas) e o commit do ponteiro no `infra-abrasil`.
- **E-mail de sistema da plataforma em produção** (item 4, Ambiente): definir `MAIL_*` reais pelo compose ou pelo `.env` do `infra-abrasil`.
- **Abas Financeiro, Compras e Agendamentos do dashboard:** mesma validação de resposta, numa frente própria, se desejado.
