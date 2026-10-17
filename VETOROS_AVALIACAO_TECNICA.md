# Avaliação técnica do VetorOS para reescrita em Node.js + Next.js

> **Atualização (2026-10-16, VETOR-SAAS-EFI-03):** a integração Mercado Pago descrita nesta análise foi removida. O recebimento das assinaturas SaaS é feito só pela Efí Bank (Pix, boleto/Bolix, cartão e recorrência), configurada em RootAdmin → Financeiro · Efí Bank. As menções ao Mercado Pago abaixo são o retrato de 2 de setembro de 2026 e foram mantidas como registro histórico.

**Data da análise:** 2 de setembro de 2026  
**Escopo:** inspeção estática do repositório no commit `2648e8ec` (`Push`, 2026-08-28), rotas, código PHP/TypeScript, migrations, configurações, documentação e testes.  
**Limites:** nenhum código, banco, migration ou comportamento foi alterado. O banco de produção, infraestrutura implantada, métricas de uso, cobertura de testes e comportamento visual em navegador não foram inspecionados; quando dependem dessas fontes, estão marcados como **não confirmado**.

## 1. Resumo executivo

O VetorOS é um SaaS multi-tenant funcional e relativamente maduro para assistência técnica. Seu núcleo cobre clientes, equipamentos, ordens de serviço (OS), orçamento, agenda de técnicos, estoque, venda/PDV, caixa, recebíveis, despesas, contratos, comissões, follow-up, qualidade, mensagens, documentos e um portal público do cliente. O projeto acumula regras valiosas e testes de regressão focados nos fluxos críticos; uma reescrita que trate o sistema apenas como CRUD perderá comportamento operacional e financeiro importante.

A arquitetura atual é um monólito modular por convenção: Laravel 12/PHP 8.3+, Eloquent, Inertia.js, React 19/TypeScript, Tailwind CSS 4 e componentes Radix/shadcn. Controllers entregam páginas Inertia e APIs; models usam um escopo global de tenant; services concentram parte das transações; events/listeners registram auditoria e enviam notificações; comandos agendados executam follow-ups e rotinas de ciclo de vida.

Os principais riscos antes de migrar são: (1) o isolamento multi-tenant depende de estado implícito (`auth`/sessão + global scope), e não de uma barreira no banco; (2) `Model::unguard()` global amplia o impacto de atribuições indevidas; (3) o portal público possui mutações sem rate limit explícito e seu token funciona como capacidade de acesso; (4) o webhook Mercado Pago autentica por segredo na URL e não valida assinatura criptográfica do provedor; (5) upload aceita SVG em armazenamento público; (6) papéis/permissões são rígidos, codificados e operador recebe praticamente os mesmos poderes do administrador; (7) migrations incrementais numerosas e vários campos/estados sem constraints fortes elevam o risco MySQL → PostgreSQL; (8) controllers e páginas muito grandes concentram domínio, consulta e apresentação.

Recomendação preliminar: não iniciar por uma reescrita total em paralelo. Primeiro produzir catálogo executável de contratos e invariantes, inventário do schema real de produção e testes de caracterização; depois experimentar uma migração incremental (Strangler) em um módulo periférico. A decisão final deve ocorrer após medir volume de dados, dependências operacionais, tolerância a coexistência e estratégia de identidade/tenant.

## 2. Estado atual do VetorOS

### Stack e execução

- Backend: PHP (`composer.json` exige `^8.3`; `Dockerfile` usa PHP 8.4 FPM), Laravel 12, Eloquent, Sanctum 4, filas Laravel e Pest 3.
- Frontend: React 19 + TypeScript, Inertia 2, Vite 6, Tailwind 4, Radix/shadcn, React Hook Form/Zod, TanStack Table, Recharts e React PDF.
- Dados: configuração suporta MySQL e outros drivers Laravel; Docker instala `pdo_mysql`. O banco efetivamente usado em produção é **não confirmado**. Os testes esperam SQLite em memória (`phpunit.xml`).
- Estado cliente: predominantemente estado local React/Inertia; não foi encontrado Redux/Zustand nem TanStack Query. Preferências de aparência/período usam `localStorage` (`resources/js/hooks`).
- Infra: há `Dockerfile`, mas não há Compose/Nginx versionados no escopo inspecionado. O container expõe somente PHP-FPM 9000 e não possui estágio explícito de build do frontend. Deploy efetivo e backups são **não confirmados**.
- Qualidade: CI (`.github/workflows/ci.yml`) e scripts para `npm run types`/`php artisan test`; não há cobertura instrumentada visível nem suíte E2E versionada, apesar de Playwright estar instalado.

### Maturidade aparente

Alta no núcleo OS/financeiro e média nos módulos auxiliares. A maturidade é inferida por amplitude dos fluxos, histórico de migrations, serviços transacionais, auditoria e testes; uso real/volume/SLA permanecem **não confirmados**.

## 3. Arquitetura atual

### Organização e relações

