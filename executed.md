# VETOR-STABILIZE-01 — Proteção dos bancos e consolidação fiscal. Resultado: **PROTEÇÕES IMPLEMENTADAS E COMPROVADAS; ENTREGAS CONSOLIDADAS EM COMMITS LOCAIS; SEM PUSH, SEM DEPLOY**

Data: 2026-10-17. Executor: estação de desenvolvimento local (WSL).

## Comparação do correio

O `correio.md` mudou desde a execução anterior (VETOR-FISCAL-AVULSA-01). A instrução atual é a **VETOR-STABILIZE-01**. O relatório anterior foi removido deste arquivo; o histórico dele está no commit `c9e90cfb` e no git.

## 1. Proteção dos bancos

### Auditoria

- **`phpunit.xml`:**
  - Definia sqlite `:memory:`, mas sem `force`.
  - Um cache de configuração (`bootstrap/cache/config.php`) fazia o Laravel ignorar o arquivo. Foi o que apagou o MySQL local.
- **`.env.testing`:** não existia. Os testes carregavam o `.env` operacional, com as credenciais reais (Spedy, WAHA, SMTP, AWS).
- **`tests/TestCase.php`:** tinha a trava criada no incidente (aborta com cache, ambiente diferente de `testing` ou banco que não seja sqlite em memória).
- **Comandos destrutivos:** nenhuma trava em `db:wipe` ou `migrate:fresh`/`refresh`/`reset`/`rollback`.
- **`docker-entrypoint.sh`:** roda `php artisan optimize` ao subir o container de produção, com o `.env` de produção. Isso está correto. Nenhum container local monta este repositório. As caches locais de hoje vieram de comandos manuais, e há um `php artisan serve` e um `vite` rodando desde 09:02/09:24.
- **`composer test`:** já roda `config:clear` antes dos testes. O `php artisan test` direto não rodava.

### Proteções implementadas (commit `37c4f0d8`)

1. **Trava de comandos destrutivos** (`App\Support\DatabaseSafety`, aplicada no `AppServiceProvider` com `DB::prohibitDestructiveCommands`):
   - Bloqueia `db:wipe`, `migrate:fresh`, `migrate:refresh`, `migrate:reset` e `migrate:rollback`.
   - Só ficam liberados nos testes com sqlite `:memory:`, ou fora de produção com `DB_ALLOW_DESTRUCTIVE_COMMANDS=true` informado **na linha de comando**.
   - Em produção, nunca. Mesmo se um cache levar os testes ao MySQL, o ambiente deixa de ser `testing` e a trava vale.
2. **`phpunit.xml` blindado:**
   - `APP_ENV`, `DB_CONNECTION`, `DB_DATABASE` e `DB_ALLOW_DESTRUCTIVE_COMMANDS` são forçados em `$_ENV` **e** `$_SERVER`. O Laravel lê `$_SERVER` primeiro, e só o `<env force>` não bastava.
   - `APP_CONFIG_CACHE`, `APP_ROUTES_CACHE` e `APP_EVENTS_CACHE` apontam para `bootstrap/cache/testing/`, que nunca existe. Um `optimize`/`config:cache` local **não alcança a suíte**.
   - As credenciais operacionais (banco, Spedy, WAHA, Mercado Pago, AWS, SMTP, Redis) são forçadas como vazias.
3. **Configuração exclusiva dos testes:**
   - `.env.testing` local, com `APP_KEY` só de teste e sem credenciais. O Laravel o carrega no lugar do `.env`.
   - Esse arquivo é proibido no git pelo `secret-guard` e ficou no `.gitignore`. O modelo versionado é o `.env.testing.example` (cópia + `php artisan key:generate --env=testing`).
   - Sem o `.env.testing`, o `phpunit.xml` sozinho mantém o isolamento.
4. **`TestCase`:** a trava do incidente foi mantida, como terceira camada.

### Comprovação (sem operação destrutiva em banco real)

Todos os cenários usaram um banco-isca inexistente (`vetoros_guard_inexistente`). O banco real foi contado antes e depois: 5 tenants, 7225 clientes e 608 OS nos dois momentos.

| Cenário | Resultado |
|---------|-----------|
| `config:cache` no caminho padrão apontando para MySQL | Suíte ignora o cache e roda no sqlite em memória (4/4) |
| Shell com `DB_CONNECTION=mysql` (e também com `APP_ENV=local` + liberação) | Variáveis forçadas; roda no sqlite em memória (4/4) |
| `db:wipe`, `migrate:fresh/refresh/reset/rollback` em local, sem liberação | Todos recusados: "This command is prohibited" |
| `APP_ENV=production` com `DB_ALLOW_DESTRUCTIVE_COMMANDS=true` | Recusado |
| Local com liberação explícita | Liberado: tentou conectar ao banco-isca e falhou com "Unknown database" |
| Suíte sem `.env.testing` (clone novo) e com ele | 783 testes passam nos dois |

