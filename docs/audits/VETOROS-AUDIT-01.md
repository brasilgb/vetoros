# VETOROS-AUDIT-01 — Auditoria geral técnica, funcional e de segurança

Data: 20/10/2026
SHA-256 de `correio.md`: `dfdde47abc8be889ded155af140f924da7c3d86d0ed9010113507eb48aae81c5`
Base: branch local (HEAD `953b90a8`) **com as alterações não commitadas da VETOROS-EFI-TEST-01** presentes na árvore de trabalho.
Modo: diagnóstico. Nenhum código de produção foi alterado, nenhum dado real foi tocado e nenhuma chamada externa foi feita. As provas de conceito rodaram como testes descartáveis, fora do repositório, em SQLite `:memory:`.

---

## 1. Resumo executivo

O núcleo do VetorOS é maduro. A suíte tem 825 testes, todos aprovados; os webhooks são autenticados com comparação em tempo constante; as credenciais ficam criptografadas no banco; o Modo Suporte é restrito; e as operações de estoque e caixa usam transações e `lockForUpdate`. A auditoria encontrou, porém, **dois problemas críticos de isolamento entre empresas** e um conjunto de falhas de exposição de dados e de controle de acesso que devem ser corrigidos antes de qualquer expansão comercial.

| Severidade | Qtde | Destaques |
|---|---|---|
| Crítico | 2 | Escrita entre tenants em Orçamentos (mass assignment de `tenant_id`, **reproduzido**); assinaturas e fotos de OS em pastas compartilhadas entre empresas |
| Alto | 4 | Página pública da OS expõe senha do aparelho, custos e notas internas (com a chave de acesso desligada, que é o padrão); usuário "Inativo" continua entrando; `/api/loginuser` sem limite de tentativas; venda aceita total arbitrário |
| Médio | 6 | Orçamentos sem autorização por perfil; tokens da API sem expiração; corrida no cancelamento de venda; módulo Serviços sem tabela; agendamentos em UTC; dependências npm vulneráveis |
| Baixo | 6 | Rotas registradas duas vezes; `TenantSequence` O(n); dívida de lint/estilo; `Model::unguard()` global; arquivos obsoletos; avisos de PHPUnit 12 |

**Impacto comercial:** os dois críticos permitem que uma empresa cliente veja ou altere dados de outra empresa (assinatura e fotos de clientes finais; cadastro de orçamentos). Num SaaS multiempresa, isso é risco direto de LGPD e de reputação.

---

## 2. Componentes examinados

- **Autenticação e acesso:** `bootstrap/app.php`, middlewares `app`/`admin`/`root.admin`/`check.subscription`/`ApplySupportSession`/`EnsureTenantApiUser`, `LoginRequest`, login da API (`UserController::loginuser`), Sanctum.
- **Multi-tenant:** `app/Tenantable.php`, `Scopes/TenantScope.php`, `resolveCurrentTenantId()`, `TenantSequence`; varredura de todos os models (tenantables e filhos sem `tenant_id`).
- **Mass assignment:** varredura de `create/update/fill` com `$request->all()` em todos os controllers de `App/` e `Admin/`.
- **Webhooks:** Efí Pix/Cobranças, Spedy (Standard Webhooks HMAC), WAHA (HMAC-SHA512), integração CRM.
- **Rotas públicas:** portal da OS (`/os/{token}`), NFS-e compartilhada (links assinados), feedback.
- **Uploads:** `ImageController` (web e API), `PartController`, `OrderSignature`.
- **Financeiro e pagamentos:** `SaasPaymentService`, `SaasCheckoutController` (verificação de dono do pagamento), escopo da Efí, `AccountReceivablePaymentService`, `ReceivablePaymentChannel`.
- **Operação e estoque:** `SaleService` (criação e cancelamento), `OrderPartsService`, exclusão de OS e de cliente.
- **Fiscal:** bloqueios entre fiscal e financeiro em venda e OS, guarda de credenciais Spedy, envio de certificado.
- **Infraestrutura:** agendador (`routes/console.php`), timezone, Dockerfile, segredos versionados, migrations.

---

## 3. Ferramentas efetivamente executadas