1. `routes/web.php` expõe site, autenticação, assinatura e portal público; `routes/app.php` reúne o painel autenticado; `routes/admin.php` administra a plataforma; `routes/api.php` atende aplicativo técnico/mobile e webhook.
2. `bootstrap/app.php` aplica `auth`, `app`, `admin` e `check.subscription`, configura CSRF e inclui middleware Inertia/CORS.
3. Controllers em `app/Http/Controllers/App` misturam orquestração, consulta analítica, validação e composição de view-models. Admin e site possuem namespaces próprios.
4. Eloquent em `app/Models` e `app/Models/App` representa domínio e aplica `Tenantable`; não há camada repository. Consultas ficam em controllers, services e models.
5. Services (`app/Services`) encapsulam finanças, caixa, vendas, status, estoque/itens, contratos, follow-up, fiscal manual, notificações e Mercado Pago.
6. Events/listeners (`app/Events`, `app/Listeners`, `App\Providers\EventServiceProvider`) desacoplam logs de ciclo de vida e comunicações. A autodiscovery foi desligada para evitar listeners duplicados.
7. React em `resources/js/pages` recebe props Inertia; componentes comuns ficam em `resources/js/components`. Relatórios e recibos têm renderização PDF/print no cliente.

### Pontos fortes

- Separação explícita entre administração global, app do tenant, API e portal público.
- Services transacionais nos fluxos financeiros e de estoque, com locking em pontos sensíveis (`SaleService`, `TenantSequence`).
- Policies e route binding tenant-aware nos agregados centrais.
- Eventos e tabelas de logs preservam rastreabilidade operacional.
- Testes nomeiam muitas regras de regressão relevantes.

### Fragilidades estruturais

- `OrderController` (~1.436 linhas), `FollowUpController` (~1.270), `DashboardController` (~982) e `TechnicianScheduleController` (~893) concentram responsabilidades.
- No frontend, páginas como portal da OS (~1.171), edição de OS (~1.005) e qualidade (~962) dificultam testes e evolução.
- `bootstrap/app.php` fornece `web`/`api` a `withRouting` e volta a agrupar os mesmos arquivos em `then`; potencial registro duplicado de rotas deve ser confirmado com `route:list` no ambiente alvo.
- `AppServiceProvider::boot()` resolve tenant e aplica SMTP por tenant durante bootstrap da requisição; esse estado mutável de configuração exige cuidado em workers persistentes.

## 4. Mapa de módulos

| Módulo | Finalidade e entidades | Fluxos e dependências | Maturidade aparente |
|---|---|---|---|
| Tenants/assinaturas | `Tenant`, plano, período, pagamento | trial de 14 dias; expiração; PIX Mercado Pago; ativação/renovação; e-mails | Média/alta |
| Clientes | `Customer` | CRUD, busca, duplicidade, pré-cadastro mobile, CSV, saldos recebíveis | Alta |
| Equipamentos/cadastros | `Equipment`, marcas/modelos, services, checklists | catálogo por tenant; usado por OS, orçamento e agenda | Média |
| Ordens de serviço | `Order`, históricos, logs, peças, pagamentos, imagens | entrada, previsão, orçamento, execução, entrega, garantia, feedback e comunicação | Alta/crítica |
| Portal público | OS por `tracking_token` | chave opcional, orçamento, confirmações, retirada, feedback, comprovantes | Alta/crítica |
| Agendamentos/app técnico | `Schedule`, checklist/material/imagem/pagamento | atribuição, envio ao técnico, check-in/out GPS, relatório, pedido de fechamento | Alta |
| Orçamentos | `Budget` e campos de orçamento da OS | modelos, validade, aprovação/reprovação, follow-up | Média/alta; há duas representações |
| Peças/estoque | `Part`, `PartMovement`, `OrderPart`, `SaleItem` | entrada/ajuste, baixa por OS/venda, retorno, estoque mínimo, etiqueta EAN-13 | Alta/crítica |
| Vendas/PDV | `Sale`, itens e logs | venda exige caixa, pagamento parcial, cancelamento, devolução de estoque, fiscal manual | Alta/crítica |
| Caixa | `CashSession`, movimentos e logs | abertura única, entradas, sangrias/cancelamento, fechamento e diferença | Alta/crítica |
| Financeiro | recebíveis, `Expense`, `AccountPayable`, comissões | sincroniza OS/venda, despesas, contas a pagar, comissão técnica, relatórios | Média/alta |
| Contratos | `MaintenanceContract` | criação, renovação, suspensão, reativação, cancelamento e impressão | Média |
| Follow-up/tarefas | campos na OS + logs | orçamento, cobrança e recuperação de feedback; pausa, snooze, atribuição, metas | Alta |
| Qualidade | feedback/garantia na OS | indicadores, retorno em garantia, recuperação de avaliação baixa | Alta |
| Mensagens | `Message` + auditoria | remetente/destinatário, leitura, edição/exclusão conforme policy | Média |
| Comunicação | templates WhatsApp, mailables/jobs | modelos por tenant, e-mails SMTP próprios, avisos e lembretes | Média/alta |
| Fiscal | campos na OS/venda e documentos administrativos | emissão externa/manual; guarda número, URL, tipo e notas | Parcial; emissão automática não existe |
| Documentos | recibos, comprovantes, etiquetas e PDFs | React PDF/impressão, logo/empresa e dados da OS/venda | Média/alta |
| Relatórios/dashboards | queries agregadas | operação, financeiro, vendas, caixa, clientes, peças, agenda, qualidade | Alta, porém acoplada aos controllers |
| Configurações/empresa | `Other`, `Company`, templates | flags de módulos, SMTP criptografado, metas, paginação, branding | Alta |
| Admin global | tenants, planos, períodos, features, branches, usuários, feedbacks | operação da plataforma e documentos fiscais da assinatura | Média |
| Ajuda/feedback do produto | tópicos, melhorias, depoimentos | importação de manual; solicitações e consentimento | Média |
| Apps auxiliares | downloads/links de apps | distribuição de artefatos ao tenant | Baixa/média |

