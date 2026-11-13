# VETOROS-PERFORMANCE-OS-03: consolidação, otimização e escalabilidade das OS

Data: 13/11/2026. Ambiente de trabalho: checkout local (`main` em `d3d58c5f`, com as alterações da OS-02 ainda não commitadas), MariaDB 11.8 local, PHP 8.4, 4 núcleos e 4 GB de RAM (WSL). **Sem acesso à VPS.**

**Status: implementado e validado localmente. Sem commit, sem push e sem deploy**, que dependem de autorização explícita.

As melhorias da OS-02 foram mantidas: paginação no servidor, eliminação de N+1, relacionamentos seletivos, consultas agregadas de pagamentos e orçamentos, OS de garantia e em andamento sob demanda, pesquisas assíncronas, isolamento por tenant e permissões. Os testes da OS-02 (`OrderPerformanceTest`) continuam aprovados.

## 1. Diagnóstico consolidado

| # | Problema | Evidência | Situação |
|---|---|---|---|
| 1 | **bcrypt por linha na listagem** quando o cache estava vazio. A OS-02 guardava o resultado em cache por 30 dias. Com o cache vazio (deploy, limpeza ou expiração), cada OS voltava a pagar um bcrypt de custo 12. | Sem a verificação gravada: ~300–400 ms por OS exibida pela primeira vez (medido no MariaDB). | **Corrigido**: verificação gravada no banco; não depende de cache. |
| 2 | **Listagem atravessando todos os tenants.** `WHERE tenant_id = ? ORDER BY id DESC LIMIT 20` usava a chave primária de trás para frente e percorria as OS de todos os tenants até encontrar as do tenant atual. Na OS-02 isso não apareceu, porque o teste usava um tenant só. | 50 tenants e 202,5 mil OS: consulta de 375–480 ms; listagem com p50 de 431–485 ms e p95 de até 2,5 s, mesmo com **um único usuário**. Com índice `(tenant_id, id)`: **1,4 ms**. | **Corrigido** (índice novo). |
| 3 | **Número de OS duplicado em criações simultâneas.** `TenantSequence::next` rodava fora da transação, então o `FOR UPDATE` não segurava nada e duas criações podiam receber o mesmo número. Além disso, a função trazia para o PHP **todos** os números de OS do tenant. | No teste de carga, 1 a 5 criações por rodada falharam com `Duplicate entry … orders_tenant_id_order_number_unique` (o usuário perde a OS digitada). | **Corrigido**: número reservado dentro da transação, calculado com `MAX` pelo índice e com até 3 tentativas em caso de deadlock. Depois da correção: 0 duplicidades com 15 criações simultâneas por tenant. |
| 4 | **E-mail SMTP síncrono** na criação da OS e na mudança de status. | Os listeners `SendOrder*Notification` chamavam `Mail::send` dentro da requisição. | **Removido**, por decisão de produto. |
| 5 | **Segredo no `.env.example`.** A alteração local continha usuário e senha reais do banco, e o `SecretGuardTest` falhava. | A mesma senha aparece em commits já publicados em `origin/main` (ver seção 4). | **Removido** do arquivo. **A rotação da senha fica pendente.** |
| 6 | **Capacidade do servidor.** Com 10 workers PHP em 4 núcleos, a saturação fica em ~30–36 req/s. O custo é CPU de PHP (serialização Inertia, mais o bcrypt de 0,3 s em cada OS criada), e não SQL. | Matriz de carga (seção 7). | Medido. Ver riscos. |
| 7 | **MySQL de produção sem ajuste.** O `docker-compose.yml` da infra não configura o MySQL 8.4. O `innodb_buffer_pool_size` fica no padrão de 128 MB, para os bancos de cinco aplicações. | A massa de teste ocupa 333 MB. Nos cenários de 50 tenants houve 245 mil leituras de disco do InnoDB e degradação forte com 50 e 100 usuários. | **Hipótese não validada.** Aumentar o buffer pool do MariaDB local foi bloqueado por ser um recurso compartilhado. Ver pendências. |