| Ferramenta | Resultado |
|---|---|
| `php artisan test` (suíte completa, SQLite em memória, `APP_KEY` descartável) | **825 aprovados, 0 falhas**, 5.817 asserções. Os 765 avisos vêm **só do ambiente**: falta `.env` (`file_get_contents(.env)` no phpdotenv), sem relação com o código. |
| Testes-prova descartáveis (4 arquivos, fora do repositório) | Confirmaram C-01, A-01, A-02, A-03 e A-04 (detalhes em cada item) |
| `vendor/bin/pint --test` | Falha: 47 arquivos fora do padrão (só estilo) |
| `npx tsc --noEmit` | 0 erros |
| ESLint | 453 problemas (442 erros, 11 avisos), sendo 401 de `no-explicit-any`, 36 de `no-unused-vars` e 10 de `react-hooks/exhaustive-deps` |
| Prettier `--check` | 2 arquivos fora do padrão (`InvoiceModal.tsx`, `edit-order.tsx`) |
| `npm run build` | Concluído (só o aviso de chunk grande) |
| `composer audit` | Nenhuma vulnerabilidade |
| `npm audit` | 25 no total (2 críticas, 15 altas); 19 em `dependencies` (detalhes em M-06) |
| PHPStan/Larastan | **Não executado: não está instalado** no projeto. Não instalei sem autorização. |
| Testes de navegador/E2E | **Não executados:** não há `.env` nem banco seguro para subir a aplicação |
| ECC (agentes `security-reviewer` etc.) | **Não usados:** a criação de subagentes foi recusada nesta sessão; a revisão foi feita diretamente |

---

## 4. Problemas encontrados

Legenda: **CONFIRMADO** = reproduzido em teste ou caminho de código lido por inteiro; **HIPÓTESE** = precisa de verificação adicional.

### Críticos

#### C-01 — Usuário de uma empresa cria ou move Orçamentos para outra empresa (CONFIRMADO, reproduzido)

- **Evidência:**
  - `app/Providers/AppServiceProvider.php:56`: `Model::unguard()` global.
  - `app/Http/Controllers/App/BudgetController.php:188` e `:230`: `$data = $request->all();` (o `validated()` é chamado e descartado).
  - `BudgetController.php:191` faz `Budget::create($data)` e `:232` faz `$budget->update($data)`.
  - `app/Tenantable.php:22` só preenche `tenant_id` **se ele vier vazio**; um valor enviado pelo cliente é respeitado.
- **Reprodução:** um usuário do tenant 2 envia `POST /app/budgets` com os campos normais mais `tenant_id=1`.
  - Resultado: `store_status=302 created_tenant=1`.
  - `PUT /app/budgets/{id_próprio}` com `tenant_id=1` deu `update_status=302 own_tenant_after=1`.
- **Impacto:** escrita de dados em outra empresa, poluição ou sabotagem de cadastro e transferência de registros entre tenants. O mesmo padrão aceita qualquer coluna (`budget_number`, `created_at`…).
- **Agravante:** `BudgetController` não chama `authorize`/`Gate` em nenhuma ação (ver M-01).
- **Mesmo padrão:** `ServiceController.php:62/93`, hoje inoperante por falta de tabela (M-04). Os `Admin/*Controller` com `$request->all()` (Period, Feature, Branch) são restritos ao RootAdmin; o risco é baixo, mas o padrão é igual.

#### C-02 — Assinaturas e fotos de OS gravadas em pastas compartilhadas entre empresas (CONFIRMADO por leitura de código)

- **Evidência:**
  - `app/Support/OrderSignature.php:47-55` grava em `public/storage/orders/{order_number}/signature.png` (nome fixo).
  - `ImageController.php:49` usa a mesma pasta `storage/orders/{order_number}` para as fotos.
  - `order_number` é **sequencial por tenant** (`OrderController.php:716`, `TenantSequence::next(..., $tenantId)`), e a OS nº 15 existe em todas as empresas.
- **Condição de reprodução:**
  1. A empresa A colhe a assinatura da OS 15.
  2. A empresa B colhe a assinatura da sua OS 15: o arquivo é **sobrescrito**, e a OS da empresa A passa a mostrar a assinatura do cliente da empresa B.
  3. Antes de B assinar, `OrderSignature::exists()` já é verdadeiro para a OS 15 de B, então o recibo de B mostra a assinatura do cliente de A (`OrderController.php:491-492`, `ReceiptController.php:126`).
- **Outras consequências:**
  - As fotos usam `uniqid()`, mas o upload pela API usa `time().rand(1, 50).'.png'` (`ImageController.php:189`). Dois uploads no mesmo segundo para OSs de mesmo número, em empresas diferentes, colidem com probabilidade de 1/50 e um sobrescreve o outro.
  - Os arquivos ficam em `public/` **sem autenticação**, com URL previsível (`/storage/orders/15/signature.png`). As assinaturas de clientes finais de todas as empresas são enumeráveis variando só o número.
  - `deleteImageFile` (`ImageController.php:56`) também tenta apagar `storage/orders/{order_id}/{arquivo}`, outra pasta compartilhada.