## 5. Regras de negócio críticas

### Ordem de serviço

- Numeração é sequencial por tenant (`TenantSequence`) e `tracking_token` é globalmente único.
- Criação exige previsão de entrega; assinatura do cliente não é aceita no fluxo web de criação, ficando em fluxo próprio/mobile.
- Estados são centralizados em `app/Support/OrderStatus.php`, mas `canTransition()` atualmente aceita qualquer transição entre valores conhecidos. Preservar o comportamento flexível ou decidir conscientemente endurecê-lo.
- Toda mudança relevante gera histórico/status e/ou `OrderLog` via services, events e listeners.
- Status fora de entregue limpa `delivery_date`; garantia calcula expiração e permite vincular OS de retorno à origem.
- Técnicos veem suas OS salvo `can_view_all_orders`; regras de reatribuição e edição são específicas.
- Partes adicionadas reduzem estoque; reduzir/remover devolve estoque; falta de saldo bloqueia operação.
- Pagamento não pode exceder saldo; exige caixa aberto; remoção é bloqueada se o caixa estiver fechado; recebível derivado deve ser sincronizado.
- Pagamento recebido no app técnico entra como pendente e só integra o caixa após conferência.

### Venda, caixa e financeiro

- Venda exige caixa aberto, bloqueia quantidade superior ao estoque e valor pago superior ao total.
- Cancelamento devolve estoque e registra movimento; operador pode cancelar apenas até 60 minutos; caixa fechado bloqueia cancelamento.
- Exclusão só de venda cancelada, somente por root/admin e com caixa aberto.
- Há somente um caixa aberto por tenant; saldo esperado = abertura + vendas concluídas + pagamentos de OS + entradas + entradas manuais − saídas manuais − sangrias válidas.
- Sangria não pode superar saldo esperado e não pode ser cancelada após fechamento.
- Status financeiro distingue pendente, parcial, pago e cancelado; recebíveis são derivados de OS/venda e precisam manter idempotência.
- Comissões e contas a pagar dependem da habilitação do módulo financeiro (`Other::financeEnabled`).

### Agenda/técnico

- Agendamento pode existir com ou sem OS; vínculo de OS deve pertencer ao cliente.
- Envio ao técnico depende de flag; técnico só acessa agendamentos próprios enviados.
- Check-in registra GPS; checkout exige check-in, GPS, relatório e checklist obrigatório completo.
- Técnico pode registrar materiais/imagens e pagamento local e solicitar fechamento; a empresa define preço final em fluxo separado.
- Agendamento vinculado a OS paga não deve ser apagado; fechar agenda não implica automaticamente mudar status da OS.

### Follow-up e qualidade

- Automação depende de flag por tenant, intervalo entre contatos, estado da OS, saldo/orçamento pendente, pausas e contatos recentes.
- Tarefas suportam responsável, conclusão e adiamento; filtros e agenda diária dependem desses campos.
- Avaliação só é aceita após entrega e uma única vez; nota ≤ 3 abre recuperação pendente.
- Confirmações de aviso e retirada são idempotentes e registradas no ciclo de vida.

### Cadastro, identidade e produto

- `customer_number`, `user_number`, `order_number`, `sales_number` e equivalentes devem permanecer sequenciais por tenant quando assim usados.
- Importação CSV é tolerante: e-mails repetidos e CPF/CNPJ inválido/repetido não bloqueiam; processa lotes com fallback por linha.
- Registro público cria tenant, company e usuário root-app e concede trial de 14 dias em transação.
- Depoimento público depende de consentimento; alterar apenas notas administrativas não reenvia e-mail ao cliente.

## 6. Banco de dados

### Estruturas principais

O histórico contém dezenas de migrations incrementais. Os blocos observados são:

- Plataforma: `plans`, `periods`, `features`, `tenants`, `branches`, `settings`, `users`, `payments`, documentos fiscais administrativos, feedbacks e solicitações de melhoria.
- Cadastros: `customers`, `equipment`, marcas/modelos, `services`, `checklists`, `companies`, `others`, `whatsapp_messages`, `receipts`.
- Operação: `orders`, `order_status_history`, `order_logs`, `order_parts`, `images`, `schedules`, tokens push, contratos.
- Estoque/vendas: `parts`, `part_movements`, `sales`, `sale_items`, `sale_logs`.
- Financeiro: `order_payments`, `cash_sessions`, movimentos/logs de caixa, `expenses`, logs, recebíveis, contas a pagar e comissões.
- Infra: `sessions`, `cache`, `jobs`, batches, falhas e tokens Sanctum.

