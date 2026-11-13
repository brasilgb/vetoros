# VETOROS-PERFORMANCE-OS-04: remoção da consulta pública de OS

Data: 13/11/2026. Ambiente: checkout local (`main` em `d3d58c5f`, com as alterações da OS-02 e da OS-03 ainda não commitadas), MariaDB 11.8 local, PHP 8.4, 4 núcleos (WSL). **Sem acesso à VPS.**

**Status: implementado e validado localmente. Sem commit, sem push e sem deploy**, que dependem de autorização explícita.

## 1. Auditoria inicial

### 1.1 Rotas públicas removidas (`routes/web.php`, sem autenticação)

| Rota | Nome | Função |
|---|---|---|
| `GET /os/{token}` | `os.token` | Página de acompanhamento (dados da OS, cliente, equipamento, fotos, pagamentos, orçamento) |
| `POST /os/{token}/access` | `os.access` | Validação da chave de 8 caracteres (bcrypt) |
| `POST /os/{token}/budget-status` | `orders.budget.status` | Aprovação/recusa do orçamento pelo cliente |
| `POST /os/{token}/acknowledge-notification` | `orders.notification.acknowledge` | Cliente confirma o aviso de conclusão |
| `POST /os/{token}/acknowledge-pickup` | `orders.pickup.acknowledge` | Cliente confirma a retirada (muda a OS para "Entregue") |
| `GET /os/{token}/receipt/{type}` | `os.receipt` | Recibos (entrega, orçamento, checklist) com nome, CPF/CNPJ, telefone e endereço do cliente |
| `GET /os/{token}/payment-proof` | `os.payment-proof` | Comprovante de pagamento |
| `GET /os/{token}/fiscal-proof` e `/pdf` | `os.fiscal-proof`, `os.fiscal-proof.pdf` | Comprovante e PDF da NFS-e |
| `POST /os/{token}/feedback` | `os.feedback.submit` | Avaliação do atendimento |

E, no painel: `POST app/orders/{order}/public-access-key/regenerate` (regeneração da chave por administrador).

### 1.2 Código, chaves e templates relacionados

- **Controller e apresentação:** `OsController` e `PublicOrderPresenter`; páginas `app/serviceorders/{index,access,payment-proof,fiscal-proof}.tsx`.
- **Chave pública:**
  - `orders.public_access_key`: cifrada com a `APP_KEY`.
  - `orders.public_access_key_hash`: bcrypt de custo 12.
  - Verificação rápida da OS-03: HMAC em `public_access_key_check`, nunca publicado.
  - Geração em `OrderController::store` e `MaintenanceContractService::createContractOrder`.
  - Leitura com recuperação no acessor `Order::publicAccessKey`, usado na listagem, na edição e no WhatsApp.
  - Comando `vetoros:backfill-order-public-key-checks` (OS-03), auditoria em `security:audit-app-key` e configuração `others.public_order_access_key_required`.
- **WhatsApp:**
  - A variável `{{ link_os }}` aparecia em 6 templates padrão: orçamento gerado, serviço concluído, feedback, mensagem padrão, cobrança de orçamento e saldo pendente.
  - Também estava no seeder e na tela de templates.
  - O botão de WhatsApp anexava a chave à mensagem quando o tenant exigia chave.
- **E-mails:** botões "Acompanhar" e o bloco da chave em `order-created`, `order-status-updated`, `order-budget-follow-up`, `order-payment-reminder` e `order-feedback-reminder`.
- **Painel:**
  - Item "Acompanhamento público" na listagem.
  - Botão de link na tela de follow-ups.
  - Diálogo "Acesso do cliente" (chave, link, mensagem pronta e regeneração) na edição da OS.
  - Opção "Solicitar chave de acesso" em Configurações.
- **Recibo impresso e PDF de OS aberta:** texto "Acompanhe o status em https://vetoros.com.br/os/{token}".
- **NFS-e da OS:** `NativeFiscalService` gravava em `orders.fiscal_document_url` o link **público** `/os/{token}/fiscal-proof/pdf`.
- **Payloads internos:** `ReportController` e `TechnicianScheduleController` (app do técnico) devolviam `tracking_token`, sem uso no frontend.

### 1.3 Dependências verificadas