Itens investigados sem problema encontrado:

- **Middleware global (`HandleInertiaRequests`).** Os indicadores de follow-up usam `withBudgetPendingSince()` (sem N+1) e filtros por usuário. No perfil, cada requisição gasta poucos milissegundos nessas consultas.
- **Locks e transações.** No MariaDB: 3 esperas por lock, máximo de 87 ms e 0 deadlocks em toda a matriz. O pico de conexões simultâneas foi 13, contra um limite de 151.
- **Cache vazio vs. aquecido.** Mesmo resultado (listagem p50 de 92 e 89 ms). O único uso de cache relevante (`inertia:performance-alert`, 10 min) é barato.
- **Memória.** Pico de 68 a 85 MB por processo PHP (OS-02). Com 10 workers, cerca de 1 GB no pior caso.
- **Payload.** Listagem de 180–190 KB e abertura de 115–275 KB, sem crescer com o histórico (OS-02).

## 2. Correções realizadas

### 2.1 Chaves públicas sem bcrypt na listagem (seção 3 do correio)

- **Nova coluna `orders.public_access_key_check`** (`char(64)`, anulável). Guarda o HMAC-SHA256 da chave em claro e do hash bcrypt gravado, com segredo derivado da `APP_KEY`. Conferir custa microssegundos.
- **Leitura** (`Order::publicAccessKey`): decifra a chave e compara com `hash_equals`. Se a coluna estiver vazia ou não conferir, faz o bcrypt **uma vez** e grava a verificação, sem eventos e sem alterar `updated_at`. A gravação só acontece se o hash continuar o mesmo, para não sobrescrever uma regeneração simultânea.
- **Escrita**: um hook `saving` grava a verificação quando chave e hash são escritos juntos (cadastro, regeneração e contrato de manutenção) e a anula quando só um dos dois muda.
- **O cache de 30 dias da OS-02 foi removido** (`rememberPublicAccessKeyVerified` e as chaves `order-public-key-verified:*`).
- **Comando `vetoros:backfill-order-public-key-checks`** (`--tenant`, `--limit`, `--chunk`): grava a verificação das OS antigas. Pode ser interrompido e retomado, e nunca exibe chaves.

### 2.2 E-mails automáticos (seção 4)

- Removidos os disparos de `OrderCreated` no cadastro e no contrato de manutenção, e de `OrderStatusUpdated` na mudança de status. Os eventos e os listeners `SendOrderCreatedNotification`/`SendOrderStatusUpdatedNotification` foram apagados.
- **"Enviar atualização ao cliente"** continua funcionando, agora chamando `OrderNotificationService::sendStatusUpdated` diretamente. Também continuam os lembretes de cobrança e o follow-up de orçamento por e-mail, os templates e envios de WhatsApp e os links públicos.
- Os jobs `App\Jobs\SendOrderCreatedNotification` e `SendOrderStatusUpdatedNotification` **foram mantidos, mas não enviam mais nada**: só registram no log e descartam. Assim, jobs antigos ainda na fila ou reprocessados de `failed_jobs` não enviam e-mail inesperado. Nenhum código os despachava desde o commit `4b4fd6f7` (06/07/2026).
- Histórico (`order_status_history`), eventos de ciclo de vida e auditoria não mudaram.
- `OrderNotificationService::sendCreated/deliverCreated` foram removidos, por falta de uso. A classe `OrderCreatedMail` permanece.
- A tela de SMTP (`others/index.tsx`) não fala mais em "notificações de status". Agora informa que a abertura e a mudança de status não enviam e-mail.
- SMTP global, filas e notificações fiscais, financeiras e administrativas não foram alterados.

### 2.3 Escalabilidade multi-tenant