### Relacionamentos, chaves e constraints

- A maioria das entidades tenant-owned possui `tenant_id` com FK e cascade; algumas FKs são nullable, permitindo registros órfãos conceitualmente.
- Há unicidade composta em sequências relevantes, por exemplo `orders(tenant_id, order_number)`; `tracking_token` é único global.
- `users.email` é único global, impedindo o mesmo e-mail em tenants diferentes; confirmar se é regra de produto ou limitação acidental.
- CPF/CNPJ deixou de ser único por tenant em migration posterior, coerente com importação tolerante.
- Muitos estados são `tinyInteger`/strings sem check constraints. Integridade de transição vive no código.
- Vários relacionamentos são polimórficos por `source_type/source_id` sem FK, especialmente auditoria/financeiro.

### Inconsistências e remodelagem futura

- `budgets` separado e campos `budget_*` em `orders` representam conceitos sobrepostos; definir fonte canônica.
- `service_cost`, `service_value`, `budget_value`, totais e recebíveis têm semânticas próximas; criar glossário financeiro antes da migração.
- `responsible_technician` textual convive com `user_id`; preferir referência estável e snapshot apenas quando necessário.
- Papéis numéricos, status numéricos e métodos de pagamento textuais precisam de catálogo/versionamento; não converter cegamente em enums PostgreSQL rígidos.
- Endereços e configurações por tenant são repetidos; normalização deve preservar snapshots usados em documentos.
- Cascades de customer/equipment/user para OS podem apagar histórico operacional/financeiro; revisar política legal de retenção antes de reproduzir.
- Campos JSON/raw response e datas precisam ser inventariados no banco real para tipos `jsonb`/`timestamptz`.

### Riscos MySQL → PostgreSQL

- Diferenças de `tinyInteger`, boolean, collation/case sensitivity, datas inválidas, casts implícitos, `whereRaw`, `COALESCE`, ordenação e auto incremento.
- Índices/uniques com valores `NULL`, comprimento de índices e comparação de strings podem mudar resultados.
- Sequências calculadas por `MAX + 1` (`TenantSequence`) exigem lock/constraint robustos para concorrência.
- Baseline baseada apenas nas migrations pode divergir de produção; extrair schema e dados reais anonimizados antes de projetar o novo schema.

**Destino conceitual:** preservar históricos, logs, documentos, movimentos e vínculos financeiros; remodelar identidade/permissões, orçamento, catálogo de estados e recebíveis; migrar dados de negócio e auditoria; eliminar somente duplicações após reconciliação formal. Não há evidência suficiente para autorizar exclusão de tabela/campo nesta fase.

## 7. Multitenancy

`Tenantable` adiciona `TenantScope`, preenche `tenant_id` na criação e restringe route model binding. `resolveCurrentTenantId()` usa usuário autenticado e fallback de sessão; login grava tenant em sessão. Rotas `app` rejeitam usuário sem tenant e rotas `admin` rejeitam usuário com tenant.

### Controles positivos

- Models tenant-owned adotam escopo global e policies críticas comparam tenant.
- APIs técnicas validam papel/propriedade; testes cobrem acesso cruzado em usuários, imagens, qualidade e agenda.
- Fluxo público usa consultas `withoutGlobalScopes()` explicitamente filtradas pelo tenant derivado da própria OS.
- Sequências e configurações são consultadas por tenant.

### Riscos

- **Alto:** isolamento é aplicação-dependente. `withoutGlobalScopes()`, SQL direto ou model que esqueça `Tenantable` pode expor outro tenant; PostgreSQL RLS não existe hoje.
- **Alto:** tenant implícito em sessão e configuração global de SMTP cria risco em queue workers/Octane/processos longos se o contexto não for instalado e limpo por job.
- **Alto:** FKs normalmente garantem existência, não que entidades relacionadas compartilhem o mesmo `tenant_id`; esse invariante fica no código.
- **Médio:** `tenant_id` nullable em entidades operacionais cria ambiguidades e pode retirar registros do escopo.
- **Médio:** toda query com `withoutGlobalScopes()` precisa de revisão individual; uso público parece intencional, mas a cobertura completa é **não confirmada**.

Na nova arquitetura, tenant deve ser um contexto explícito e imutável por request/job, incluído em toda chave de negócio, constraint composta, log e cache key. Considere RLS como defesa adicional, sem substituir autorização da aplicação.

## 8. Usuários, roles e permissões

Autenticação web usa sessões Laravel; API usa tokens Sanctum. Existem cinco papéis numéricos em `User`: root-system (99), root-app (9), administrador (1), operador (2) e técnico (3). Root/admin recebem todas as permissões; operador atualmente recebe a mesma lista funcional; técnico recebe dashboard, OS, agenda e mensagens. `can_view_all_orders` amplia visão do técnico.