| Área | Dependência da consulta pública | Situação após a remoção |
|---|---|---|
| Orçamento | Aprovação/recusa **pelo cliente** só existia pelo portal | A equipe registra a resposta do cliente mudando o status (fluxo interno já existente, com versão, ator "usuário" e auditoria) |
| Aviso de conclusão e retirada | Confirmações do cliente só pelo portal | A equipe muda para "Cliente avisado" e "Entregue" como antes |
| Feedback do cliente | Nota e comentário só pelo portal | **Não há mais canal para o cliente registrar nota.** O e-mail de lembrete pede resposta por e-mail. Os dados já gravados continuam |
| NFS-e da OS | PDF baixado pelo link público | Link passa à rota autenticada (`app.fiscal-documents.file`), igual à venda. O cliente recebe a nota pelos canais da Spedy/do emissor |
| Contratos | OS de contrato geravam chave (bcrypt) | Não geram mais. NFS-e de contrato usa link assinado próprio (`/nfse/...`), sem relação com o portal |
| Área do cliente | Não existe área do cliente separada; o "acesse sua área do cliente" do template de feedback era o próprio portal | Texto removido |
| WhatsApp/e-mail | Links do portal | Removidos (seção 2) |
| Fotos e assinatura | URLs assinadas e temporárias (`signed`); o portal era um dos emissores | Continuam, emitidas pelo painel e pela API do app |

### 1.4 Estado da OS-03

As alterações da OS-03 continuam **sem commit e sem deploy**. Itens dela afetados por esta etapa:

- **Coluna `public_access_key_check` e comando de backfill:** perderam o sentido. A migration da OS-03 foi reescrita para criar só o índice `(tenant_id, id)`; a coluna não é mais criada e o comando foi apagado. **O backfill não foi executado.**
- **Mantido da OS-03:**
  - índice `(tenant_id, id)`;
  - remoção dos e-mails automáticos;
  - correção da numeração concorrente;
  - limpeza do `.env.example`.

## 2. Alterações

### Backend

- `routes/web.php`: as 11 rotas `/os/...` foram substituídas por **uma** rota `Route::any('/os/{path}')`, que devolve **410** com uma página estática (`resources/views/os-unavailable.blade.php`).
  - A página **não consulta o banco** e não mostra dado da OS.
  - Também envia `X-Robots-Tag: noindex` e tem `throttle:30,1`.
  - Links antigos (WhatsApp, e-mails, recibos impressos) mostram "Consulta de ordem de serviço indisponível".
- Removidos `OsController`, `PublicOrderPresenter`, a rota e o método `regeneratePublicAccessKey` e o comando `BackfillOrderPublicKeyChecks`.
- **`Order`:** removidos o acessor `publicAccessKey`, o HMAC, o gancho `saving` e todo `Hash::check`/`Crypt::decrypt`. As colunas legadas e o `tracking_token` ficam em `$hidden`, nunca serializados.
- **Criação de OS** (`OrderController::store` e contratos): sem chave, sem `Hash::make` e sem `Crypt::encrypt`. O `tracking_token` continua sendo gerado (uuid, custo desprezível), porque a coluna é `NOT NULL UNIQUE`; não dá acesso a nada.
- **Listagem e edição:** saíram `public_access_key_value`, `publicAccessKey` e `publicAccessKeyConfigured` (antes eram decifrados e conferidos por OS).
- **`NativeFiscalService`:** o `fiscal_document_url` da OS passa a ser a rota autenticada da nota.
- **`security:audit-app-key`:** não audita mais `orders.public_access_key`. A opção `--verify-hash` saiu. SMTP e Spedy continuam auditados.
- **Configurações:** `public_order_access_key_required` saiu do formulário, da validação e do cast. A coluna fica no banco.
- `ReportController` e `TechnicianScheduleController`: deixaram de enviar `tracking_token`.
- **Mantido sem alteração:** autenticação, policies, isolamento por tenant, URLs assinadas de fotos e assinatura, links assinados de NFS-e de contrato e SaaS, throttles e `SensitiveData` (que segue mascarando as colunas legadas em logs).

### Templates (`{{ link_os }}`)