- **Índice `orders_tenant_id_id_idx (tenant_id, id)`**, na mesma migration. `ADD INDEX` é online no InnoDB.
- **`TenantSequence`**: para `orders.order_number`, que é inteiro e tem índice único `(tenant_id, order_number)`, usa `MAX()` com `FOR UPDATE`. As colunas de texto (`messages.message_number`, `parts.part_number`) continuam com o cálculo em PHP.
- **`OrderController@store`**: a reserva do número passou para dentro da transação, com até 3 tentativas. O bcrypt da chave pública ficou fora, para não prolongar a trava.

### 2.4 Segurança (seção 7)

- `.env.example`: `DB_DATABASE=vetoros`, `DB_USERNAME=vetoros` e `DB_PASSWORD=` vazio. O `SecretGuardTest` voltou a passar. Em produção, `DB_*` vem do bloco `environment` do compose, que tem precedência sobre o `env_file`, então a mudança não afeta o container.
- `public_access_key_check` entrou em `Order::$hidden` e em `SensitiveData::KEYS`.

## 3. Arquivos modificados

**Novos**

- `database/migrations/2026_11_13_100000_add_public_access_key_check_to_orders.php`
- `app/Console/Commands/BackfillOrderPublicKeyChecks.php`
- `tests/Feature/App/OrderPerformanceOs03Test.php` (11 testes)
- `scripts/performance/os03-seed.php` (massa de carga; só roda em banco com "perf" no nome)
- `scripts/performance/os03-loadgen.php` (carga HTTP; só roda contra localhost)
- `docs/audits/performance-os-03/` (resultados brutos)

**Alterados**

- `app/Models/App/Order.php`
- `app/Http/Controllers/App/OrderController.php`
- `app/Services/OrderNotificationService.php`
- `app/Services/MaintenanceContractService.php`
- `app/Providers/EventServiceProvider.php`
- `app/Jobs/SendOrderCreatedNotification.php`
- `app/Jobs/SendOrderStatusUpdatedNotification.php`
- `app/Support/TenantSequence.php`
- `app/Support/SensitiveData.php`
- `resources/js/pages/app/others/index.tsx`
- `.env.example`
- `tests/Feature/App/OrderControllerTest.php`
- `tests/Feature/App/OrderCommunicationTest.php`
- `tests/Feature/App/TenantSequenceTest.php`

**Removidos**

- `app/Events/OrderCreated.php`
- `app/Events/OrderStatusUpdated.php`
- `app/Listeners/SendOrderCreatedNotification.php`
- `app/Listeners/SendOrderStatusUpdatedNotification.php`

## 4. Segurança das chaves públicas

- O **acesso público não mudou**: `OsController::authorizeAccess` continua conferindo a chave digitada com bcrypt, e a regeneração continua invalidando a chave anterior (coberto por teste).
- A verificação rápida é um HMAC com segredo derivado da `APP_KEY`. Quem altera o banco sem a `APP_KEY` não consegue forjá-la. Copiar a chave cifrada e a verificação de outra OS não passa, porque o hash é diferente (testado). Uma verificação inválida cai no bcrypt e é corrigida (testado).
- Se a `APP_KEY` for rotacionada, as verificações deixam de conferir. Cada OS volta a pagar o bcrypt uma vez e é regravada, sem perda de acesso.
- **OS com chave divergente do hash**, por dado corrompido, pagam bcrypt a cada exibição, como antes, e continuam registrando o aviso no log. O backfill as conta separadamente.
- **Credencial exposta:** a senha do banco que estava no `.env.example` local aparece nos commits `22a5a86a` (`.env.example`, 04/04/2026) e `1032bcd9` (`executed.md`, 18/09/2026), ambos já em `origin/main`. Ela não existe no `HEAD` e é a mesma do `.env` local. O `.env` da infra não foi encontrado localmente, então **não deu para confirmar se a produção usa a mesma senha.** Recomendação: **rotacionar a senha em todo banco onde ela for usada.** Limpar o histórico do Git só faz sentido junto com a rotação e exige autorização. O valor não aparece neste relatório.