Policies existem para OS, mensagem, agenda, venda, caixa, despesa, conta a pagar, contrato e usuário; Gates cobrem módulos. Restrições especiais observadas: tenant não manipula usuário de outro tenant; exclusão de venda exige admin/root; técnico tem propriedade limitada; fluxos administrativos fundamentais estão protegidos por middleware.

Riscos:

- **Alto:** RBAC codificado em `User::permissions()` dificulta concessão/revogação granular e auditoria de mudanças.
- **Alto:** operador pode criar/alterar/excluir usuários e acessar configurações/financeiro, equivalência que deve ser validada com o negócio.
- **Alto:** nem todos os controllers usam policy de forma uniforme; alguns usam Gate, helpers ou confiança no escopo. É necessário matriz rota × papel × tenant antes da migração.
- **Médio:** `roles` nullable/tinyint sem constraint permite estado desconhecido.
- Proteções contra exclusão do último administrador/root do tenant não foram confirmadas no código inspecionado: **não confirmado**.

## 9. Segurança

### Crítico (P0)

- Nenhuma vulnerabilidade crítica explorável foi comprovada apenas pela inspeção. Pentest, dependências em runtime e configuração de produção são **não confirmados**.

### Alto

- Webhook Mercado Pago (`WebhookController`) usa token secreto no path e consulta o pagamento na API, mas não valida `x-signature`/assinatura oficial. URLs vazam com mais facilidade em logs/proxies; migrar para assinatura, timestamp/replay protection e segredo rotacionável.
- Portal público: `tracking_token` autoriza leitura e, quando a chave opcional não é exigida, também mutações. Apenas `/access` tem throttle explícito; orçamento, confirmações e feedback precisam rate limit/anti-automação e política de expiração.
- Upload (`ImageController`) aceita `svg` e usa arquivos acessíveis pelo disco público. SVG ativo pode produzir XSS/conteúdo malicioso dependendo de como é servido/incorporado. Bloquear conteúdo ativo, validar magic bytes, re-encodar raster e servir com headers seguros.
- `Model::unguard()` global reduz proteção contra mass assignment. Embora haja Requests/validação em muitos fluxos, qualquer `create/update($request->all())` futuro terá maior impacto.
- Isolamento tenant depende do ORM/contexto implícito; ver seção 7.

### Médio

- CORS permite origem, método e header `*`; `supports_credentials=false` reduz impacto para cookies, mas tokens bearer mobile ampliam a superfície.
- Webhook registra o token recebido em warning quando inválido, expondo tentativa/segredo em logs.
- `raw_response` do pagamento guarda payload integral; definir minimização, criptografia e retenção.
- Sessão não é criptografada por padrão; cookie é HttpOnly/SameSite Lax, mas `secure` depende do ambiente. Validar HTTPS/HSTS/Secure em produção.
- Portal público revela comprovantes e histórico por token; impedir indexação/cache compartilhado e definir revogação/rotação.
- URLs fiscais informadas manualmente precisam allowlist/proxy seguro para evitar phishing/open navigation na UI.
- PDFs e textos livres devem permanecer escapados; React protege texto por padrão e não foi encontrado uso relevante de `dangerouslySetInnerHTML`, mas templates/documentos devem ser testados.

### Baixo/melhoria

- CSRF Laravel cobre web; exceção do webhook é esperada, mas a rota está no grupo API e merece simplificação/documentação.
- Query Builder/Eloquent reduz SQL injection; `whereRaw/selectRaw` observados usam expressões controladas. Não foi achada concatenação SQL diretamente explorável.
- Adicionar CSP, Permissions-Policy, Referrer-Policy, limites globais, trilha de login/admin, gestão de sessões/tokens e varredura de dependências/secrets em CI.
- `.env` não está rastreado segundo `git ls-files`; valores foram deliberadamente omitidos desta análise. Rotação dos segredos existentes é **não confirmada**.

## 10. Integrações

- **Mercado Pago:** SDK PHP cria PIX, consulta pagamento, usa idempotency key, persiste status/payload e renova tenant. Preservar `external_reference`, idempotência, extensão a partir da expiração futura e prevenção de renovação dupla.
- **E-mail:** mailables para cadastro, status de assinatura, fatura, OS, orçamento, cobrança, feedback e melhorias. SMTP é configurável/criptografado por tenant (`TenantMailConfig`, `OtherController`). Preservar fallback, idempotência e isolamento em jobs.
- **WhatsApp:** geração de links/textos e templates por tenant; não foi encontrada API oficial de envio. Integração efetiva é **parcial/manual**.
- **ViaCEP:** chamadas diretas do navegador em formulários e hook. Preservar contrato de campos, mas adicionar timeout/cache/proxy e tratamento de indisponibilidade.
- **Expo push:** tokens de dispositivos e `TechnicianPushNotificationService`; entrega e credenciais em produção são **não confirmadas**.
- **Fiscal:** não há Focus NFe no código atual. NFS-e/NF-e são emitidas fora do sistema; VetorOS registra manualmente número/link/tipo/notas. `government_api` é somente configuração/feature flag; NFC-e não implementada.
- **Armazenamento:** local/public e S3 configuráveis. Disco Google está configurado sem dependência de driver claramente presente: disponibilidade é **não confirmada**. Backup não foi identificado.
- **PDF/impressão:** React PDF/print no cliente para relatórios, recibos e comprovantes; preservar layouts e aviso de documento não fiscal.