- **Templates padrão** (serviço, seeder e tela de templates): as linhas com `{{ link_os }}` foram retiradas. As demais variáveis (`saudacao`, `cliente`, `ordem`, `saldo`, `dias_pendentes`, `valor_orcamento`, `valor_servico` e as do técnico) estão intactas.
- **Templates já salvos pelos tenants** não foram alterados no banco. Na montagem da mensagem, a **linha inteira** que contém `{{ link_os }}` é descartada, para não sobrar "Acompanhe pelo link: " vazio. A regra é a mesma no servidor (`WhatsappMessageTemplateService::withoutRemovedPlaceholders`, envio via WAHA) e no navegador (`resources/js/lib/whatsapp-template.ts`, botão e prévia). A documentação de variáveis da tela não cita mais `{{ link_os }}`.
- **E-mails:** removidos os botões de acompanhamento e o bloco da chave. Os textos foram ajustados ("Avisaremos sobre as próximas etapas…"; o lembrete de feedback pede resposta por e-mail).

### Frontend

- Removidos:
  - o item "Acompanhamento público" da listagem;
  - o botão de link dos follow-ups;
  - o botão, o diálogo "Acesso do cliente" e o diálogo de regeneração na edição da OS;
  - a opção de chave em Configurações;
  - o texto com o link no recibo impresso e no PDF;
  - a chave anexada à mensagem de WhatsApp.

## 3. Banco de dados

### 3.1 Nesta etapa (migrations novas, ainda não executadas em produção)

| Migration | Efeito | Risco |
|---|---|---|
| `2026_11_13_100000_add_tenant_id_id_index_to_orders` | Índice `(tenant_id, id)` da OS-03 (sem a coluna de verificação) | `ADD INDEX` online no InnoDB; idempotente |
| `2026_11_13_110000_repoint_order_fiscal_document_urls` | OS cujo `fiscal_document_url` aponta para `/os/.../fiscal-proof/pdf` passam a apontar para a rota autenticada da última NFS-e autorizada da OS | Só reescreve a URL; se a nota não for encontrada, o valor antigo fica. Varredura completa de `orders` (9 s com 202 mil OS no ambiente de carga) |

**Nenhuma coluna, OS, cliente, orçamento ou histórico foi apagado.**

### 3.2 Colunas e índices exclusivos da consulta pública (remoção planejada, não executada)

| Objeto | Uso atual | Dependências restantes |
|---|---|---|
| `orders.public_access_key` (text) | Nenhum (só em `$hidden` e `SensitiveData`) | Seeds de benchmark (`tests/Feature/Performance`) |
| `orders.public_access_key_hash` (varchar) | Nenhum | Idem |
| `orders.public_access_key_check` | Só existe em bancos que rodaram a migration antiga da OS-03 (ambiente de carga local); **não existe em produção** | — |
| `others.public_order_access_key_required` | Nenhum | — |
| `orders.tracking_token` (`varchar(40) UNIQUE NOT NULL`) e seu índice único | Gerado na criação por ser obrigatório; não é lido | `OrderController::store`, `MaintenanceContractService`, factories e seeders |

Plano para a migration posterior (VETOROS-PERFORMANCE-OS-05 ou equivalente), **somente depois** de o código desta etapa estar em produção e estável:

1. Confirmar na VPS que nenhum código ainda lê as colunas. Este código já não lê, e os workers precisam ter sido reiniciados.
2. **Backup** de `orders` e `others`, ou pelo menos das colunas, caso seja preciso reverter.
3. Remover `others.public_order_access_key_required`, `orders.public_access_key` e `orders.public_access_key_hash` (`DROP COLUMN`, `ALGORITHM=INPLACE`; reconstrói a tabela, rodar fora do pico).
4. Para o `tracking_token`, em duas fases:
   1. Torná-lo `nullable` e parar de gerá-lo.
   2. Depois, remover o índice único e a coluna.
5. Atualizar factories, seeders e o benchmark, e tirar as colunas de `$hidden` e `SensitiveData`.

Os eventos já registrados com `channel = public_tracking` (`order_events` e `order_budgets.response_channel`) são histórico e devem ser mantidos.

## 4. Testes

- **Novo `OrderPerformanceOs04Test` (6 testes):**
  - links antigos `/os/...` respondem 410 sem dados do cliente nem da OS;
  - POSTs antigos (aprovar orçamento, aviso, retirada, feedback, chave) não alteram nada;
  - nenhuma rota pública de OS registrada além da página 410;
  - criar OS não gera chave nem hash;
  - listagem e tela da OS não expõem token nem chave;
  - linhas com `{{ link_os }}` são descartadas e as demais variáveis preservadas, e os templates padrão não têm mais `link_os`.