## 5. Remoção dos e-mails automáticos

| Caminho | Antes | Depois |
|---|---|---|
| Cadastro de OS (`store`) | E-mail síncrono, com aviso "falha ao enviar o e-mail" | Nenhum e-mail |
| Mudança de status (`update`) | E-mail síncrono | Nenhum e-mail; histórico, ciclo de vida e auditoria preservados |
| OS gerada por contrato de manutenção | E-mail síncrono | Nenhum e-mail; a NFS-e e a cobrança do contrato seguem com envio próprio |
| "Enviar atualização ao cliente" (manual) | Via evento | Chamada direta, com o mesmo comportamento |
| Jobs antigos na fila ou em `failed_jobs` | Enviariam | São descartados sem enviar |

Para conferir na VPS antes do deploy (somente leitura):

```sql
SELECT queue, COUNT(*), MIN(FROM_UNIXTIME(created_at)) FROM jobs GROUP BY queue;
SELECT COUNT(*) FROM failed_jobs
 WHERE payload LIKE '%SendOrderCreatedNotification%' OR payload LIKE '%SendOrderStatusUpdatedNotification%';
```

Não é preciso limpar filas: os jobs antigos viram descarte. Evite `queue:retry all` sem olhar o conteúdo.

## 6. Redis e filas (constatado no repositório; VPS não acessada)

- **Redis 7** (`infra-abrasil/docker-compose.yml`), **compartilhado por 5 aplicações**, sem senha, sem `maxmemory` e sem política de remoção configurada. Cada app tem `REDIS_PREFIX` próprio (`vetoros_`, `vetorpet_`…), mas todos usam os mesmos bancos lógicos 0 e 1.
- **VetorOS:** `CACHE_STORE=database`, `QUEUE_CONNECTION=database` e `SESSION_DRIVER=database`. **Hoje o VetorOS não usa Redis.**
- **Workers:** `vetoros-worker` (`queue:work database --sleep=3 --tries=3 --timeout=90`) e `vetoros-scheduler` (`schedule:work`). Não há Supervisor; o reinício fica por conta do Docker (`restart: unless-stopped`). Os agendamentos são diários, a cada 30 minutos (conciliação Efí) e a cada 10 minutos (Spedy).
- **PHP-FPM:** `pm.max_children=10` e `pm.max_requests=500`. **Opcache** com `validate_timestamps=0`.
- **Isolamento de cache por tenant:** as chaves usadas incluem o tenant (`inertia:performance-alert:{tenant}`, `tenant-mail:{tenant}`).
- **Não foi preciso migrar para Redis.** Com a verificação gravada no banco, as telas de OS não dependem de cache, e o cache vazio teve o mesmo desempenho do aquecido. Se no futuro o cache for para o Redis, é preciso um banco lógico próprio para o VetorOS (`REDIS_CACHE_DB`), porque `cache:clear` no Redis executa `FLUSHDB` e **apagaria o cache das outras quatro aplicações**.
- Não foi possível medir CPU, memória e jobs pendentes ou falhos na VPS, por falta de acesso.

## 7. Resultados antes e depois

### 7.1 Ambiente de carga