- Novo `tests/Feature/DatabaseSafetyTest.php` (4 testes):
  - banco isolado e caminho de cache próprio;
  - nenhuma credencial operacional;
  - matriz de ambientes e bancos;
  - comandos proibidos recusados sem tocar no banco.

## 2. Recuperação e integridade

- **Backups preservados** (não alterados; SHA-256 registrado):
  - `~/Documentos/vetoros-new.sql` (2026-09-20, MySQL 8.4.11 da VPS) — `8b7e59d9…485540`
  - `~/Documentos/localhost.sql` (2026-09-19, multibanco) — `b6dec608…4d75992`
  - `~/Documentos/vetoros-antes-da-restauracao-20261017-095302.sql` (estado logo antes da restauração) — `8bbe8a55…18d7e9`
  - Há também `~/Documentos/users_202610171011.sql`, uma exportação criada às 10:11, fora desta execução. Não foi tocada.
- **Mecanismos de recuperação no servidor local:**
  - É **MariaDB 11.8.6** (não MySQL).
  - **binlog desligado** (`log_bin=OFF`): não há recuperação pontual.
  - Os bancos `vetorosnew` (idêntico ao dump de 20/09) e `vetoros2` (vazio, criado às 09:14) não têm dados mais novos.
  - Nenhum outro dump do VetorOS posterior a 20/09 foi encontrado na pasta pessoal.
- **Contagens:**
  - As 68 tabelas do dump batem linha a linha com o banco restaurado.
  - Únicas diferenças: `migrations` (+19, as aplicadas depois) e `cache` (+2, dados de execução).
  - Há 19 tabelas a mais, criadas pelas migrations posteriores.
- **Estrutura:** comparada coluna a coluna e índice a índice com um banco descartável no próprio MariaDB, com todas as migrations aplicadas do zero (`vetoros_schema_check`, removido depois). Os índices são idênticos. Divergências de colunas:
  - `schedules.details` está `NOT NULL` no banco restaurado (assim veio da VPS) e nullable nas migrations. É uma **divergência que já existe na produção**; não foi corrigida aqui.
  - A tabela `users` do banco local tem **duas colunas espúrias**, `INSERT INTO sigmaos.users (tenant_id` e `updated_at) VALUES`, ambas vazias. Elas **não existem no dump**: a tabela foi recriada às 10:12–10:13, fora desta execução, logo após a exportação das 10:11, provavelmente por uma importação em ferramenta de banco. Os 15 usuários batem com o dump. **Correção pendente de autorização:** `ALTER TABLE users DROP COLUMN ...` nas duas colunas.
- **Dados não recuperados:** tudo o que foi criado no banco local `vetoros` entre 2026-09-20 e 2026-10-17 09:10. A última OS restaurada é de 18/09. As configurações recriadas às 09:25 de hoje (empresa emissora, Spedy, pagamentos) estão no backup "antes-da-restauração", se for preciso reaproveitá-las.
- **Nova restauração:** não foi feita nenhuma.

## 3. Consolidação das entregas

- **Alterações pendentes revisadas:** CONTRATO-02, EFI-02/03/04, UX-FISCAL-02 e UX-ADMIN-03 já estavam no commit `554e78df` e foram preservadas. O que estava pendente era só a FISCAL-AVULSA-01, agora no commit `c9e90cfb`.
- **Suíte completa:** `php artisan test --parallel` → **783 passaram** (5430 asserções).
- **Frontend:**
  - `tsc --noEmit`: sem erros.
  - ESLint e Prettier: ok.
  - `npm run build`: ok.
  - Pint: ok nos arquivos novos e alterados. Os apontamentos em `routes/app.php` e em modelos já existiam no commit anterior.
- **Migrations em MySQL 8.4.11** (container descartável, dados em memória, removido ao fim):
  - Do zero: todas aplicadas.
  - Rollback e reaplicação da migration nova: ok.
  - **Caminho real de atualização:** dump da VPS de 20/09 importado e as **19 migrations pendentes** aplicadas em ~5 s, sem erro. As contagens foram preservadas (5 tenants, 7225 clientes, 608 OS, 165 contas a receber, 7 documentos fiscais, 15 usuários).
- **Permissões, multitenant e financeiro das notas avulsas:** cobertos pelos 18 testes da `StandaloneInvoiceTest`:
  - sem permissão fiscal: nada é emitido nem buscado;
  - cliente, peça, reprocessar, descartar e busca de outro tenant: inacessíveis;
  - a mesma chave de solicitação em outro tenant não colide;
  - financeiro desligado: recusado;
  - recebimento exige caixa aberto;
  - autorização e cancelamento não mexem no financeiro;
  - duplicidade: um único lançamento.