- **Impacto:** vazamento e troca de dado pessoal biométrico-documental (assinatura), comprovante de recebimento inválido e LGPD.
- **Não verificado:** o estado das pastas em produção (não acessei o servidor). A correção exige migração dos arquivos existentes.

### Altos

#### A-01 — Portal público da OS envia senha do aparelho, custos e notas internas (CONFIRMADO, reproduzido)

- **Evidência:**
  - `OsController.php:96` faz `'order' => $order` (model inteiro) e `:70` carrega `with('customer')` sem restringir colunas.
  - `Order::$hidden` (`Order.php:31`) só oculta a chave de acesso.
  - A chave de acesso é opcional e **nasce desligada**: `public_order_access_key_required` tem `default(false)` (migration `2026_07_10_180000`).
- **Reprodução:** `GET /os/{tracking_token}` em empresa com a configuração padrão retorna 200 e `order_password_exposed=true`.
  - As props também levam `service_cost`, `manual_parts_cost`, `technician_observations`, `customer_feedback_recovery_notes`, `budget_follow_up_*`, `technician_local_payment_*` e, pelo `with('customer')`, todas as colunas do cliente.
- **Com a chave habilitada:** o acesso é bloqueado até a validação (`OsController.php:55-64`, com throttle 6/min em `/access`). Quem tem a chave, porém, continua recebendo os custos e as notas internas no JSON da página, mesmo que a tela não os mostre.
- **Classificação:**
  - **Alto** para empresas com a chave desligada (o padrão): o token é UUID, mas circula por WhatsApp e e-mail.
  - **Médio** (vazamento de dado interno ao próprio cliente) quando a chave está ligada.

#### A-02 — Usuário marcado como "Inativo" continua entrando na web e na API (CONFIRMADO, reproduzido)

- **Evidência:** `LoginRequest.php:45` usa `Auth::attempt` só com e-mail e senha; `UserController.php:236` (`loginuser`) também não verifica `status`. Nenhum middleware verifica `users.status`. A tela de usuários rotula o campo como "Ativo/Inativo".
- **Reprodução:** um usuário com `status=0` obtém `web_login_status=302 authenticated=true` e `api_login_status=200 token_issued=true`.
- **Impacto:** um funcionário desligado e marcado como inativo mantém o acesso. Os tokens Sanctum já emitidos também continuam válidos (M-02).

#### A-03 — `/api/loginuser` sem limite de tentativas (CONFIRMADO, reproduzido)

- **Evidência:** `routes/api.php:45` não tem `throttle`; o grupo `api` não ganhou `throttleApi()` em `bootstrap/app.php`; o controller não usa `RateLimiter`. O login web limita a 5 tentativas (`LoginRequest`).
- **Reprodução:** 15 senhas erradas seguidas resultam em `{"401":15}`, sem nenhum 429.
- **Impacto:** força bruta e preenchimento de credenciais (credential stuffing) contra contas de técnicos e administradores, contornando a proteção do login web.

#### A-04 — Venda no PDV grava o total enviado pelo navegador sem conferir com os itens (CONFIRMADO, reproduzido)

- **Evidência:** `SaleController.php:102` valida só `'total_amount' => 'required|numeric'`; `SaleService.php:33` usa esse valor como total. Os itens recebem `unit_price = sale_price`, mas a soma nunca é comparada ao total, e não existe campo de desconto nem trilha de auditoria de desconto.
- **Reprodução:** 2 peças de R$ 100,00 foram enviadas com `total_amount=0.01`; resultado: `status=200 stored_total=0.01`, estoque baixado de 10 para 8. Valor negativo é recusado (422).
- **Impacto:** venda abaixo do preço sem controle (fraude de operador), caixa e contas a receber divergentes da mercadoria baixada e margens erradas.
- **Observação:** pode ser um "desconto livre" intencional, mas hoje não há regra, limite por perfil nem registro do desconto.

### Médios

- **M-01 — Orçamentos sem nenhuma autorização por perfil (CONFIRMADO):**
  - `BudgetController` (index/store/update/destroy, `:240-245`) não chama `authorize`/`Gate`, enquanto os demais módulos usam policies.
  - Um técnico (role 3) pode excluir orçamentos. O isolamento por tenant se mantém no route binding, exceto pelo C-01.