- Banco isolado `vetoros_perf_os03` (MariaDB 11.8, buffer pool padrão de 128 MB): **50 tenants e 202.500 OS** (17 tenants com 500, 17 com 2.000 e 16 com 10.000), 406 mil históricos e 406 mil logs.
- Servidor embutido do PHP com **10 workers**, igual ao `pm.max_children=10` da produção, com opcache e config, rotas e eventos em cache, em **4 núcleos**, mesmo número de vCPUs da KVM 4.
- Banco, aplicação e gerador de carga dividiram a mesma máquina, com 4 GB de RAM contra 16 GB da VPS.
- Cada usuário virtual fez login real e teve sessão própria. Mistura de operações: listar 25%, filtrar por status 8%, pesquisar 8%, abrir OS 20%, abrir OS pesada 4%, formulário de nova OS 5%, buscar cliente 5%, link público 7%, criar OS 5%, editar e salvar 8%, mudar status 5%.
- O envio real de WhatsApp não entrou na carga, porque chama a WAHA externa. Ele está coberto pelos testes funcionais com WAHA simulada.
- **Percentis:** p50 e p95 calculados sobre todas as amostras de cada operação; nenhuma média de percentis.

### 7.2 Antes e depois das correções (1 usuário, tenant de 10 mil OS, 50 tenants no banco)

| Operação | Antes (sem índice) | Depois |
|---|---|---|
| Consulta SQL da listagem | 375–480 ms | **1,4 ms** |
| Listagem (p50 / p95) | 485 / 615 ms (até 2,5 s no tenant de 2.000 OS) | **89 / 110 ms** |
| Pesquisa (p50 / p95) | 552 / 816 ms | **~95 / 140 ms** |
| Abrir OS simples (p50) | — | 73–75 ms |
| Listagem com cache vazio vs. aquecido (p50) | — | 92 vs. 89 ms (sem diferença) |
| OS sem verificação gravada (antes do backfill) | bcrypt por OS a cada cache vazio | bcrypt **uma vez por OS**; 300–400 ms só na primeira exibição |

### 7.3 Matriz de carga: usuários com 2 s de pausa entre ações (uso realista)

p95 em ms. "Listar" é o pior entre listar, filtrar e pesquisar. "Abrir" é o pior entre OS simples e pesada.

| Tenants | Usuários | req/s | Listar | Abrir | Form. nova | Criar | Editar |
|---|---|---|---|---|---|---|---|
| 10 | 10 | 4,7 | 417 | 247 | 310 | 645 | 389 |
| 10 | 30 | 14,2 | 729 | 260 | 132 | 536 | 396 |
| 10 | 50 | 22,8 | 504 | 506 | 341 | **1.645** | 388 |
| 10 | 100 | 29,4 | **3.713** | **3.174** | **2.421** | **4.018** | **3.562** |
| 20 | 10 | 4,8 | 227 | 154 | 107 | 435 | 267 |
| 20 | 30 | 14,3 | 178 | 151 | 93 | 564 | 185 |
| 20 | 50 | 23,2 | 478 | 376 | 297 | **1.108** | 392 |
| 20 | 100 | 33,1 | **2.590** | **2.416** | **1.894** | **3.699** | **1.895** |
| 30 | 10 | 4,6 | 349 | 201 | 260 | 390 | 179 |
| 30 | 30 | 14,1 | 342 | 375 | 93 | **1.153** | 159 |
| 30 | 50 | 23,2 | 497 | 266 | 133 | **1.274** | 194 |
| 30 | 100 | 33,6 | **1.894** | **2.220** | **1.497** | **2.842** | **1.726** |
| 50 | 10 | 4,8 | 207 | 385 | 123 | 402 | 121 |
| 50 | 30 | 14,1 | 323 | 250 | 240 | 417 | 184 |
| 50 | 50 | 19,4 | **4.039** | **1.536** | **1.357** | **1.727** | 866 |
| 50 | 100 | 12,0 | **13.431** | **10.040** | **10.591** | **10.507** | **9.899** |

Estresse (50 tenants, sem pausa, cada usuário dispara a próxima requisição assim que recebe a anterior): com 10 usuários, listagem p95 de 2,6 s e 6,3 req/s; com 30, 2,0 s e 27,7 req/s; com 50, 7,0 s; com 100, 6,0 s e 30 req/s. É um limite superior de carga, não uso real.

Leitura dos resultados:

- **Até 30 usuários ativos simultâneos**, as metas de listagem, abertura e formulário (p95 < 800 ms) foram atingidas em todas as combinações de 10 a 50 tenants. A criação passou de 1.000 ms uma vez (30 tenants, 1.153 ms).
- **Com 50 usuários**, listagem e abertura ficaram dentro da meta com 10, 20 e 30 tenants, mas a **criação** ficou entre 1,1 e 1,7 s. Com 50 tenants houve degradação geral, compatível com o buffer pool de 128 MB (dados maiores que a memória do InnoDB). A hipótese não foi validada (seção 9).
- **Com 100 usuários**, a capacidade satura em ~30–34 req/s (10 workers em 4 núcleos), e o tempo vira fila: p95 de 2 a 4 s, e ~10 s com 50 tenants.
- **Criar OS** custa ~300 ms de p50 já sem carga, por causa do `Hash::make` de custo 12 da chave pública, mantido por segurança.
- Os "erros" de mudar status (1 a 4 por rodada, HTTP 303) são transições recusadas pela validação do status sorteado pelo gerador (por exemplo, OS entregue). Não houve erro no log. Os erros de criar OS (HTTP 302) nesta matriz eram as duplicidades de número, corrigidas depois.

Revalidação da numeração (2 tenants, 30 usuários sem pausa, ~59 criações simultâneas): **0 duplicidades e 0 erros no log**.

Backfill: **~0,32 s por OS** em um núcleo (200 OS em 65 s), ou ~54 min para 10 mil OS. Pode rodar em paralelo com `--tenant`.

Saída bruta em `docs/audits/performance-os-03/`. Comandos:

```bash
DB_DATABASE=vetoros_perf_os03 php artisan migrate --force
DB_DATABASE=vetoros_perf_os03 php scripts/performance/os03-seed.php --tenants=50 --manifest=/tmp/m.json
# servidor: PHP_CLI_SERVER_WORKERS=10 php -d opcache.enable_cli=1 -S 127.0.0.1:8099 (router do Laravel)
php scripts/performance/os03-loadgen.php --base=http://127.0.0.1:8099 --manifest=/tmp/m.json \
    --tenants=50 --users=30 --duration=40 --think=2000 --out=res.jsonl
```

## 8. Testes

- **`OrderPerformanceOs03Test` (11 testes):**
  - listagem sem bcrypt com cache vazio;
  - OS criada pelo formulário já sai com a verificação gravada;
  - OS legada paga bcrypt uma vez e não altera `updated_at`;
  - verificação copiada de outra OS é recusada;
  - verificação forjada cai no bcrypt e é corrigida;
  - trocar só o hash anula a verificação;
  - a regeneração gera nova verificação e a chave antiga para de abrir o link público;
  - a coluna nunca é serializada;
  - o backfill preenche e conta as divergências;
  - criar OS não envia e-mail;
  - jobs legados são descartados sem enviar.
- **`TenantSequenceTest`:** novo teste do `MAX` por tenant. Os testes de colunas de texto continuam passando.
- **Ajustados:** `OrderControllerTest` (mudança de status não envia e-mail; o envio manual continua) e `OrderCommunicationTest` (orçamento gerado sem e-mail automático; o envio manual fica ligado à versão do orçamento).
- **Suíte completa: 923 aprovados, 1 ignorado (benchmark), 0 falhas.** O `SecretGuardTest` voltou a passar.
- **Demais verificações:**
  - `npm run types`, `npm run build` e Pint: aprovados.
  - ESLint em `others/index.tsx`: 3 erros de `any` que já existem no `HEAD`; nenhum vem desta alteração.
  - `git diff --check`: aprovado, exceto pelos espaços finais do próprio `correio.md`.
- **Concorrência:** matriz de 16 cenários mais 4 de estresse, além da revalidação da numeração (seção 7).

## 9. Riscos e pendências