## 11. Frontend e UX

### Estado observado

- Navegação agrupa atendimento, financeiro, relatórios e configurações, respeitando permissões e flags do tenant.
- Há buscas, filtros persistidos, paginação, tabelas, calendários, badges, skeletons/toasts e modais; operação cobre desktop e componentes responsivos.
- Fluxos ricos existem para OS, agenda técnica, follow-up, caixa e relatórios.
- APIs auxiliares são chamadas por `fetch`; Inertia gerencia formulários/navegação. Cache, deduplicação e invalidação de server state não são sistemáticos.

### Problemas/oportunidades

- Páginas monolíticas e formulários extensos elevam carga cognitiva e risco de regressão.
- Padrões mistos de formulário, select, feedback e abertura de janela/print tornam a experiência inconsistente.
- Relatórios PDF no cliente podem consumir memória e divergir por browser; definir estratégia de geração/assinatura no novo sistema.
- A quantidade real de cliques, acessibilidade, Core Web Vitals e experiência móvel não foi medida: **não confirmado**. Fazer testes observacionais com atendente e técnico antes de redesenhar.
- Na futura UI, preservar atalhos e densidade operacional; dividir por etapas apenas quando reduzir erro, não por estética.

## 12. Dívida técnica

- Controllers e páginas muito extensos; domínio, autorização, query, transformação e apresentação coexistem.
- Regras repetidas de tenant, dinheiro, filtros, status e permissões estão distribuídas.
- Ausência de repositories não é um defeito por si só, mas consultas analíticas espalhadas dificultam migração e otimização.
- Domínio anêmico em vários models; services cobrem somente parte dos casos.
- Histórico de migrations é longo e incremental; baseline ainda é apenas recomendação documental.
- Papéis/estados/flags são magic numbers/strings em vários pontos.
- Configuração SMTP mutável por request e listeners/jobs síncronos ou dependentes do driver de fila exigem disciplina operacional.
- Docker/README divergem no PHP (8.4 vs 8.2) e `composer.json` fixa plataforma 8.3.32.
- Dependência Playwright sem testes E2E encontrados; frontend não possui testes unitários/componentes visíveis.
- Comentários legados e rotas duplicadas/sinônimas (`whatsapp-messages` e `whatsapp-message`, dois endpoints PIX, GET logout API) aumentam superfície.

## 13. Testes

Há 42 arquivos de teste, majoritariamente Feature/Pest, cobrindo autenticação, autorização, tenant, OS, portal público, estoque, venda, caixa, agenda técnica, pagamentos, Mercado Pago, follow-up, qualidade, dashboard, relatórios e comandos. Unit tests explícitos são poucos (`Ean13Test` e exemplo). Não há cobertura percentual configurada, testes visuais/E2E ou testes de carga.

Execução nesta análise:

- `php artisan test`: 4 testes passaram e 246 foram reportados como falhos, todos bloqueados antes do cenário por `could not find driver` ao abrir SQLite `:memory:`. Portanto, isso é falha do ambiente de teste, não evidência de 246 regressões.
- `npm run types`: concluído com sucesso (`tsc --noEmit`, exit code 0).
- Testes de integração fiscal automática não se aplicam porque a emissão não existe; há testes do registro fiscal manual.

Lacunas prioritárias para migração: contratos de API versionados, caracterização do schema de produção, concorrência em sequências/estoque/caixa, propriedade tenant em todas as FKs, idempotência de jobs/webhook, segurança de uploads, rate limiting público, snapshots/contratos de PDFs e E2E dos percursos de atendente/técnico/cliente.

## 14. Funcionalidades e comportamentos que não podem ser perdidos

1. Isolamento integral por tenant, inclusive jobs, caches, arquivos, e-mail e relatórios.
2. Sequências legíveis por tenant e unicidade do token público da OS.
3. Histórico completo de status, pagamentos, vendas, caixa, despesas, mensagens, comunicação e auditoria.
4. Entrada da OS com cliente/equipamento, previsão, técnico, checklist, peças, imagens, orçamento, execução, entrega, garantia e avaliação.
5. Portal público com chave opcional, aprovação/reprovação de orçamento, confirmações idempotentes, retirada condicionada, feedback e comprovantes.
6. Regras de estoque transacionais em OS/venda/cancelamento/ajuste e rastreabilidade por movimento.
7. Venda somente com caixa aberto, limites de pagamento/estoque, cancelamento em 60 minutos para operador e devolução de itens.
8. Caixa único aberto por tenant, composição exata do saldo, sangria/cancelamento e imutabilidade após fechamento.
9. Pagamento de OS limitado ao saldo, conferência do pagamento móvel e sincronização dos recebíveis.
10. Agenda do técnico, propriedade, envio, check-in/out com GPS, relatório/checklist obrigatórios, materiais, fotos e fechamento solicitado.
11. Follow-up de orçamento/cobrança e recuperação de feedback com cooldown, pausa, resposta, snooze, responsável, metas e automação opt-in.
12. Avaliação única pós-entrega e abertura automática de recuperação para nota baixa.
13. Retorno em garantia vinculado à OS original e cálculo de vigência.
14. Importação CSV tolerante com processamento em lote e retorno de erros por linha.
15. Templates WhatsApp e SMTP por tenant, com regras de quando enviar e registros de contato.
16. Emissão fiscal manual, armazenamento de referência e distinção clara entre comprovante não fiscal e nota.
17. Relatórios, dashboards e indicadores atuais, incluindo filtros e comparações temporais.
18. Papéis root global/root app/admin/operador/técnico, visão limitada do técnico e controles especiais de exclusão.
19. Trial, assinatura PIX, idempotência do webhook e extensão correta do vencimento.
20. Consentimento explícito para depoimento e fluxo de melhoria/feedback do produto.