- **M-02 — Tokens Sanctum sem expiração (CONFIRMADO):**
  - `config/sanctum.php:53` tem `'expiration' => null`, e o login cria um token novo a cada chamada sem revogar os antigos.
  - Combinado com A-02, desativar um usuário não encerra o acesso pelo app.
- **M-03 — Corrida no cancelamento de venda (CONFIRMADO por código, não reproduzido):**
  - `SaleService.php:85` verifica `status === 'cancelled'` **fora** da transação, e a venda não é travada; o lock em `:101` é só das peças.
  - Duas requisições simultâneas passam pela verificação e devolvem o estoque **duas vezes**, gerando dois `PartMovement` de retorno.
  - Não reproduzido: o SQLite não executa transações concorrentes no teste.
- **M-04 — Módulo "Serviços" sem tabela (CONFIRMADO):**
  - O model `App\Models\App\Service` usa a tabela `services`, que **não é criada por nenhuma migration** (varredura de todos os models: é a única).
  - Rotas `register-services` e páginas `resources/js/pages/app/services/*` existem, assim como `/api/servicos`.
  - Numa instalação nova, a tela quebra com erro de SQL. Se a tabela existe em produção (criada manualmente), o esquema não é reproduzível.
  - O controller também tem o padrão do C-01.
- **M-05 — Agendamentos interpretados em UTC (CONFIRMADO):**
  - `config/app.php:68` define `timezone => 'UTC'`, e `routes/console.php:13,17,21,41,45` agenda os follow-ups e as notificações em `dailyAt('09:00'/'10:00'/'08:00'/'11:00'/'11:30')` **sem** `->timezone()`. Só os contratos (07:00/07:30) definem `America/Sao_Paulo`.
  - Na prática, cobranças e pedidos de avaliação saem às 05:00–08:30 no horário de Brasília (09:00 UTC = 06:00 BRT).
- **M-06 — Dependências npm com vulnerabilidades conhecidas (CONFIRMADO, `npm audit`):**
  - Em `dependencies` (que vão para o bundle ou o runtime): `axios` e `lodash`/`lodash-es` (altas), além de `form-data` e `follow-redirects`.
  - Ferramentas de build declaradas como `dependencies` em vez de `devDependencies`: `vite`, `rollup`, `postcss`, `concurrently`/`shell-quote` (crítica).
  - Todas têm correção disponível. `composer audit` está limpo.
  - Não atualizei nada, conforme a restrição.

### Baixos

- **B-01 — Rotas registradas duas vezes:** `bootstrap/app.php:31-32` passa `web:` e `api:` ao `withRouting` **e** volta a registrar `routes/api.php` e `routes/web.php` no `then:` (`:37-43`). Hoje o efeito é nulo, porque a segunda definição sobrescreve a primeira, mas confunde e duplica o custo de boot.
- **B-02 — `TenantSequence::next` carrega todos os números do tenant** (`TenantSequence.php:27`, `pluck($column)->max()`) a cada nova OS ou venda. O custo cresce linearmente com o histórico; o certo é `max()` no banco. `sales_number`/`budget_number` não têm índice único por tenant (só `orders` tem).
- **B-03 — `Model::unguard()` global** (`AppServiceProvider.php:56`): é a causa-raiz do C-01. `UserController` (App e Admin) compensa com `reguard()`/`unguard()` em volta do `create`, o que mostra que o problema é conhecido, mas a solução é local e frágil.
- **B-04 — Dívida de qualidade:**
  - 47 arquivos fora do Pint;
  - 453 problemas de ESLint (401 de `any`);
  - 10 avisos de `react-hooks/exhaustive-deps` (possíveis estados desatualizados);
  - 2 arquivos fora do Prettier.
- **B-05 — Código obsoleto versionado:**
  - `public-old/` (19 arquivos, 1,5 MB);
  - `sophos-ca.crt` na raiz (certificado de CA de proxy corporativo, não é segredo, mas não pertence ao repositório);
  - `especificacoes.txt` e `VETOROS_AVALIACAO_TECNICA.md` soltos na raiz;
  - variáveis `MP_*` (Mercado Pago) em `phpunit.xml` sem uso no código.
- **B-06 — PHPUnit 12:** testes com metadados em doc-comment (`@test`), que deixam de funcionar no PHPUnit 12.

### Pontos verificados sem problema

