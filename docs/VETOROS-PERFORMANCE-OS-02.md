# VETOROS-PERFORMANCE-OS-02 — Desempenho das operações de OS

Data: 13/11/2026 · Ambiente de trabalho: checkout local (`main` em `d3d58c5f`), sem acesso à VPS.
**Status: implementado e validado localmente; não publicado, sem commit e sem deploy.**

## 1. Causa-raiz (com evidência)

O benchmark (`tests/Feature/Performance/OrderOperationsBenchmarkTest.php`) mostrou que o tempo
não estava no banco: com 500 OS, a listagem levava ~4,9 s, dos quais só ~7 ms eram SQL.

| # | Causa | Onde | Efeito medido |
|---|-------|------|---------------|
| 1 | **bcrypt por linha.** O accessor `Order::publicAccessKey` decifra a chave pública e confere com `Hash::check` (bcrypt, `BCRYPT_ROUNDS=12` em produção, ~0,25 s). A listagem lê a chave de todas as linhas da página (`public_access_key_value`, usada no botão de WhatsApp). | `OrderController@index`, `@show` | Listagem: 20 × ~0,25 s ≈ **5 s** em qualquer tamanho de tenant. Abertura da OS: +~0,3 s. |
| 2 | **Listas que crescem com o histórico** enviadas no carregamento: `Order::distinct()->pluck('model')` (não usado pelo frontend), todas as OS com garantia ativa e todas as OS em andamento do tenant (filtradas no navegador). | `@create`, `@show` | Formulário novo: 63 ms / 186 KB (500 OS) → **881 ms / 2,0 MB** (10.000 OS). |
| 3 | **N+1 em `others` e `order_budgets`.** Por linha: prazo de follow-up, financeiro habilitado e janela de feedback (consultas a `others`) e `budgetPendingSince()` (1–2 consultas para OS com orçamento gerado). Também havia lazy-load de pagamentos. | `@index` | 52–104 consultas por página → **20** fixas. |

Itens investigados e descartados como causa:

- **Índices MySQL.** Em MariaDB 11.8 com 30.000 OS no tenant, `ORDER BY id DESC LIMIT 25` (com e sem filtro de status) levou 0,5–3,4 ms e `COUNT(*)` 6 ms; os índices existentes (`orders_tenant_*`, `order_status_history (order_id, created_at)`, `order_payments (order_id, paid_at)`, `order_logs (order_id, created_at)`) atendem as consultas. **Nenhum índice novo foi criado.**
- **Salvar/editar/status.** Ficam entre 12 e 37 ms com 18 a 57 consultas e não crescem com o volume. A transação cobre só o necessário para consistência (OS, estoque, itens, totais, recebível e comissão).
- **Pesquisas assíncronas.** Clientes (`customers/search`) e equipamentos do cliente já usavam busca com `limit 20` e debounce.

Pontos fora do escopo do benchmark (exigem produção):

- **E-mail síncrono.** Ao criar uma OS ou mudar o status, o e-mail ao cliente é enviado via SMTP dentro da requisição (`SendOrderCreatedNotification`/`SendOrderStatusUpdatedNotification` chamam `deliver*` direto). Com SMTP lento, isso soma segundos. Já existem os jobs `App\Jobs\SendOrder*Notification` (`ShouldQueue`), mas o repositório não tem configuração de worker de fila (`QUEUE_CONNECTION=database`; nenhum `queue:work` em `deploy/`, `Dockerfile` ou `docker-entrypoint.sh`). **Não alterei** isso: enfileirar sem worker confirmado faria os e-mails pararem sem aviso. Ver pendências.
- **bcrypt no cadastro.** `Hash::make` da chave pública custa ~0,25 s por OS nova em produção (no teste roda com 4 rounds). É necessário para a segurança e foi mantido.

## 2. Alterações

**Backend**