1. **Commit e deploy:** não feitos. Precisam de autorização.
2. **Ordem de deploy:**
   1. Rodar a migration (coluna anulável e índice online).
   2. Publicar o código. O entrypoint roda `php artisan optimize`, que refaz o cache de eventos.
   3. **Reiniciar `vetoros-worker`**, porque o `queue:work` mantém o código antigo em memória.
   4. Rodar `php artisan vetoros:backfill-order-public-key-checks` fora do pico. Pode ser por tenant, e o sistema funciona sem ele: cada OS paga o bcrypt só na primeira exibição.
3. **Rotacionar a senha do banco** exposta no histórico do Git (seção 4).
4. **Buffer pool do MySQL** (padrão de 128 MB para 5 bancos). A hipótese é forte, mas **não foi medida**. Validar na VPS (`SHOW GLOBAL STATUS LIKE 'Innodb_buffer_pool_read%'`) e, se confirmar, avaliar algo como 2–4 GB num servidor de 16 GB. A mudança é no `docker-compose.yml` da infra, compartilhado com outras aplicações, e precisa de avaliação e autorização.
5. **Capacidade:** acima de ~30 req/s, o gargalo é CPU de PHP. Opções:
   - avaliar `pm.max_children` contra a memória real da VPS;
   - reduzir o custo do `Hash::make` da chave pública (decisão de segurança; hoje custo 12);
   - reduzir o payload Inertia da listagem.

   Nada disso foi alterado.
6. **Números locais, não de produção:** a VPS tem 16 GB e CPU possivelmente diferente, e o FPM não é o servidor embutido do PHP. Confirmar p50/p95 em produção com tráfego real. Não rodar carga agressiva na VPS.
7. **Envio manual por e-mail de "Enviar atualização ao cliente"** continua síncrono (decisão do usuário a cada clique). O mesmo vale para cobrança e follow-up manuais.
8. **`TenantSequence`** só ganhou o caminho por `MAX` para OS. Clientes, peças etc. continuam com o cálculo em PHP e fora de transação em alguns fluxos. Isso é fora do escopo das OS e fica como candidato.
9. **Banco local `vetoros_perf_os03`** (333 MB) foi mantido para reprodução. Pode ser removido com `DROP DATABASE vetoros_perf_os03`.

## 10. Plano de rollback

- **Código:** reverter os arquivos da seção 3 (eventos e listeners voltam do Git). Os e-mails automáticos voltariam junto.
- **Banco:** `php artisan migrate:rollback --step=1` remove o índice e a coluna. Também é seguro **manter** os dois com o código antigo: o código antigo ignora a coluna, e o índice só ajuda.
- **Sem perda de dados:** nenhuma chave, hash ou número de OS existente é alterado. A verificação é derivada e pode ser recalculada.
- Os jobs legados, que só descartam, voltam a enviar e-mail se o código antigo for restaurado. Conferir `jobs` e `failed_jobs` antes de reverter.

## 11. Veredito técnico

**Aprovado para deploy, condicionado à autorização, à rotação da senha exposta e à validação em produção.**

- O bcrypt por linha foi eliminado sem depender de cache.
- Os e-mails automáticos de OS foram removidos, sem tocar nos envios manuais, no WhatsApp e nos outros módulos.
- Foram corrigidos dois problemas que só aparecem com muitos tenants ou concorrência real: a varredura de todos os tenants na listagem (de ~480 ms para 1,4 ms de SQL) e a duplicidade de número de OS em criações simultâneas.
- Com até 30 usuários ativos, todas as metas de leitura foram atingidas com 10 a 50 tenants e 202 mil OS. Com 50 usuários, a criação de OS passa de 1 s.
- Com 100 usuários simultâneos, a configuração atual (10 workers, 4 núcleos) satura. Para isso é preciso capacidade ou ajuste de infraestrutura (buffer pool do MySQL e FPM), não mais correção de código das OS.
