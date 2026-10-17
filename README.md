# VetorOS

Plataforma web para assistências técnicas com gestão de atendimento, clientes, ordens, follow-ups, cobranças, indicadores, financeiro e portal público do cliente.

## Stack

- PHP 8.2
- Laravel 12
- React 19 + TypeScript
- Inertia.js
- Tailwind CSS 4
- shadcn/ui + Radix UI

## Principais módulos

- Cadastros de clientes, equipamentos, serviços, peças e checklists
- Ordens de serviço com orçamento, pagamentos, comprovantes e recibos
- Portal público da OS por `tracking_token`
- Follow-ups de orçamento e cobrança
- Tarefas operacionais e performance comercial
- Indicadores de qualidade, garantia e avaliações de clientes
- Vendas, despesas e caixa
- Configuração SMTP por tenant

## Requisitos

- PHP 8.2+
- Composer
- Node.js 20+
- Banco de dados compatível com Laravel

## Instalação

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run dev
```

## Comandos úteis

```bash
composer dev
php artisan test
npm run types
```

No estado atual do projeto, `php artisan test` e `npm run types` devem permanecer verdes antes de qualquer deploy.

## CI

O repositório possui uma workflow de CI em `.github/workflows/ci.yml` para validar automaticamente:

- `npm run types`
- `php artisan test`

## Produção

Antes de publicar em ambiente real, siga o checklist versionado em [docs/production-checklist.md](docs/production-checklist.md).

Se o deploy for em HostGator Turbo compartilhado, siga também o guia específico em [docs/deploy-hostgator-turbo.md](docs/deploy-hostgator-turbo.md).

## Emissão fiscal e comprovantes

O fluxo fiscal padrão do VetorOS é manual, com atalho para os emissores oficiais do governo:

- na ordem ou na venda, o botão "Emitir NFS-e"/"Emitir NF-e" abre um resumo dos dados (cliente, itens, valores) e um link que leva direto ao **Emissor Nacional da NFS-e** (`nfse.gov.br`) ou ao **portal da NF-e** (`nfe.fazenda.gov.br`)
- a emissão em si acontece no site do governo, fora do VetorOS
- depois de emitido, o número do documento e o link de consulta são registrados manualmente na ordem/venda, para consulta e auditoria posterior

Não há, hoje, integração automática via API com nenhum emissor. A antiga integração com a Focus NFe (`FocusNfeService`, `AdminFocusNfeService`) foi removida do projeto em 2026-07-13: a decisão do produto foi não depender de atravessador para emitir notas, e integrar diretamente com as APIs governamentais. Esse projeto está pausado por enquanto por causa das mudanças em curso na legislação fiscal (Reforma Tributária), com intenção de retomar.

Como preparo para essa retomada, o cadastro de configurações fiscais (`Outras configurações`) já expõe uma opção "Automática pelas APIs governamentais" (`fiscal_provider = government_api`), liberada por uma flag por tenant (`automatic_fiscal_emission_enabled`, controlada apenas pelo admin da VetorOS em `Admin > Tenants`, não pelo próprio tenant). Hoje essa opção só mostra os campos de configuração — não existe nenhum código que efetivamente chame uma API de emissão. Não habilite essa flag para tenants em produção até a integração ser implementada.

O NCM e o CFOP são configurados individualmente no cadastro de cada peça/produto (não são obrigatórios nem bloqueiam a emissão, já que a emissão não é feita pelo próprio VetorOS):

- NCM: classificação fiscal da mercadoria, informada com 8 dígitos
- CFOP: natureza fiscal da operação do produto, informada com 4 dígitos

Esses códigos devem ser confirmados na nota fiscal de compra, com a contabilidade ou na legislação aplicável. Serviços de uma ordem não utilizam NCM; na NFS-e, a classificação ocorre pelo item da lista de serviço.

No atendimento, uma impressora térmica pode ser usada para imprimir um comprovante da venda ou do serviço. Esse documento deve ser identificado como `COMPROVANTE NÃO FISCAL — NÃO SUBSTITUI A NOTA FISCAL`. Quando o cliente precisar do documento fiscal, o link registrado manualmente pode ser aberto e impresso em A4 ou enviado em PDF.

### NFC-e e cupom fiscal

A NFC-e (modelo 65) é o cupom fiscal eletrônico normalmente impresso em bobina, com QR Code no DANFC-e. Ela não está implementada no fluxo fiscal atual do VetorOS.

## Configurações gerais

A tela `Outras configurações` foi organizada em duas abas:

### Sistema e SMTP

- Habilitação de recursos financeiros e operacionais do sistema
- Configuração SMTP por tenant
- Envio de e-mail de teste

### Operacionais

- Visibilidade dos menus operacionais
- Meta de retorno em garantia
- Intervalo de follow-up com cliente
- Prazo para solicitar avaliação do cliente
- Metas comerciais

## Novas features recentes

### 1. Visibilidade dos menus operacionais

Agora é possível mostrar ou ocultar menus diretamente em `Outras configurações`:

- `Acompanhamentos`
- `Tarefas`
- `Perf. comercial`
- `Garantia/Avaliações`

Essas opções controlam a navegação do app e são persistidas por tenant na tabela `others`.

### 2. Follow-up automático com chave liga/desliga

Foi adicionada a opção `Envio automático de follow-up`.

Comportamento:

- se estiver desabilitada, o sistema não envia follow-up automático de orçamento nem de cobrança
- se estiver habilitada, o sistema respeita o campo `Dias entre contatos`

Campos relacionados:

- `automatic_follow_ups_enabled`
- `communication_follow_up_cooldown_days`

### 3. Portal público da ordem de serviço

O acompanhamento público da OS foi consolidado em rotas como:

- `/os/{token}`
- `/os/{token}/receipt/{type}`
- `/os/{token}/payment-proof`
- `/os/{token}/fiscal-proof`

Capacidades:

- acompanhar status da OS
- aprovar ou reprovar orçamento
- confirmar aviso de conclusão
- confirmar retirada
- enviar avaliação do atendimento
- consultar comprovantes públicos da ordem

O fluxo público usa `tracking_token` único por ordem.

### 4. Feedback do cliente simplificado

O painel público do cliente foi simplificado para deixar a avaliação mais direta:

- nota em 5 estrelas
- opinião textual

### 5. Importação de clientes por CSV

A importação foi ajustada para ficar mais tolerante a arquivos grandes e dados inconsistentes:

- e-mails repetidos são aceitos
- CPF/CNPJ inválido ou repetido não é mais bloqueio na importação
- lotes com erro fazem fallback por linha
- a resposta final informa melhor quantidades importadas e falhas

## Multi-tenant, isolamento e segurança

O projeto trabalha com isolamento por tenant.

Endurecimentos recentes:

- configurações de `Other`, `Company` e branding carregadas por `tenant_id`
- `TenantScope` prioriza `auth()->user()->tenant_id`
- `Tenantable` preenche `tenant_id` automaticamente e restringe route model binding ao tenant atual
- policies críticas passaram a validar tenant explicitamente
- fluxos públicos da OS foram ajustados para resolver a ordem por `tracking_token` sem depender do tenant autenticado na sessão

## Migrations importantes recentes

Se o ambiente estiver desatualizado, rode:

```bash
php artisan migrate
```

Migrations recentes relacionadas a novas features:

- `2026_04_17_133257_drop_customers_tenant_id_cpfcnpj_unique`
- `2026_04_17_154103_add_menu_visibility_flags_to_others_table`
- `2026_04_17_160523_add_automatic_follow_ups_enabled_to_others_table`
- `2026_10_17_200000_create_support_sessions_table` (Modo Suporte do RootAdmin)
- `2026_10_17_210000_add_commercial_whatsapp_settings` (contato comercial configurável)

## Estratégia de baseline das migrations

O projeto já acumulou muitas migrations incrementais. Para enxugar esse histórico sem quebrar clientes em produção, a estratégia recomendada é criar uma nova baseline por blocos funcionais, mantendo compatibilidade com ambientes já migrados.

Blocos atuais do schema:

- `core`: planos, tenants, usuários, cache, jobs, personal access tokens
- `cadastros`: clientes, equipamentos, serviços, checklists, mensagens, agendamentos
- `ordens`: orders, order_parts, order_status_history, order_payments, order_logs, images, receipts, companies, whatsapp_messages
- `financeiro`: sales, sale_items, sale_logs, expenses, expense_logs, cash_sessions, cash_session_logs, payments
- `configuracoes`: others, settings, branches
- `qualidade-operacao`: garantia, avaliações, follow-ups, operational_audits

Sequência segura para enxugar:

1. Congelar novas mudanças estruturais enquanto a baseline é montada.
2. Gerar a estrutura final do banco em ambiente limpo.
3. Criar novas migrations consolidadas por bloco, com o schema final já pronto.
4. Validar `migrate:fresh --seed` com os seeders atuais.
5. Só depois aposentar o histórico antigo.

Regras práticas:

- não apagar migrations antigas antes de validar a nova baseline em banco limpo
- não misturar refactor de código com refactor estrutural de banco na mesma entrega
- manter nomes e tipos finais das colunas exatamente como estão em produção
- revisar defaults importantes, especialmente em `others`, `orders`, `sales` e `cash_sessions`

Prioridade de consolidação:

1. `others` e blocos de configuração
2. `orders` e extensões de follow-up/garantia/feedback
3. `sales`, `expenses` e `cash_sessions`
4. tabelas administrativas (`periods`, `features`, `branches`, `settings`)

Checklist mínimo antes de apagar o histórico antigo:

- `php artisan migrate:fresh --seed` sobe sem erro
- login funciona
- tenant demo é criado corretamente
- OS, pagamentos, vendas e caixa funcionam
- portal público por `tracking_token` funciona
- SMTP/teste de e-mail funciona
- menus operacionais respeitam os defaults novos

Observação: hoje os seeders já foram ajustados para o estado atual do produto, então o caminho correto é primeiro validar `migrate:fresh --seed` localmente e só depois iniciar o squash real das migrations.

## Estrutura resumida

- `app/Http/Controllers/App`: controllers autenticados do painel
- `app/Http/Controllers/OsController.php`: portal público da OS
- `resources/js/pages/app`: telas React do painel
- `resources/js/pages/app/serviceorders`: acompanhamento público da OS
- `routes/app.php`: rotas autenticadas
- `routes/web.php`: rotas públicas

## Licença

Uso interno do projeto VetorOS. Ajuste este trecho se houver política formal de licenciamento.