- **Webhooks:**
  - Efí: segredo na URL com `hash_equals` (`EfiWebhookController.php:58`) e confirmação sempre por consulta à API.
  - Spedy: HMAC com timestamp e deduplicação.
  - WAHA: HMAC-SHA512 com `hash_equals`.
- **Pagamentos SaaS:** `status`/`refresh` verificam o dono (`SaasCheckoutController.php:150,161`). As rotas de Efí e fiscal central estão sob `root.admin`. Escopo da Efí confirmado: **é usada só nas assinaturas SaaS** (checkout, webhooks, `SaasContractService`, `ReconcileSaasPayments`); não há uso em recebimentos das empresas clientes.
- **Credenciais:** cast `encrypted` em Efí, Spedy, `FiscalSetting` (token, segredo de webhook, CSC). Nenhum segredo encontrado em `config/` ou em arquivos de chave versionados.
- **Uploads:** a regra `mimes` do Laravel 12 recusa nome `.php` com conteúdo PNG (`passes=false`), e a regra `image` recusa SVG por padrão. Não encontrei upload executável.
- **IDOR:** route binding com escopo de tenant (`Tenantable::resolveRouteBinding`), e os filhos sem `tenant_id` (`OrderPayment` etc.) são validados contra o pai (`OrderController.php:1272`).
- **Modo Suporte:** escrita limitada a uma lista de rotas, com auditoria por ação.
- **Integridade:**
  - OS com nota fiscal emitida não é excluída;
  - venda com NFC-e ativa não é cancelada;
  - cliente com vínculos não é excluído;
  - as baixas de estoque na venda usam `lockForUpdate`;
  - a OS tem índice único `(tenant_id, order_number)`.
- **XSS:** só 2 `dangerouslySetInnerHTML`; `chart.tsx` (shadcn) gera CSS próprio. **HIPÓTESE:** `help-search.tsx:118` renderiza `content` dos tópicos de ajuda, cadastrados só pelo RootAdmin; é risco baixo se o RootAdmin for confiável, mas recomenda-se sanitizar.

---

## 5. Impacto técnico e comercial (síntese)

| Item | Técnico | Comercial |
|---|---|---|
| C-01, C-02 | Quebra do isolamento entre tenants | Incidente LGPD; perda de confiança; possível obrigação de notificar a ANPD |
| A-01 | Exposição de credencial do aparelho e de dados internos | Reclamação de cliente final; margem e custo visíveis ao consumidor |
| A-02, A-03, M-02 | Controle de acesso ineficaz após desligamento; força bruta | Ex-funcionário com acesso a cadastro e financeiro |
| A-04, M-03 | Divergência entre estoque, caixa e financeiro | Perdas não rastreáveis; relatórios de margem errados |
| M-04, M-05 | Instalação nova quebrada; mensagens de madrugada | Suporte e imagem com o cliente final |

## 6. Riscos de segurança e perda de dados

- **Perda de dados:** C-02 (assinatura sobrescrita, sem versão anterior); C-01 (registro movido para outro tenant some da empresa de origem); M-03 (estoque inflado).
- **Exposição:** C-02 (assinaturas enumeráveis), A-01 (senha do aparelho, dados do cliente).
- **Acesso indevido:** A-02, A-03, M-02, M-01.

## 7. Funcionalidades incompletas

- Módulo **Serviços** (M-04): sem tabela.
- **Desconto no PDV** (A-04): não existe como conceito; o total é livre.
- **Status "Inativo"** de usuário (A-02): gravado, mas sem efeito no acesso.
- **Central Universal de Confirmação de Pagamentos:** viável.
  - Já existem o ponto de extensão `App\Services\Payments\ReceivablePaymentChannel` e a entrada idempotente `AccountReceivablePaymentService::register(..., source: integration, externalReference)`, documentados para provedores do tenant.
  - Hoje **nenhum** canal automático está implementado.
  - A infraestrutura da Efí não deve ser reaproveitada para os tenants sem uma separação explícita de credenciais por empresa, porque é a conta do SaaS.
- **Homologação real Efí** (vinda da EFI-TEST-01): pendente.

## 8. Código obsoleto e duplicidades

`public-old/`, `sophos-ca.crt`, variáveis `MP_*`, registro duplo de rotas (B-01), `ServiceController::getServicos` sobre tabela inexistente, `reguard`/`unguard` repetidos em `UserController` (App e Admin), e lógica de pasta de OS duplicada entre `ImageController` e `OrderSignature`.

---