- `app/Models/App/Order.php`: a verificação da chave pública guarda em cache (30 dias) **só o resultado positivo**, sob `sha256(texto cifrado | hash)`. Trocar a chave muda as duas partes e invalida a entrada. No cache ficam só um digest e `true`, nada secreto. Há proteção para falha do cache. O novo `rememberPublicAccessKeyVerified()` é chamado no cadastro e na regeneração da chave.
- `app/Models/App/Other.php`: `financeEnabled`, `communicationFollowUpCooldownDays`, `customerFeedbackRequestDelayDays` e `recordsPerPage` passam a ser lidos uma vez por requisição/job (`RequestCache`, `scoped`). Gravar via Eloquent invalida a leitura.
- `app/Http/Controllers/App/OrderController.php`:
  - `index`: `withBudgetPendingSince()` na própria consulta; total pago só por `withSum` (sem carregar todos os pagamentos); `buildPaymentSummary` usa o agregado quando existe.
  - `create`/`show`: removido `models` (DISTINCT não usado); `warrantySourceOrders`/`activeOrders` deixam de ir no carregamento; equipamentos, peças e técnicos com só as colunas usadas.
  - Novo `contextOptions` (`GET app/orders/context-options`, rota `app.orders.context-options`): devolve as OS de garantia e as OS em andamento **do cliente e equipamento escolhidos**, com limite de 50. Usa `authorize('viewAny')` e `TenantRule::exists('customers')` (cliente de outro tenant → 422), e as OS em andamento seguem `scopeOrdersQuery` (técnico sem "ver todas" só vê as próprias). As OS de garantia não são restritas por técnico, como antes; a validação no servidor (`warrantySourceOrder`) não mudou.

**Frontend**

- `resources/js/hooks/use-order-context-options.ts`: busca sob demanda com debounce de 250 ms, cancela a requisição anterior (`AbortController`) e, em caso de falha, segue sem as sugestões.
- `create-order.tsx` e `edit-order.tsx`: consomem o hook. Layout e fluxos sem mudança; o filtro por cliente/equipamento no navegador foi mantido.

**Paginação.** Já era paginação real no servidor. Mantive a configuração **por tenant** (`records_per_page`, padrão 20, opções 10/20/35/50/100) em vez de impor 25: não dá para distinguir quem escolheu 20 de quem nunca mudou, e sobrescrever a escolha mudaria o comportamento. O `COUNT(*)` do paginador custa 6 ms com 30.000 OS (MySQL), então não justifica trocar a paginação numerada.

## 3. Antes / depois

Medições no processo (kernel HTTP de teste, SQLite em memória, PHP 8.4, 7 execuções + 1 de aquecimento). Os tempos cobrem banco, PHP e serialização da página completa; não incluem rede nem navegador. As chaves usam bcrypt com custo 12, como em produção.

| Cenário | 500 OS antes → depois | 2.000 OS antes → depois | 10.000 OS antes → depois (p95) |
|---|---|---|---|
| Listar (p50) | 4.854 → **43 ms** | 5.981 → **60 ms** | 10.012 → **204 ms** |
| Listar por status | 4.864 → 42 ms (104 → 20 consultas) | 5.229 → 48 ms | 7.495 → 68 ms |
| Listar com busca | 4.907 → 40 ms | 5.994 → 53 ms | 6.076 → 86 ms |
| Abrir OS simples | 370 → **26 ms** (315 → 115 KB) | 382 → 29 ms | 590 → 48 ms |
| Abrir OS pesada¹ | 431 → **86 ms** (475 → 275 KB) | 646 → 84 ms | 659 → 229 ms |
| Formulário nova OS | 63 → **18 ms** | 207 → 30 ms | 1.039 → **47 ms** (2.063 → 91 KB) |
| Buscar cliente / equipamento | 3 / 2 ms | 4–6 / 3–7 ms | 4 / 2 ms |
| Salvar nova OS | 12 → 12 ms | 16 → 32 ms² | 26 → 109 ms² |
| Editar e salvar | 14 → 16 ms | 13 → 34 ms² | 20 → 13 ms |
| Mudar status | 24 → 24 ms | 29 → 25 ms | 32 → 291 ms² |