- **`OrderPerformanceTest`:** os dois testes da chave foram trocados por um que garante **zero `Hash::make` e zero `Hash::check`** ao criar, listar e abrir OS.
- **Removidos por testarem só o portal ou a chave:**
  - `OsControllerTest`, `Security/PublicOrderPortalTest`, `OrderPublicAccessKeyRegenerationTest` e `OrderAccessKeyResilienceTest`;
  - os testes de verificação rápida da `OrderPerformanceOs03Test`, cujos testes de e-mail continuam;
  - 5 testes de resposta pública em `OrderBudgetVersioningTest`;
  - 4 em `OrderEventTrailTest`;
  - 1 em `IntelHomologationTest`, sobre comissão com retirada confirmada pelo cliente.
- **Adaptados ao fluxo interno:**
  - fluxo completo da OS em `OrderControllerTest`, até "Entregue" pela equipe;
  - renegociação e métricas de conversão em `OrderBudgetVersioningTest`, com aprovação pela equipe;
  - NFS-e da OS em `NativeFiscalEmissionTest`: o link é a rota autenticada e quem não está logado é redirecionado;
  - `AuditAppKeyCommandTest`, sem a chave das OS.
- **Suíte completa:** **884 aprovados, 1 ignorado (benchmark), 0 falhas.**
- **Demais verificações:**
  - `npm run types` e `npm run build`: aprovados.
  - Pint aprovado. Ele também reformatou os imports de `TechnicianScheduleController`.
  - ESLint nos 9 arquivos de frontend alterados: **nenhum problema novo**; a contagem é idêntica à do `HEAD` (6, 8, 3, 16 e 13 erros de `any`, que já existiam).

## 5. Desempenho

O ambiente é o mesmo da OS-03, para comparação direta:

- banco `vetoros_perf_os03`, com 50 tenants e 202 mil OS;
- servidor embutido do PHP com 10 workers e opcache;
- config, rotas e eventos em cache em diretório temporário, sem tocar em `bootstrap/cache`;
- mesmo gerador de carga e mesma mistura, sem a operação "link público": `scripts/performance/os04-loadgen.php`, com a opção nova `--ops`.

O banco de carga recebeu as duas migrations novas. Saída bruta em `docs/audits/performance-os-04/`.

### 5.1 Criação sem bcrypt (1 usuário, sem pausa, tenant de 10 mil OS)

| Operação | OS-03 (com bcrypt custo 12) | OS-04 |
|---|---|---|
| Criar OS (p50 / p95) | ~300 ms de p50 | **35 / 51 ms** (800 criações) |
| Listar (p50) | 89 ms | 104 ms |
| Abrir OS (p50) | 73–75 ms | 97 ms |
| Formulário nova OS (p50) | — | 59 ms |

**A remoção do bcrypt reduziu a criação em cerca de 8,5 vezes.** Leitura e abertura ficaram na mesma faixa da OS-03. A diferença de 15 a 20 ms está dentro da variação entre execuções numa máquina compartilhada, porque essas telas já não usavam bcrypt depois da OS-03.

### 5.2 Matriz com usuários reais (2 s de pausa entre ações), p95 em ms

"Listar" é o pior entre listar, filtrar e pesquisar. "Abrir" é o pior entre a OS simples e a pesada.

| Tenants × usuários | req/s | Listar | Abrir | Form. | **Criar (OS-03 → OS-04)** | Editar |
|---|---|---|---|---|---|---|
| 10 × 10 | 4,7 | 1.293¹ | 203 | 95 | 645 → **81** | 422 |
| 10 × 30 | 13,9 | 826 | 784 | 167 | 536 → **111** | 308 |
| 30 × 30 | 13,9 | 272 | 193 | 167 | 1.153 → **298** | 155 |
| 30 × 50 | 24,1 | 287 | 296 | 142 | 1.274 → **156** | 164 |
| 50 × 50 | 21,1 | 804 | 426 | 326 | 1.727 → **532** | 221 |

¹ Um único pico de pesquisa (n = 17) definiu o p95. A listagem simples teve p95 de 197 ms.