- **Interface, desktop 1366 px e celular 390 px** (Playwright/Chromium contra servidor isolado na porta 8011 com sqlite descartável; nenhum banco real envolvido):
  - Fluxos percorridos: listagem, NFS-e (formulário e resumo), NF-e com dois produtos (um sem NCM/CFOP), recebimento já realizado, resumo e erro fiscal do servidor voltando ao formulário com os dados preservados.
  - Sem erro de console e sem rolagem horizontal da página.
  - **Corrigido:** na listagem em desktop, a coluna Ações (Reprocessar/Descartar) ficava fora da área visível, porque as células não quebravam linha. Origem, Vínculo e a mensagem de erro agora quebram linha.

## 4. Preparação da homologação Spedy

Roteiro completo em `docs/homologacao-fiscal-avulsa.md`, com pré-condições conferíveis, os 11 cenários do correio e critérios de aceite:
- NFS-e sem financeiro e com conta a receber;
- NF-e;
- NFC-e;
- recebimento com caixa aberto;
- rejeição e reprocessamento, descarte e cancelamento;
- PDF/XML;
- conciliação pelo `fiscal:sync-spedy`;
- duplicidade.

Nenhuma nota foi emitida nesta etapa.

## 5. Publicação

### Estado dos commits (branch local `vetor-stabilize-01`, criado a partir de `main` = `origin/main`)

| Commit | Conteúdo |
|--------|----------|
| `c9e90cfb` | Emite notas fiscais avulsas pela tela Notas Fiscais (VETOR-FISCAL-AVULSA-01) |
| `37c4f0d8` | Protege os bancos contra exclusão acidental e isola a configuração dos testes (VETOR-STABILIZE-01) |
| (último) | Registra o correio e o relatório de execução do VETOR-STABILIZE-01 |

**Sem push, sem merge na `main`, sem deploy.**

### Verificações de segurança

- **Repositório `brasilgb/vetoros` é PÚBLICO no GitHub.** Tudo o que for enviado fica visível, inclusive `correio.md` e `executed.md`. Este relatório não traz e-mails, credenciais nem dados de clientes.
- `scripts/security/secret-guard.php staged` rodou em cada commit: nada encontrado.
- O `gitleaks` não está instalado nesta máquina, então a segunda varredura do hook não rodou.
- O hook `pre-commit` do projeto não está ativo neste clone (`core.hooksPath` vazio). Para ativar: `git config core.hooksPath scripts/git-hooks`.
- Não entram no git: `.env`, `.env.testing` (com `APP_KEY` de teste), dumps `*.sql`. O `.env.testing.example` não tem chave.

### Checklist de publicação (para autorizar)

1. Revisar e aprovar os três commits do branch `vetor-stabilize-01`.
2. Na VPS, **antes de tudo**: `mysqldump` do banco de produção e conferência do arquivo.
3. Push do branch e merge na `main` (precisa de autorização específica).
4. Deploy da imagem. O entrypoint roda `optimize`; depois, `php artisan migrate --force`, com 19 migrations pendentes em relação ao dump de 20/09. Se a VPS já tiver recebido as anteriores, só a `2026_10_17_100000_create_standalone_invoices_table`. Conferir com `php artisan migrate:status`.
5. Confirmar que a VPS **não** tem `DB_ALLOW_DESTRUCTIVE_COMMANDS` no `.env`.
6. Homologação conforme `docs/homologacao-fiscal-avulsa.md`.

## 6. Migrations pendentes

- **Banco local `vetoros`:** nenhuma.
- **VPS:** desconhecido daqui. Em relação ao dump de 20/09 são 19, todas validadas em MySQL 8.4.11. A única desta frente é `2026_10_17_100000_create_standalone_invoices_table` (só cria tabela; rollback testado).

## 7. Condições objetivas para homologação na VPS

1. Backup verificado do banco da VPS tirado imediatamente antes do deploy.
2. Commits `c9e90cfb` e `37c4f0d8` publicados (após autorização) e `migrate:status` sem pendências.
3. `SPEDY_ENVIRONMENT=sandbox`, chave da conta sandbox e `SPEDY_WEBHOOK_SECRET` configurados; webhook `https://<domínio>/api/webhooks/spedy` cadastrado na Spedy sandbox.
4. Fila (`queue:work`) e agendador (`schedule:run`, com o `fiscal:sync-spedy` a cada 10 min) ativos.
5. Tenant exclusivo de homologação com:
   - empresa emissora na Spedy sandbox, ambiente Homologação e os três modelos liberados;
   - certificado A1 de teste, CSC de homologação e dados tributários confirmados;
   - financeiro ativo e caixa aberto;
   - `production_released_at` vazio.
6. Clientes (CPF e CNPJ) e peças de teste, incluindo uma com NCM inválido para o cenário de rejeição.

## 8. Pendências

- **Autorizar** a remoção das duas colunas espúrias de `users` no banco local.
- **Decidir** sobre `schedules.details` (`NOT NULL` na produção, nullable nas migrations).
- **Autorizar** push, merge e deploy (item 5).
- **Opcional:** ativar o hook de pre-commit e instalar o `gitleaks`.