## 9. Plano de correção por prioridade e dependência

| Ordem | Item | Ação proposta | Depende de |
|---|---|---|---|
| 1 | C-01 | `BudgetController`/`ServiceController` passam a usar `$request->validated()`; `Tenantable::creating` passa a **forçar** o tenant atual (ignorando valor enviado) quando houver tenant na sessão; teste de regressão cross-tenant | — |
| 2 | C-02 | Mover assinaturas e fotos para disco privado em `orders/{tenant_id}/{order_id}/`, servidas por rota autenticada ou assinada; script de migração dos arquivos atuais (com inventário e backup antes) | Decisão sobre o que fazer com assinaturas já sobrescritas |
| 3 | A-01 | Montar no `OsController` um payload explícito (lista de campos permitidos) para o portal; nunca enviar `password`, custos ou notas internas; avaliar ligar a chave de acesso por padrão em novos tenants | — |
| 4 | A-02, M-02 | Exigir `status=1` no login web/API e num middleware; revogar tokens ao inativar; definir expiração dos tokens | — |
| 5 | A-03 | `throttle` (ex.: 5/min por e-mail+IP) em `/api/loginuser` | — |
| 6 | A-04, M-03 | Recalcular o total no servidor; criar desconto explícito com limite por perfil e auditoria; travar a venda (`lockForUpdate`) e verificar o status dentro da transação no cancelamento | Regra de desconto definida pelo negócio |
| 7 | M-01 | Policy de Orçamentos | 1 |
| 8 | M-04 | Decidir: criar a migration de `services` (conferindo o esquema de produção) ou remover o módulo | Acesso ao esquema de produção |
| 9 | M-05 | `->timezone('America/Sao_Paulo')` nos comandos agendados (ou timezone da aplicação, avaliando o impacto nas datas gravadas) | — |
| 10 | M-06 | Atualizar `axios` e `lodash`; mover ferramentas de build para `devDependencies`; `npm audit fix` com build e testes | Autorização para atualizar dependências |
| 11 | B-01…B-06 | Remover o registro duplo de rotas; `max()` no banco em `TenantSequence` e índices únicos; remover `Model::unguard()` global (após 1, com varredura de `$fillable`); Pint/ESLint graduais; limpar obsoletos; migrar `@test` para atributos | 1 para o `unguard` |
| 12 | — | Instalar Larastan e rodar E2E num ambiente de homologação com `.env` e banco próprios | Autorização |

## 10. Testes executados e resultados

- **Suíte completa:** 825 aprovados, 0 falhas, 5.817 asserções, ~46 s. Os avisos vêm do ambiente (falta `.env`).
- **Provas descartáveis**, fora do repositório, removidas da árvore de trabalho, SQLite em memória:
  - `CrossTenantMassAssignmentProbeTest`: C-01 reproduzido (criação e atualização).
  - `AuthProbeTest`: A-02 (web e API) e A-03 (15×401, nenhum 429) reproduzidos.
  - `PublicOrderProbeTest`: A-01 reproduzido (`password` e custos nas props).
  - `SaleTotalProbeTest`: A-04 reproduzido (R$ 200,00 vendidos por R$ 0,01; estoque 10 → 8).
  - Verificação de upload: `teste.php` com conteúdo PNG é recusado pela validação.
- **Frontend:** `tsc` sem erros; build concluído. Os resultados de ESLint, Prettier e Pint estão na seção 3.
- **Dependências:** `composer audit` limpo; `npm audit` com 25 vulnerabilidades.

## 11. Limitações da auditoria

- Sem `.env`, banco ou servidor: **não houve testes de navegador nem E2E**. A interface (responsividade, modais, mensagens) não foi inspecionada visualmente, e o estado real de produção (pastas de C-02, tabela `services`) não foi verificado.
- PHPStan/Larastan não está instalado e não foi instalado.
- Os subagentes ECC não foram usados (criação recusada na sessão); a revisão foi manual, guiada por varreduras de padrões. Módulos extensos (fiscal Spedy completo, NF-e/NFC-e, contratos de manutenção, OS ponta a ponta, relatórios) foram examinados por amostragem, **não linha a linha**. A ausência de achados nessas áreas não comprova a ausência de problemas.
- A corrida de M-03 foi identificada por leitura, sem reprodução concorrente.
- As análises de fiscal e de Efí não fizeram chamadas reais (por restrição).
- A árvore de trabalho continha as alterações não commitadas da EFI-TEST-01, auditadas como parte do código atual.