## 15. Oportunidades da nova arquitetura

### Arquitetura

Monólito modular TypeScript inicialmente, com módulos de domínio e fronteiras explícitas; eventos outbox para efeitos externos; ADRs para invariantes. Evitar microservices antes de haver necessidade operacional medida.

### Banco de dados

PostgreSQL com constraints compostas por tenant, RLS como defesa adicional, `numeric` para dinheiro, `timestamptz`, `jsonb` criterioso, índices derivados de consultas reais e migrations forward-only. Ledger/auditoria imutável onde necessário.

### Backend

Fastify com schemas compartilhados, use cases transacionais, autorização central, idempotency keys e OpenAPI. Separar commands de queries; encapsular sequências e locks.

### Frontend

Next.js + TanStack Query para server state, formulários tipados e design system. Componentizar por tarefa operacional e manter contratos de erro estáveis.

### UX

Pesquisa com usuários reais, atalhos por teclado/scanner, autosave seguro, prevenção de dupla submissão, estados offline/de rede no app técnico e acessibilidade WCAG.

### Segurança

Threat modeling por módulo, secrets manager, webhook assinado, upload isolado/re-encodado, rate limits, CSP, rotação/revogação de tokens, MFA para admins, trilha de auditoria e testes de autorização negativos.

### Performance

Orçamentos de latência, query profiling, paginação cursor onde útil, agregados/materialized views para dashboards, filas para PDFs/e-mails e Redis apenas com chaves tenant-aware e política de invalidação.

### Observabilidade

Logs estruturados com request/tenant/user/correlation id sem PII excessiva, métricas de jobs/webhooks, traces, alertas SLO, DLQ e painel de reprocessamento auditado.

### Multitenancy

Contexto explícito em request/job, constraints e políticas; testes automáticos de não interferência; arquivos e cache namespaced; ferramentas de suporte com impersonation temporária e auditada.

### Integrações

Adapters com contratos versionados, sandbox, retries com backoff, circuit breaker, idempotência, reconciliação e armazenamento mínimo do payload.

### Automação

Workflows determinísticos para follow-up, cobrança e contratos, com fila, calendário, opt-out, janela de envio e explicação do motivo de cada ação.

### IA

Somente como recomendação/assistência, sobre dados autorizados, com aprovação humana para efeitos externos e ferramentas de domínio limitadas.

## 16. IA e automação

Casos futuros: transformar relato em rascunho de OS; classificar prioridade/categoria; resumir histórico; sugerir checklist/diagnóstico; montar rascunho de orçamento; detectar anomalias de estoque/caixa; prever reposição; priorizar follow-ups; resumir indicadores; auxiliar busca no manual; orquestrar notificações via n8n.

Guardrails obrigatórios:

- IA nunca acessa SQL, shell, credenciais ou infraestrutura diretamente.
- Ferramentas determinísticas de domínio com schemas, tenant obrigatório, menor privilégio e allowlists.
- Leitura separada de escrita; aprovação humana para orçamento, status, pagamento, comunicação ou fiscal.
- Registro de prompt/contexto/resultado/ação, redaction de PII, retenção definida e avaliação contínua.
- Proteção contra prompt injection em anexos/mensagens; limites de custo, taxa e escopo.
- Resposta deve citar fontes internas e expressar incerteza; diagnóstico técnico nunca deve ser executado automaticamente.
- n8n/agentes usam service accounts específicas e eventos outbox, não credenciais humanas.

## 17. Estratégias de migração

| Critério | Reescrita total | Incremental / Strangler |
|---|---|---|
| Risco de perder regras | Muito alto até paridade | Menor se contratos e comparação dual forem usados |
| Prazo até primeira entrega | Longo | Curto por módulo |
| Coexistência | Menor após big-bang, difícil antes | Exige gateway, identidade e sincronização temporária |
| Banco | Migração grande e rollback complexo | Migra por ownership; dual-write/CDC traz complexidade |
| Rollback | Difícil após corte | Por rota/feature flag, geralmente melhor |
| Liberdade arquitetural | Máxima | Limitada temporariamente pelos contratos legados |
| Custo operacional | Pico próximo ao corte | Custo prolongado de dois sistemas |