¹ OS com 30 peças, 300 históricos, 300 logs, 300 mensagens e 15 orçamentos de 10 itens.
² Escrita sem mudança de código; a variação vem de oscilação do SQLite em memória e da máquina (p50 entre 13 e 37 ms).

Todas as metas do correio (p95 < 800 ms para listagem, abertura e formulário; < 1.000 ms para salvamento simples) foram atingidas **neste ambiente**. Isso não é medição em produção: o MySQL real, a rede e o tráfego simultâneo precisam ser confirmados na VPS (ver pendências).

Saída bruta: `docs/audits/performance-os-02/before.txt` e `after.txt` (comando no cabeçalho do teste de benchmark).

## 4. Testes

- `tests/Feature/App/OrderPerformanceTest.php` (9 testes): número de consultas da listagem constante com 3 e 18 linhas; `total_paid` e alerta de cobrança preservados; verificação da chave reaproveitada sem bcrypt e invalidada quando o hash muda; OS nova legível sem bcrypt; formulário sem listas de histórico; `context-options` filtrado por cliente e equipamento, recusando cliente de outro tenant e respeitando a visibilidade do técnico; memória de `others` invalidada ao gravar. Rodado 12 vezes seguidas sem falha.
- `tests/Feature/Performance/OrderOperationsBenchmarkTest.php`: benchmark, ignorado na suíte normal sem `BENCH_ORDERS`.
- Suíte completa: **910 aprovados, 1 ignorado, 1 falha pré-existente**: `Tests\Unit\Security\SecretGuardTest` reprova a alteração local em `.env.example` (credenciais reais do banco), feita antes desta tarefa e fora dela. Não foi tocada.
- `npm run types`, `npm run build`, ESLint nos arquivos alterados, Pint e `git diff --check`: aprovados.
- Removido o `tests/Feature/App/OrdersListBenchTmpTest.php` (benchmark temporário antigo, não versionado e quebrado), substituído pelo novo.

## 5. Riscos e rollback

- **Cache da verificação da chave.** Em produção `CACHE_STORE=database`, então cada linha faz uma leitura por chave primária na tabela `cache` (barata). Após o deploy, as OS antigas pagam o bcrypt uma vez na primeira exibição (a primeira abertura de cada página da listagem pode levar ~5 s) e depois ficam em cache. Se o cache for limpo, a primeira exibição volta a pagar esse custo.
- **`RequestCache` em `others`.** Gravar via `DB::table('others')` não invalida a leitura da própria requisição. Não há esse uso no código da aplicação; em FPM a memória termina com a requisição.
- **Busca sob demanda no formulário.** Se `context-options` falhar, o formulário funciona, mas sem o aviso de duplicidade e sem a lista de OS de garantia. A validação no servidor não mudou.
- **Rollback.** Não há migration nem mudança de dados. Basta reverter os arquivos listados na seção 2. As entradas `order-public-key-verified:*` no cache são inofensivas e expiram sozinhas.

## 6. Pendências

1. **Commit/deploy:** não feitos. O deploy em produção exige autorização explícita.
2. **Validação em produção:** medir p50/p95 reais (FPM + MySQL) da listagem, abertura e formulário depois do deploy e conferir `slow_query_log`, `max_connections` e `pm.max_children` (10, segundo `deploy/php/php-fpm.d/zz-vetoros-pool.conf`) sob carga real.
3. **E-mail síncrono:** confirmar se existe worker de fila na VPS. Se existir, trocar os listeners para despachar `App\Jobs\SendOrderCreatedNotification`/`SendOrderStatusUpdatedNotification`, que já são `ShouldQueue` e reaproveitam o registro em `order_messages`. As mensagens "falha ao enviar e-mail" da tela passariam a não ser imediatas. Decisão de produto e infraestrutura.
4. **Medição no navegador:** tempo percebido e renderização (React) não foram medidos; o benchmark cobre só o backend.
5. **`.env.example`:** a alteração local com credenciais reais faz o `SecretGuardTest` falhar e não deve ser commitada.