- **Criação:** ficou abaixo de 600 ms de p95 em todas as combinações; na OS-03 passava de 1 s com 30 usuários ou mais.
- **50 tenants × 50 usuários:** antes era o pior caso, com listagem em 4.039 ms, abertura em 1.536 ms e formulário em 1.357 ms de p95. Agora fica abaixo de 810 ms nas três.
  - Parte da melhora vem da CPU liberada pela criação sem bcrypt.
  - Parte pode vir do buffer pool já aquecido, então não é uma comparação isolada.
- **Erros:** 0 em todas as rodadas. Não houve "fatal" nem exceção no log do servidor.

### 5.3 Criação concorrente (2 tenants, 30 usuários, sem pausa)

- **Volume:** 1.832 OS criadas em 30 s (60,5 req/s), com p50 de 375 ms e p95 de 1.101 ms.
- **Comparação:** a rodada da OS-03 com 2 tenants e 30 usuários sem pausa usou a mistura completa de operações, não só criação. Nela, as 59 criações tiveram p50 de 1.715 ms e p95 de 2.584 ms. Os volumes não são comparáveis, mas a latência por criação caiu bastante.
- **Integridade:** 0 erros e **0 números de OS duplicados**, conferidos no banco. As 2.594 OS criadas durante a carga estão sem chave e sem hash.

### 5.4 Rotas públicas que expõem dados de clientes

- **`/os/...`:** só resta a página 410 estática, que não consulta o banco.
- **Rotas públicas que continuam:**
  - fotos e assinatura: URL **assinada** e temporária;
  - NFS-e de contrato e do SaaS: link **assinado** e temporário;
  - chat do site;
  - planos;
  - feedback do **tenant** sobre o VetorOS (`/experience/{token}`), que não expõe dados de clientes finais.

  Nenhuma delas expõe dados de OS por token simples.

## 6. Riscos, impacto e pendências

1. **Impacto funcional para o cliente final:** confirmar com o negócio. O cliente deixa de:
   - acompanhar a OS pela internet;
   - aprovar ou recusar orçamento pelo link;
   - confirmar aviso e retirada;
   - avaliar o atendimento;
   - baixar recibos, comprovantes e NFS-e pelo link.

   Essas ações passam a ser registradas pela equipe. A recusa pelo cliente com motivo, que só existia no portal, também passa a depender do registro manual. Os indicadores de **feedback** devem cair, porque não há mais canal de nota.
2. **Commit e deploy:** não feitos; precisam de autorização. Ordem sugerida:
   1. Rodar as migrations: o índice, que é online, e a reescrita dos links de NFS-e.
   2. Publicar o código.
   3. Reiniciar o `vetoros-worker`.

   Não rodar `vetoros:backfill-order-public-key-checks`: o comando deixou de existir. Se a OS-03 for publicada antes desta etapa, ela precisa ser publicada **junto** com esta, para a coluna de verificação nunca ser criada.
3. **Migration de remoção das colunas:** planejada (seção 3.2), não executada.
4. **Código que ficou sem uso, para limpeza posterior:**
   - `OrderBudgetService::respondAsCustomer` e `CHANNEL_PUBLIC`;
   - `OrderStatusService::recordCustomerAction`;
   - os eventos e listeners `OrderCustomer{NotificationAcknowledged,PickupAcknowledged,FeedbackSubmitted}`.

   Foram mantidos para preservar a leitura do histórico e diminuir o risco desta etapa.
5. **Templates salvos pelos tenants:** continuam com `{{ link_os }}` no banco; a linha é descartada no envio. Se o tenant reabrir e salvar o template, a linha continua lá, sem efeito. Uma limpeza nos dados pode ser feita depois, se o negócio quiser.
6. **Recibos já impressos e mensagens já enviadas** levam à página 410.
7. **Pendências que vêm da OS-03:**
   - rotacionar a senha do banco exposta no histórico do Git;
   - medir o buffer pool do MySQL na VPS;
   - confirmar p50 e p95 em produção.
8. **Banco local de carga:**
   - recebeu as migrations desta etapa e cerca de 2,6 mil OS de teste;
   - pode ser removido com `DROP DATABASE vetoros_perf_os03`;
   - o banco de desenvolvimento `vetoros` não foi tocado.