Uma reescrita total simplifica o estado final, mas demanda congelamento/replicação contínua de regras e um ensaio de migração completo. O Strangler permite aprender e reduzir blast radius, porém só funciona com ownership claro de dados; dual-write ingênuo deve ser evitado.

Sequência de avaliação recomendada: inventário do banco real → glossário/invariantes → testes de caracterização → mapa de contratos → escolher módulo piloto periférico (por exemplo ajuda/solicitações, não caixa/OS) → gateway/SSO/contexto tenant → shadow reads e reconciliação → rollout por tenant → rollback testado. Não há informação suficiente para decisão definitiva.

## 18. Riscos

| Risco | Probabilidade/impacto | Mitigação antes do corte |
|---|---|---|
| Perda de regra implícita | Alta/Crítico | testes de caracterização e catálogo de invariantes |
| Vazamento cross-tenant | Média/Crítico | constraints, RLS, matriz de autorização e testes negativos |
| Divergência financeira/estoque | Média/Crítico | ledger/reconciliação, locks e idempotência |
| Schema real divergir de migrations | Média/Alto | dump estrutural e profiling anonimizados |
| Jobs/comunicações duplicados | Média/Alto | outbox, idempotency keys e observabilidade |
| Migração fiscal prematura | Alta/Alto | preservar manual; isolar futura API atrás de adapter/flag |
| PDFs/documentos divergirem | Média/Alto | golden files e homologação jurídica/operacional |
| Queda de produtividade por redesign | Média/Alto | pesquisa, protótipos e rollout por tenant |
| Coexistência complexa | Alta/Alto | ownership único por agregado e rollback ensaiado |
| Cobertura enganosa | Média/Alto | corrigir ambiente CI e publicar cobertura por risco |

## 19. Achados P0/P1/P2/P3

### P0 — Crítico

- Formalizar invariantes de tenant e testar toda rota/job antes de qualquer migração.
- Inventariar e reconciliar schema/dados reais, sobretudo OS, estoque, caixa, venda, pagamentos e recebíveis.
- Criar contratos executáveis dos fluxos listados na seção 14.
- Definir estratégia de consistência/rollback sem dual-write descontrolado.
- Corrigir o ambiente de testes (driver SQLite ausente nesta execução) para obter baseline confiável.

### P1 — Importante

- Assinatura oficial e anti-replay no webhook Mercado Pago; remover segredo da URL/log.
- Endurecer portal público, rate limits e ciclo de vida dos tokens.
- Remover SVG ativo do upload público ou sanitizar/re-encodar de forma comprovável.
- Substituir `Model::unguard()` global por atribuição explícita.
- Matriz de permissões; validar equivalência operador/admin e proteção do último administrador.
- Extrair domínio dos controllers grandes e definir fontes canônicas para orçamento/financeiro/status.
- Contexto tenant explícito para workers, SMTP, cache e arquivos.

### P2 — Melhorias

- Modularizar páginas, padronizar formulários/erros/loading e introduzir TanStack Query onde houver benefício.
- Consolidar migrations somente depois de validar produção e ambiente limpo.
- E2E, acessibilidade, performance, cobertura e testes de concorrência.
- Docker Compose/Nginx, build multi-stage, health checks, backups e runbooks versionados.
- Observabilidade estruturada e painel de reprocessamento.

### P3 — Evolução futura

- Integração fiscal governamental quando contratos legais estabilizarem.
- IA assistiva e n8n com ferramentas limitadas/auditadas.
- Previsão de estoque, priorização operacional e detecção de anomalias.
- Offline/PWA no app técnico, condicionado a estudo de uso.

## 20. Recomendações para a próxima etapa

1. Restaurar a execução completa dos testes e registrar cobertura/baseline de tempo.
2. Extrair schema do ambiente real, cardinalidades, tamanho, dados inválidos e consultas lentas, com anonimização.
3. Realizar workshops curtos com CEO/COO/CTO, atendente, técnico e financeiro para validar glossário e seção 14.
4. Produzir matriz `rota/use case × papel × tenant × efeito` e threat model dos fluxos públicos/financeiros.
5. Documentar estados e transições de OS, agenda, venda, caixa, assinatura, follow-up e contratos.
6. Congelar contratos essenciais por testes de caracterização/API e golden files de documentos.
7. Fazer prova técnica pequena de Fastify/PostgreSQL/RLS/outbox sem tocar produção nem assumir a estratégia final.
8. Comparar reescrita total e Strangler com estimativas, equipe, janela de corte, coexistência e rollback reais.

---

### Evidências principais consultadas

`composer.json`, `package.json`, `Dockerfile`, `README.md`, `bootstrap/app.php`, `routes/*.php`, `app/Tenantable.php`, `app/Models/Scopes/TenantScope.php`, `app/Models/User.php`, `app/Policies/*`, `app/Http/Controllers/**`, `app/Services/*`, `app/Events/*`, `app/Listeners/*`, `app/Console/Commands/*`, `database/migrations/*`, `resources/js/pages/**`, `resources/js/components/**`, `resources/js/Utils/navLinks.ts`, `tests/**`, `config/{auth,cors,database,filesystems,mail,queue,sanctum,services,session}.php` e `.github/workflows/ci.yml`.
