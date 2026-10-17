# VETOR-UX-ADMIN-03 — Reorganização das telas Empresas e Empresas Emissoras. Resultado: **IMPLEMENTADO E HOMOLOGADO LOCALMENTE; SEM COMMIT, SEM PUSH, SEM DEPLOY, SEM MIGRATION**

Data: 2026-10-17. Executor: estação de desenvolvimento local (WSL).

## Comparação do correio

O `correio.md` mudou desde a execução anterior (VETOR-UX-FISCAL-02, logo abaixo). A instrução atual é a VETOR-UX-ADMIN-03.
- **Parte 2 (Empresas emissoras):** já tinha sido entregue na UX-FISCAL-02 e foi só revalidada (item 5).
- **Parte 1 (RootAdmin → Empresas):** é o trabalho novo.
- As alterações não commitadas das frentes anteriores (CONTRATO-02, EFI-02/03/04, UX-FISCAL-02) foram preservadas.

## 1. Auditoria (antes de alterar)

- **Listagem:**
  - 14 colunas: #, contato, razão social, CNPJ, e-mail, telefone, status, assinatura, criação, plano, período, vencimento, restante e ações.
  - 4 botões por linha: WhatsApp, editar, prévia do e-mail e excluir.
  - Linhas coloridas sem variante escura.
- **Cadastro:** formulário único (dados, endereço, plano, status, observações).
- **Edição:** página longa com:
  - 4 cards de resumo;
  - seção comercial (contrato, NFS-e do SaaS, cobranças e diálogos);
  - 10 botões de e-mail (5 prévias e 5 envios reais, sem confirmação);
  - formulário de dados com plano, status e emissão fiscal.
- **Módulos e funcionalidades:** não existe controle por empresa no RootAdmin.
  - Os módulos (peças, vendas, compras, financeiro) são ligados pelo próprio cliente em "Sistema e módulos" (`others`).
  - As funcionalidades pertencem ao plano (`periods.features`).
  - Nenhuma funcionalidade nova foi criada. A aba mostra essas informações em modo leitura, junto com a situação da conta (status), que é o controle de ativação e suspensão que já existia.
- **Decisão:** a edição continua como **página com abas**, e não como Dialog. O formulário é extenso e tem tabela de cobranças com diálogos próprios; o correio permite essa opção quando ela é "mais segura e utilizável". O cadastro usa a mesma estrutura de abas.

## 2. Listagem (RootAdmin → Empresas)

- **Colunas:**
  - **Empresa:** razão social e contato.
  - **CNPJ.**
  - **Modalidade:** "Plano X" ou "Contrato personalizado".
  - **Situação:** selo Ativa/Carência/Bloqueada, mais vencimento e dias restantes.
  - **Ação:** **Gerenciar** e um menu "…" com Enviar WhatsApp, Prévia do e-mail da fatura e Excluir empresa.
- **Mantidos:** busca, os 8 filtros, contador e paginação. A ordem continua sendo a do backend (prioridade de atenção).
- **Exclusão:** passou a usar um AlertDialog controlado, com texto fiel à regra do backend (empresa com contrato não é excluída).
- **Responsivo:** tabela a partir de 768 px e cartões no celular.
- **Estado vazio** com mensagem conforme os filtros. **Selos** com variantes para o tema escuro.

## 3. Cadastro e edição em abas

As 5 abas são Dados gerais, Assinatura e planos, Financeiro e cobranças, Módulos e funcionalidades, e Fiscal.
- **Abas sempre montadas** (`forceMount`; as inativas ficam só ocultas): o que foi digitado continua ao trocar de aba.
- **Aviso de alterações:** "Alterações não salvas…" aparece para o formulário principal e para o comercial.
- **Aba no endereço (`#aba`):** recarregar mantém a aba.
  - Bug encontrado na homologação: o redirecionamento depois de salvar apagava o `#aba`. Corrigido reaplicando a aba no fim de cada visita do Inertia, só na mesma página.
- **Erros de validação:** selo com a contagem em cada aba. Se a aba atual não tem erro, a tela vai para a primeira aba com problema.

**Edição:**
- **Dados gerais:** identificação, contato, observações e endereço com ViaCEP.
- **Assinatura e planos:**
  - Resumo: plano, período, vencimento e dias restantes, agora calculados na tela; antes mostravam "Sem informacao".
  - Plano tradicional, **bloqueado com contrato personalizado**.
  - Contrato personalizado, com volta ao plano tradicional e desagendamento.
- **Financeiro e cobranças:**
  - Próxima cobrança e histórico, com as ações existentes (registrar pagamento, ajustar, estornar, reenviar fatura, emitir e reenviar NFS-e).
  - Comunicações de assinatura: Prévia e Enviar.
- **Módulos e funcionalidades:** situação da conta (status), funcionalidades do plano e módulos ligados pelo cliente, em modo leitura.
- **Fiscal:** emissão fiscal automática, NFS-e do SaaS e um link para Fiscal → Empresas emissoras.
- **Botão "Salvar dados da empresa":** fica numa barra fixa no rodapé. As seções comerciais mantêm o próprio botão.

**Cadastro:** abas Dados gerais, Assinatura e planos, e Módulos (status). Financeiro e Fiscal ficam desativadas, com o aviso "Disponível depois do cadastro".

**Confirmações de ações críticas:**
- **Mantidas:** registrar pagamento, estorno (com senha), ajuste e volta ao plano (com senha e confirmação).
- **Novas:**
  - Ativar contrato personalizado: mostra valor e primeiro vencimento.
  - Desagendar a volta ao plano.
  - **Enviar e-mail real** ao cliente. Antes, um clique enviava direto.

**Outros pontos:**
- **Carregamento:** indicador girando nos botões de salvar e confirmar.
- **Visitas:** usam `preserveState` e `preserveScroll`.
- **Contraste no tema escuro:** switches desligados com borda `slate-300`; medido `oklch(0.869…)` contra fundo `rgb(11,18,32)`, cerca de 12:1. Selos com variantes escuras.

## 4. Backend (mínimo, sem migration)

- **`TenantController::show`:**
  - Carrega `plan.periods.features`.
  - Envia `modules` (os 4 módulos do cliente, só leitura) via `Other::withoutGlobalScopes()->first()`, **sem criar registro**.
- **`TenantController::update`:** corrigido o texto "Empresa atualizada com sucess!" para "sucesso".
- **Inalterados:** rotas, validações, permissões, a trava de plano do contrato (backend), Efí, carência, bloqueio e reativação, Spedy, credenciais e ambientes.

## 5. Homologação (Playwright/Chromium, servidor local com `MAIL_MAILER=log` e `QUEUE_CONNECTION=sync`)

- **Massa de teste:** RootAdmin e plano "HOMOLOG UXA03" (com 2 funcionalidades), mais 3 empresas: plano, bloqueada e contrato com cobrança em aberto.
  - No navegador foram bloqueados os envios de e-mail e as exclusões.
  - **Tudo removido ao final**, incluindo a empresa criada pelo teste (conferido: 0 empresas, usuários e planos de homologação).
- **Cenários:** desktop claro (1366×800), tablet (820×1180), celular (390×844) e desktop escuro. **0 erros de JS e 0 respostas 5xx** em todos.

| Verificação | Resultado |
|---|---|
| Colunas da listagem | Empresa, CNPJ, Modalidade, Situação, Ação (cartões no celular) |
| Filtro "Bloqueadas" | Só a empresa bloqueada |
| Menu "…" e confirmação de exclusão | Itens corretos; Cancelar não exclui |
| Abas da edição e setas do teclado | 5 abas; seta → "Assinatura e planos"; `#subscription` na URL |
| Valor preservado ao trocar de aba | Sim, com aviso de alteração não salva |
| Salvar dados gerais | Persistido após recarregar; revertido depois |
| Emissão fiscal: salvar e reverter | `false → true`, aba mantida (`#fiscal`) depois de salvar |
| Validação | Razão social vazia salva na aba Fiscal → vai a "Dados gerais" com selo de erro |
| Trava do plano no contrato | Seletor de plano desabilitado, aviso exibido, switch do contrato travado |
| Ativar contrato | Confirmação com valor (R$ 1.200,00) e vencimento; Cancelar não grava |
| Cobranças | Cobrança em aberto listada; "Registrar pagamento" abre o diálogo; Cancelar não grava |
| Enviar e-mail | Pede confirmação; Cancelar não envia |
| Módulos e funcionalidades | Funcionalidades do plano listadas; aviso de módulos do cliente |
| Cadastro | Financeiro/Fiscal desativadas; envio vazio marca 3 abas com erro; empresa válida criada (contagem 3 → 4) |
| Rolagem horizontal | Nenhuma em todos os tamanhos |

**Empresas emissoras (parte 2, revalidada):** colunas, selos, abas por teclado, switch sem fechar o modal, senha errada mantendo a confirmação, Esc com foco devolvido e nenhuma chamada à Spedy, nos três cenários. A massa foi removida.

## 6. Testes

- **Novo `tests/Feature/Admin/TenantAdminScreensTest.php`** (3 testes, 70 asserções):
  - Campos e filtros da listagem.
  - Funcionalidades do plano e módulos só leitura na edição, sem criar registro.
  - Bloqueio das telas para usuários de empresa.
- **Suíte completa (`--parallel`):** **761 aprovados**.
- **Frontend:** `tsc --noEmit`, `eslint` e `prettier --check` limpos; `vite build` OK.
- **Permissões do RootAdmin:** cobertas pelo `RootAdminAccessTest` (existente) e pelo teste novo.

## 7. Arquivos

- **Alterados:**
  - `resources/js/pages/admin/tenants/index.tsx`, `create-tenant.tsx` e `edit-tenant.tsx`.
  - `resources/js/components/admin/tenant-commercial-section.tsx`: dividida em `useTenantCommercial`, `CommercialContractPanel`, `SaasInvoicePanel`, `CommercialChargesPanel` e `CommercialDialogs`.
  - `app/Http/Controllers/Admin/TenantController.php`.
- **Novos:**
  - `resources/js/components/admin/tenant-form-fields.tsx` e `resources/js/components/admin/tenant-tabs.tsx`.
  - `tests/Feature/Admin/TenantAdminScreensTest.php`.
- **Empresas emissoras:** `resources/js/pages/admin/fiscal/companies.tsx`, sem mudanças nesta entrega (feito na UX-FISCAL-02).

## 8. Pendências e observações

- **Busca de Empresas emissoras (backend, já existia):** o termo é reduzido aos dígitos para comparar com o CNPJ. "zz-nao-existe-uxf02" vira "02" e encontra qualquer CNPJ que contenha "02". Não alterei, porque esta entrega não muda o backend fiscal. Sugestão: só comparar o CNPJ quando o termo for numérico.
- **Ativação e suspensão de módulos por empresa** não existe no RootAdmin (os módulos são do cliente). Se for desejada, é funcionalidade nova, com regra e auditoria a definir.
- **Mensagem do cadastro:** "Empresa cadastrado com sucesso!" (concordância) continua no backend; não alterei.
- **Vite em modo dev:** durante a homologação havia um processo do Vite rodando nesta máquina (`public/hot`), que não foi iniciado por mim e continua ativo.

---

# VETOR-UX-FISCAL-02 — Simplificação das Empresas Emissoras. Resultado: **IMPLEMENTADO E HOMOLOGADO LOCALMENTE; SEM COMMIT, SEM PUSH, SEM DEPLOY, SEM CHAMADAS À SPEDY**

Data: 2026-10-16. Executor: estação de desenvolvimento local (WSL).

## Comparação do correio

O `correio.md` mudou desde a execução anterior (VETOR-SAAS-EFI-04, logo abaixo). A instrução atual é a VETOR-UX-FISCAL-02, sem execução anterior registrada. As alterações não commitadas das frentes CONTRATO-02 e EFI-02/03/04 foram preservadas.

## 1. Escopo

- **Só frontend:** `resources/js/pages/admin/fiscal/companies.tsx`.
- **Inalterados:** controller (`FiscalCompanyController`), rotas, contratos JSON e Inertia, permissões (`root.admin`), serviços Spedy e migrations.
- **Backend:** todas as regras e bloqueios continuam lá, incluindo a senha obrigatória para aprovar produção, o bloqueio de cadastro sem fiscal habilitado e a auditoria.

## 2. Tabela principal

- **Colunas:**
  - **Empresa:** razão social e CNPJ.
  - **Cadastro Spedy:** Cadastrada, Pendente ou Erro. "Vínculo de outro ambiente" conta como Erro; o detalhe aparece no modal.
  - **Ambiente:** Produção ou Homologação.
  - **Ação:** botão **Configurações**, com `aria-label` que inclui o nome da empresa.
- **Mantidos:** busca por nome/CNPJ e paginação.
- **Novo estado vazio:** "Nenhuma empresa encontrada para …" ou "Nenhuma empresa cadastrada". Antes, a tabela ficava vazia sem aviso.

## 3. Modal de configurações

O modal usa o `Dialog` do shadcn/ui, com `max-w-3xl`, `max-h-[90vh]` e rolagem interna. As abas usam `Tabs`.

- **Geral:**
  - Empresa e CNPJ.
  - Switch "Fiscal habilitado".
  - Situação da emissão por modelo.
  - Ambiente de emissão, com Aprovar ou Revogar produção.
- **Modelos fiscais:** NF-e, NFC-e e NFS-e, cada uma com switch próprio, selo Pronta/Impedida, impedimento detalhado e aviso de "desativada pelo cliente".
- **Certificado digital:** situação (Vigente/Pendente), titular, validade, bloqueios ligados ao certificado e orientação de envio pelo titular no VetorOS.
- **Integração Spedy:**
  - Dados: situação do cadastro, identificador remoto, ambiente do vínculo, data do cadastro, última sincronização e erro da integração.
  - Ações existentes: Cadastrar/Sincronizar e Consultar cadastro e certificado.
  - O retorno do diagnóstico aparece dentro do modal.

## 4. Regras atendidas

- **Confirmação de ação crítica:**
  - O `window.prompt` foi substituído por um `AlertDialog` com campo de senha.
  - Senha errada mostra "Senha incorreta." e mantém a confirmação aberta.
  - Revogar produção também passou a pedir confirmação; antes, não pedia.
- **Switches não fecham o modal:** as ações usam `preserveState` e `preserveScroll`. A empresa exibida é relida das props pelo id, então os dados se atualizam sem perder a empresa nem a aba.
- **Retorno de cada ação:** a mensagem de sucesso ou erro aparece no topo do modal (`role=status`/`alert`), além do toast global.
- **Carregamento:** indicador girando na ação em andamento e uma ação por vez.
  - Os controles não ficam `disabled` durante o envio. O `disabled` tirava o foco do teclado (bug encontrado na homologação); agora é usado `aria-disabled` com trava no envio.
- **Foco:**
  - Ao fechar o modal, o foco volta ao botão "Configurações" que o abriu.
  - Ao fechar a confirmação, volta ao botão de produção.
  - A confirmação fica dentro da árvore do modal, para o Radix não ler o clique nela como "clique fora".
- **Tema escuro:** o trilho desligado do switch ganhou a borda `slate-300` sobre `slate-600`.
  - Medido: borda `oklch(0.869 …)` contra o fundo `rgb(11,18,32)`, contraste ≈ 12:1 (WCAG 1.4.11 pede 3:1).
  - Sem a borda, o trilho medido ficava entre 1,04 e 2,21:1.
  - A página não tem checkboxes. O componente global `Switch` não foi alterado; o ajuste vale só para esta tela.

## 5. Homologação (Playwright/Chromium headless, servidor local)

- **Massa de teste:** um RootAdmin e 3 empresas descartáveis "HOMOLOG UXF02": cadastrada, com erro e pendente.
  - O vínculo Spedy da empresa cadastrada é falso. **As rotas `register` e `check` foram bloqueadas no navegador**, e nenhuma requisição chegou a elas.
  - **Tudo foi removido ao final** (conferido: 0 empresas e 0 usuários restantes).
- **Cenários:** desktop claro (1366×800), celular claro (390×844) e desktop escuro.

| Verificação | Resultado nos 3 cenários |
|---|---|
| Colunas da tabela | Empresa, Cadastro Spedy, Ambiente, Ação |
| Selos | Cadastrada, Erro, Pendente |
| Abrir pelo teclado (foco + Enter) | OK |
| Abas por setas | Geral → Modelos fiscais → Certificado digital → Integração Spedy |
| Alternar NF-e | `false → true`, modal aberto, aba mantida, mensagem "Habilitação fiscal atualizada."; depois revertido |
| Senha errada ao aprovar produção | "Senha incorreta." exibido, confirmação aberta; Cancelar volta ao modal |
| Consultar sem cadastro | Botão desabilitado |
| Erro da integração | Exibido na aba Spedy |
| Esc | Fecha e devolve o foco a "Configurações de …" |
| Estado vazio da busca | OK |
| Rolagem horizontal | Nenhuma |
| Erros JS, respostas 5xx, chamadas à Spedy | 0 |

## 6. Testes

- **Testes fiscais** (`FiscalCentralAdministration`, `SpedyIssuerActivation`, `CompanyFiscalIdentity`): 34 aprovados.
- **Suíte completa (`--parallel`):** 758 aprovados.
- **Frontend:** `tsc --noEmit`, `eslint` e `prettier --check` limpos; `vite build` OK.

## 7. Arquivos alterados

- `resources/js/pages/admin/fiscal/companies.tsx`: único arquivo de produto.
- `executed.md`: este relatório.

## 8. Pendências

- Os testes de backend não cobrem a interface. A validação do comportamento do modal foi a navegação acima, sem teste automatizado de frontend versionado.
- As ações reais de Cadastrar/Sincronizar/Consultar na Spedy não foram acionadas, como pede o correio. O comportamento delas não mudou no backend.

---

# VETOR-SAAS-EFI-04 — Vencimentos, avisos, bloqueios e direcionamento ao pagamento. Resultado: **IMPLEMENTADO E HOMOLOGADO LOCALMENTE; SEM COMMIT, SEM PUSH, SEM DEPLOY, SEM MIGRATION**

Data: 2026-10-16. Executor: estação de desenvolvimento local (WSL). As alterações das frentes CONTRATO-02, EFI-02 e EFI-03 continuam na mesma árvore de trabalho e foram preservadas.

## 1. Fluxos auditados

- **Middleware:** `CheckSubscriptionStatus`.
- **Controllers:** `SubscriptionController` e `SaasCheckoutController`.
- **Serviços:** `SaasPaymentService`, `SaasContractService` e `PlanRenewalService`.
- **Inertia:** `HandleInertiaRequests` (`plans`, `custom_contract`).
- **Telas:** `Subscription/Blocked.tsx`, `SubscriptionBanner`, `SubscriptionModal`, `EfiCheckout` e `SubscriptionPaymentSuccess`, mais o menu (`navLinks.ts`).
- **E-mails de vencimento:** `Tenant::buildSubscriptionNotice`, além dos textos de cobrança do contrato e da Efí.

## 2. Rotas (todas fora do middleware de assinatura, sob `auth`)

| Rota | Nome | Uso |
|---|---|---|
| `GET /subscription` | `subscription.show` | **Nova.** "Minha assinatura": situação e pagamento em qualquer estado |
| `GET /subscription/blocked` | `subscription.blocked` | Destino do middleware. Fora do bloqueio, redireciona para `/subscription` |
| `GET /api/subscription/check` | `subscription.check_status` | Situação calculada no servidor (`access` vem da cobertura) |
| `POST /subscription/efi/{pix,billet,card}` | `subscription.efi.*` | Checkout Efí |
| `POST /subscription/efi/recurring/cancel` | `subscription.efi.recurring.cancel` | Cancelar autorização recorrente |
| `GET /subscription/efi/payments/{payment}` | `subscription.efi.status` | Acompanhamento da transação, isolado por tenant |
| `POST /subscription/efi/payments/{payment}/refresh` | `subscription.efi.refresh` | **Nova.** "Consultar pagamento": o servidor consulta a Efí e concilia |

Rotas antigas `subscription.pay` e `subscription.generate_pix` não existem mais (há teste para isso).

## 3. Problemas identificados e correções

1. **Não havia tela de assinatura fora do bloqueio.**
   - Os avisos só tinham um modal de PIX antigo.
   - **Correção:** criei `/subscription` com a mesma tela da de bloqueio. Ela mostra situação, motivo, vencimento, carência, contratação, valor, cobrança, meios de pagamento e transação em andamento.
   - O menu Configurações ganhou o item **Assinatura**.
2. **O aviso só aparecia na carência.**
   - **Correção:** `App\Support\SubscriptionAlert` agora calcula no servidor os estados `due_soon` (até 3 dias antes), `due_today` e `grace_period`.
   - O banner é discreto perto do vencimento e destacado no dia e na carência. O botão é "Ver assinatura" ou "Pagar agora" e leva a `/subscription`.
   - **A carência de 3 dias foi preservada** (`Tenant::subscriptionBucket`).
3. **Contrato personalizado.**
   - O cliente vê só a cobrança do contrato (número, periodicidade, competência, vencimento e valor), sem catálogo de planos.
   - O valor vem do servidor. Há teste confirmando que parâmetros enviados pelo cliente são ignorados.
4. **Sem forma de reconsultar o pagamento.**
   - **Correção:** botão "Consultar pagamento" ligado à rota `refresh`. A consulta à Efí é autenticada no servidor; se a Efí falhar, nada muda.
   - A transação pendente reaparece depois de recarregar a página.
5. **Liberação antecipada.**
   - `check_status` devolve `paid_pending_obligations` quando o pagamento foi confirmado mas outra competência continua vencida. Nesse caso o acesso não é liberado.
6. **Cache da tela de bloqueio.**
   - A tela é enviada com `Cache-Control: no-store, private`.
   - Ao continuar depois do pagamento, a navegação usa `location.replace`: a página relê a assinatura e a tela de bloqueio sai do histórico.
7. **E-mails de vencimento e textos de cobrança apontavam para `/subscription/blocked`.**
   - Fora do bloqueio isso gerava um salto a mais.
   - **Correção:** agora apontam para `/subscription` (`Tenant.php`, `SaasPaymentService.php`, `SaasContractService.php`).
8. **Modal da carência sem acessibilidade** (uma `div` sem foco preso e sem Esc).
   - **Correção:** passou a usar o `Dialog` do projeto, com Esc e foco preso; o link é do Inertia.
   - O `localStorage` está protegido com try/catch, e o modal mostra uma mensagem própria para o último dia de carência.
9. **Efí indisponível ou não homologada:** a tela mostra uma mensagem amigável, sem redirecionamento e sem outro gateway.
10. **Lint:** o `any` em `EfiCheckout.tsx` (`firstError`) foi tipado.
11. **Segurança (item 9):** `ABRASIL_MAIL_PASSWORD` já está vazia no `.env.example`. `SecretGuardTest`: 8/8 aprovados.

## 4. Testes automatizados

- **`tests/Feature/App/SubscriptionFlowTest.php`:** 16 testes, 260 asserções.
  - Estados: ativo, vencimento próximo, vencimento no dia, carência, bloqueio com rotas de pagamento acessíveis.
  - Plano tradicional versus contrato; rotas antigas removidas.
  - Pagamentos: Pix confirmado com reativação, boleto aguardando compensação com reconsulta, cartão recusado com nova tentativa, cobrança expirada.
  - Casos especiais: pagamento atrasado sem liberação antecipada, Efí indisponível, isolamento multitenant, usuário sem empresa.
- **Já cobertos em `EfiSaasPaymentsTest`:**
  - Webhook duplicado: `test_pix_webhook_confirms_by_api_and_repeated_events_are_ignored`.
  - Recorrência confirmada: `test_recurring_card_requires_authorization_and_reconciles_each_competence`.
  - Falha e cancelamento da recorrência.
- **Suíte completa (`--parallel`):** 758 aprovados em 5 de 7 rodadas.
  - Nas outras 2, falhou só `SpedyIssuerActivationTest > registration links issuer…`, que é intermitente e passa isolado (11/11).
  - Esse teste é da frente fiscal e não foi tocado aqui (ver pendências).
- **Frontend:** `tsc --noEmit` sem erros, `eslint` e `prettier` limpos nos arquivos da frente, `vite build` OK.

## 5. Evidências de navegação (Playwright/Chromium headless, `php artisan serve` local)

- **Massa de teste:** 6 empresas descartáveis "HOMOLOG EFI04 *" e o plano descartável "HOMOLOG EFI04 Mensal" (R$ 89,90).
  - Foram criadas com e-mail em `array` e fila `sync`, sem envio real.
  - **Foram removidas ao final** (conferido: 0 restantes). Planos existentes não foram tocados.
- **Viewports:** desktop 1366×800 e celular 390×844. Resultado: **0 erros de JS, 0 respostas 5xx, 0 ciclos de redirecionamento, sem rolagem horizontal.**

| Estado | Após login | Aviso | Botão do aviso → destino | `/subscription/blocked` | Sair |
|---|---|---|---|---|---|
| Ativa | `/app` | — | — | → `/subscription` | `/login` |
| Vence em 2 dias | `/app` | "vence em 2 dias (18/10/2026)" | Ver assinatura → `/subscription` | → `/subscription` | `/login` |
| Vence hoje | `/app` | "vence hoje (16/10/2026)" | Pagar agora → `/subscription` | → `/subscription` | `/login` |
| Carência | `/app` | "venceu em 15/10/2026… mais 2 dias"; modal abre e fecha com Esc | Pagar agora → `/subscription` | → `/subscription` | `/login` |
| Bloqueada (plano) | `/subscription/blocked` | tela "Acesso suspenso" | — | permanece | `/login` |
| Bloqueada (contrato) | `/subscription/blocked` | só a cobrança VOS-00014, R$ 1.200,00 | — | permanece | `/login` |

Na base local a Efí não está homologada, então todas as telas mostraram a mensagem de pagamento indisponível. O fluxo de checkout real (QR Code, boleto e cartão) está coberto pelo simulador nos testes, não pelo navegador.

## 6. Arquivos desta frente

- **Novos:** `app/Support/SubscriptionAlert.php` e `tests/Feature/App/SubscriptionFlowTest.php`.
- **Alterados (backend):** `app/Http/Middleware/CheckSubscriptionStatus.php`, `app/Http/Controllers/App/SubscriptionController.php`, `app/Http/Controllers/App/SaasCheckoutController.php` (`refresh`) e `routes/web.php`.
- **Alterados (textos de cobrança):** `app/Models/Tenant.php`, `app/Services/Payments/SaasPaymentService.php` e `app/Services/Commercial/SaasContractService.php`.
- **Alterados (frontend):** `resources/js/pages/Subscription/Blocked.tsx`, `resources/js/components/Payment/{SubscriptionBanner,SubscriptionModal,EfiCheckout}.tsx` e `resources/js/Utils/navLinks.ts`.

## 7. Pendências reais

- **Planos locais sem preço:** todos os planos da base local valem R$ 0,00. Uma empresa de plano nessa situação vê "Fale com o atendimento", porque não há renovação a cobrar. **Confirmar que os planos de produção têm valor** antes da implantação. Produção não foi inspecionada.
- **Checkout Efí real no navegador** (Pix, boleto e cartão) depende de credenciais e homologação da Efí. Até lá, só foi validado contra o simulador.
- **`SpedyIssuerActivationTest`** falha de forma intermitente na execução paralela (2 de 7 rodadas). Investigar na frente fiscal.
- **E-mails de vencimento:** `buildSubscriptionNotice` retorna `null` sem `plan_id`. Contratos personalizados recebem a fatura do contrato, não esses lembretes. Isso não foi alterado.

---

# VETOR-SAAS-EFI-03 — Exclusividade Efí Bank e remoção do Mercado Pago. Resultado: **IMPLEMENTADO E VALIDADO LOCALMENTE; SEM COMMIT, SEM PUSH, SEM DEPLOY, SEM ALTERAÇÃO NAS CONTAS EFÍ/MERCADO PAGO**

Data: 2026-10-16. Executor: estação de desenvolvimento local (WSL).

## Comparação do correio

`correio.md` estava diferente da versão executada antes (VETOR-SAAS-EFI-02, logo abaixo). A instrução atual é a VETOR-SAAS-EFI-03. As alterações não commitadas da CONTRATO-02 e da EFI-02 foram preservadas e continuam na mesma árvore de trabalho.

## ⚠ Alerta de segurança (outra frente, não alterado)

Durante esta execução, às 11:18, o `.env.example` (versionado) recebeu de outra frente a troca de `MAIL_*` por `ABRASIL_MAIL_*`, com **`ABRASIL_MAIL_PASSWORD` preenchida com o mesmo valor do `.env` local**, ou seja, uma credencial real. O valor não foi exibido em nenhum momento.
- Não alterei essa linha, porque o correio pede para preservar as alterações das outras frentes.
- **Esvazie o valor antes de qualquer commit.** O hook de pre-commit e o `SecretGuardTest` já barram esse arquivo.
- Se esse arquivo já tiver saído desta máquina, trate a senha como exposta e troque-a.

É a única falha da suíte completa (ver item 6).

## 1. Inventário antes da remoção (somente leitura)

- **Banco local `vetoros`** (dados de desenvolvimento, não de produção): 3 pagamentos do Mercado Pago, todos `approved` (R$ 421,16); nenhum pendente, estornado ou contestado; nenhuma NFS-e do SaaS ligada a eles; 2 tenants com `last_payment_id`.
- O VetorOS nunca teve assinatura recorrente no Mercado Pago, só PIX avulso. Não há recorrência a encerrar.
- **Produção não foi inspecionada.** O inventário real sai na implantação com `php artisan vetoros:legacy-payments-report` (item 7).

## 2. Arquivos removidos

- `app/Services/MercadoPagoService.php`: cliente do SDK
- `app/Http/Controllers/App/WebhookController.php`: webhook do Mercado Pago
- `app/Http/Controllers/App/PaymentController.php`: geração do PIX antigo
- `tests/Feature/App/PaymentControllerTest.php` e `tests/Feature/App/WebhookControllerTest.php`: testes exclusivos do Mercado Pago
- Dependência `mercadopago/dx-php` (`composer remove`; o lockfile só perdeu esse pacote)
- Rotas `subscription.pay`, `subscription.generate_pix` e `api/webhooks/mercadopago/{token}`; exceção de CSRF do webhook antigo
- `config/services.php` (bloco `mercadopago`) e `.env.example` (`MP_ACCESS_TOKEN`, `MP_PUBLIC_KEY`, `MP_WEBHOOK_TOKEN`)
- Seletor de provedor no RootAdmin, com rota e ação; coluna `saas_payment_settings.active_provider`
- Fallback automático para o Mercado Pago sem homologação, e reaproveitamento do PIX do Mercado Pago (`reusablePixFor`)
- `docker-compose.yml` da infra: `MP_*` removidas **só do serviço `vetoros`**; o `vetorpet` não foi tocado

## 3. Arquivos alterados

**Gateway único:**
- `app/Models/Admin/SaasPaymentSetting.php`: sem provedor. `usesEfi()` depende só da homologação; `blockedReason()` dá a mensagem administrativa.
- `app/Services/Payments/SaasPaymentService.php`:
  - Bloqueio com mensagem quando a Efí não está configurada ou homologada, sem nunca usar outro gateway. O agendador audita `saas_payment.collection_blocked`.
  - Trava de transição: com pagamento anterior pendente e válido, nada novo é criado para a empresa.
- `app/Http/Controllers/App/SaasCheckoutController.php`: só Efí; sem meio disponível, a tela recebe `unavailable_message`.
- `app/Http/Controllers/Admin/Finance/EfiSettingsController.php`, `routes/admin-finance.php`, `resources/js/pages/admin/finance/efi.tsx`:
  - O seletor de provedor virou **Homologar / Suspender**. Suspender bloqueia novas cobranças e mantém a conciliação do que já existe.
  - Mostra o motivo do bloqueio e o resumo do histórico anterior, somente leitura.

**Telas do tenant:**
- `resources/js/pages/Subscription/Blocked.tsx`, `resources/js/components/Payment/SubscriptionModal.tsx`, `resources/js/components/Payment/EfiCheckout.tsx`: sem fluxo Mercado Pago; só o checkout Efí ou o aviso.
- `app/Http/Middleware/CheckSubscriptionStatus.php`: libera as rotas `subscription.efi.*` para a empresa bloqueada.

**Histórico preservado e legível:**
- `app/Models/App/Payment.php`: `isSettled()`, escopo `settled()`, `billedPlanId()` e `settledAt()` leem tanto a Efí quanto o histórico.
- **Lacuna corrigida da EFI-02:** a NFS-e de plano pago pela Efí não saía, porque o código só reconhecia `approved` e o plano no `raw_response`.
  - Agora funciona em `app/Services/Fiscal/SaasInvoiceService.php` e `Admin/Fiscal/SaasInvoiceController.php`.
  - Pagamento que quita uma competência de contrato é recusado ali, porque tem nota própria pela cobrança. Isso evita nota em dobro.
- `app/Http/Controllers/Admin/TenantController.php` (prévia da fatura paga) e `resources/views/emails/subscription-invoice-paid.blade.php` (status `paid`).
- `app/Services/Commercial/SaasContractService.php` e `PlanRenewalService.php`: textos e comentários.

**Novos:**
- `app/Services/Payments/LegacyPaymentsReport.php` e o comando `vetoros:legacy-payments-report`: inventário somente leitura. O comando falha enquanto houver pendente válido.
- Migration `database/migrations/2026_10_16_140000_remove_provider_switch_from_saas_payment_settings.php`.
- `tests/Concerns/FakesEfi.php`: o simulador da Efí extraído para reuso.

**Testes, dados de exemplo e documentação:**
- Testes: `EfiSaasPaymentsTest`, `SaasCommercialContractTest`, `SaasContractPlanLockTest` e `FiscalCentralAdministrationTest`, todos só com Efí.
- `database/factories/App/PaymentFactory.php` e `database/seeders/DatabaseSeeder.php`: dados de exemplo no formato Efí.
- `docs/production-checklist.md` e `docs/deploy-hostgator-turbo.md`: operação só com Efí.
- `VETOROS_AVALIACAO_TECNICA.md`: nota de atualização no topo.

## 4. Migration

`2026_10_16_140000_remove_provider_switch_from_saas_payment_settings`:
- Remove `saas_payment_settings.active_provider` (configuração, não histórico).
- Muda **só o default** de `payments.gateway` para `efi`.

**Nenhuma linha é alterada e nenhuma tabela ou coluna de histórico sai.** O `down()` restaura as duas mudanças. A migration da EFI-02 (já aplicada no banco local, lote 35) não foi reescrita; só o comentário dela foi atualizado.

## 5. Referências restantes ao Mercado Pago (justificadas)

| Onde | Motivo |
|---|---|
| `scripts/security/SecretGuard.php`, `.gitleaks.toml`, `tests/Unit/Security/SecretGuardTest.php` | Detecção de credenciais expostas, preservada a pedido do correio |
| Registros em `payments` com `gateway = mercadopago` | Histórico financeiro: ids externos, valores, datas e resposta original intactos |
| `2026_01_23_165507_create_payments_table.php` (`default('mercadopago')`) | Migration histórica já aplicada; o default foi corrigido pela migration nova |
| `2026_10_16_130000_create_efi_saas_payments.php` (definição de `active_provider`) | Migration já aplicada; a coluna é removida pela `140000` |
| Testes com `gateway = 'mercadopago'` e o 404 em `/api/webhooks/mercadopago/...` | Provam que o histórico é legível e que a rota antiga não existe |
| `VETOROS_AVALIACAO_TECNICA.md` | Diagnóstico de 2 de setembro, mantido como registro, com nota |
| `executed.md`, `correio.md` | Registros históricos das execuções |
| `docker-compose.yml` da infra, serviço `vetorpet` | Outra aplicação; fora do escopo |

A busca final não encontrou nada em `app/`, `routes/`, `config/`, `resources/`, `bootstrap/`, `composer.json`, `.env.example`, no bundle do frontend (`public/build`) nem na tabela de rotas.

## 6. Testes executados

- Suíte completa (SQLite): **742 testes, 741 passaram**. A única falha é o `SecretGuardTest` do `.env.example`, causada pela credencial da outra frente (alerta acima).
- **No MariaDB:** como da outra vez, por orientação do responsável, as migrations rodaram no banco descartável `vetoros_migration_test` do MariaDB 11.8.6 local, e não em MySQL 8.4.
  - `migrate:fresh` limpo, e a migration nova desfaz e reaplica sem erro.
  - Com um pagamento antigo do Mercado Pago gravado antes, a migration mantém a linha idêntica.
  - Suítes Efí, contrato, trava de planos e fiscal: **79 testes passaram**.
- Cobertura específica desta entrega:
  - Pix, boleto, cartão, recorrência e estornos, só pela Efí (22).
  - Sem homologação: cobrança bloqueada com mensagem, nenhuma chamada externa e rota antiga 404.
  - Trava de transição com pagamento anterior pendente, e o relatório falhando enquanto ele vale.
  - Homologar e suspender, inclusive a recusa de qualquer "outro provedor".
  - Contratos e planos pagos pela Efí: idempotência, estorno, reversão para plano, conversão com Pix antigo virando divergência e isolamento entre tenants.
  - NFS-e de plano pago pela Efí, e recusa para pagamento de competência de contrato.
- Pint: ok. Prettier nos arquivos alterados: ok. `tsc --noEmit`: ok. `npm run build`: ok. Guarda de segredos nos 63 arquivos desta entrega: ok.

## 7. Procedimento para as cobranças legadas (antes da implantação)

1. Em produção, antes do deploy: `php artisan vetoros:legacy-payments-report` (somente leitura).
2. Se houver **pendente ainda válido**, o comando falha e lista cada um. Para cada um:
   - Consultar no painel do Mercado Pago.
   - Se foi pago, registrar a quitação manual no RootAdmin: cobrança do contrato com "Registrar pagamento", ou ajuste do vencimento no plano.
   - Se não foi pago, aguardar a expiração. Nada é recriado na Efí automaticamente.
3. Fazer o deploy só com o comando terminando sem pendente válido. Depois dele, a rota antiga deixa de existir; o Mercado Pago desiste das notificações.
4. Mesmo assim, no código: enquanto existir pendente antigo válido, a empresa não recebe cobrança nova (sem pagamento em dobro).
5. Nada foi alterado nas contas do Mercado Pago ou da Efí. Desativar o webhook e as credenciais no painel do Mercado Pago é decisão do responsável, depois do passo 3, e sem afetar o VetorPet.

## 8. Pendências

- **Esvaziar `ABRASIL_MAIL_PASSWORD` no `.env.example`** (alerta acima).
- Repetir as migrations em MySQL 8.4 na homologação.
- As pendências de credenciais e homologação da Efí da EFI-02 continuam valendo.
- Os incidentes de credenciais antigas no histórico Git seguem sem reescrita, como pede o correio.
- O banco descartável `vetoros_migration_test` continua no MariaDB local: `DROP DATABASE vetoros_migration_test;`.

---

# VETOR-SAAS-EFI-02 — Implementação completa Efí Bank. Resultado: **IMPLEMENTADO E VALIDADO LOCALMENTE CONTRA SIMULADOR; SEM CREDENCIAIS EFÍ, SEM COBRANÇA REAL, SEM COMMIT, SEM PUSH, SEM DEPLOY**

Data: 2026-10-16. Executor: estação de desenvolvimento local (WSL). Nenhum push, deploy, migration de produção ou chamada real à Efí.

## Comparação do correio

`correio.md` estava diferente da versão executada antes (VETOR-SAAS-CONTRATO-02, registrada logo abaixo). A instrução atual é a VETOR-SAAS-EFI-02. Não havia código Efí no repositório nem registro de uma EFI-01. As alterações não commitadas da CONTRATO-02 foram preservadas e continuam na mesma árvore de trabalho.

## 1. Arquitetura (sem novo motor de assinatura)

- A competência continua sendo a `TenantSubscriptionCharge` do contrato (`SaasContractService`) ou a renovação do plano tradicional. A renovação do plano foi extraída do webhook do Mercado Pago para `PlanRenewalService`, e o **mesmo** serviço atende o Mercado Pago e a Efí.
- `SaasPaymentService` cria e concilia a transação externa e aplica o resultado pelo motor existente (`registerPayment`, `refund`, `syncCoverage`). Carência, bloqueio e reativação seguem a regra atual de `tenants.expires_at` / `subscriptionBucket`; não há política nova.
- As transações da Efí reutilizam a tabela `payments` (a mesma do Mercado Pago), agora ligadas à cobrança local (`tenant_subscription_charge_id`) ou ao plano (`plan_id`), com identificação única local (`reference`) e o id do provedor (`payment_id` = txid do Pix ou `charge_id` da API Cobranças).
- Clientes HTTP próprios (`EfiPixClient`, `EfiChargesClient`) sobre o cliente HTTP do Laravel, em vez do SDK PHP: cada chamada fica explícita, testável e sem dependência nova no backend. No frontend entrou só a biblioteca oficial de tokenização `payment-token-efi@3.4.1` (no `package-lock.json`, que é o lockfile usado pelo `npm ci` do Dockerfile).

## 2. Funcionalidades concluídas

**Pix (API Pix):**
- Cobrança imediata (`PUT /v2/cob/{txid}`) com txid único de 32 caracteres (`VOS` + 29 alfanuméricos) e expiração de 24 h.
- QR Code e copia e cola (`GET /v2/loc/{id}/qrcode`).
- Reutilização da cobrança pendente válida do mesmo alvo e valor.
- Confirmação só por consulta (`GET /v2/cob/{txid}`).
- Remoção da cob irmã depois que outro meio quita (`PATCH`, `REMOVIDA_PELO_USUARIO_RECEBEDOR`).
- Devolução (`PUT /v2/pix/{e2eId}/devolucao/{id}`) e cadastro do webhook (`PUT /v2/webhook/{chave}`).

**Boleto/Bolix (API Cobranças):**
- `POST /v1/charge/one-step` com `banking_billet` no vencimento contratado. Competência já vencida ganha vencimento curto (+2 dias).
- Exibe linha digitável, PDF, link e QR Code Pix do Bolix quando a API os devolve.
- O link e a linha vão na fatura por e-mail (SMTP institucional existente).
- Situação lida por `GET /v1/charge/{id}`: vencimento → `expired`, cancelamento → `cancelled`, confirmação → quitação.
- **Sem duplicidade com o Bolix:** se o cliente pedir Pix com um Bolix pendente, recebe o Pix do próprio Bolix, e nenhuma cob nova é criada.

**Cartão (API Cobranças):**
- Tokenização no navegador (`EfiPay.CreditCard...getPaymentToken`). O servidor recebe só `payment_token` e a máscara; número, CVV e validade nunca chegam ao VetorOS nem são gravados.
- Cobrança avulsa (`one-step` com `credit_card`). Recusa (`unpaid`) mantém a cobrança aberta e fica auditada.
- **Recorrência:** assinatura nativa (`POST /v1/plan`, um plano por intervalo, criado uma vez; `POST /v1/plan/{id}/subscription/one-step`), só com o aceite explícito "Autorizo…" (IP, usuário e horário gravados).
- Cancelamento pelo titular (`PUT /v1/subscription/{id}/cancel`). Falha de renovação fica registrada na autorização e a competência continua aberta, sujeita à carência.

**Reconciliação da recorrência:**
- Cada cobrança da assinatura quita a competência mais antiga em aberto do contrato. Se ela ainda não foi gerada, é gerada e quitada; no plano tradicional, renova o plano.
- Com recorrência ativa, **o agendador não cria cobrança externa** para a competência, e a fatura informa "Cobrança automática no cartão".

**Webhooks e conciliação:**
- Rotas separadas:
  - Pix: `POST /api/webhooks/efi/{segredo}` e `/{segredo}/pix`, já que a Efí acrescenta `/pix` à URL cadastrada.
  - Cobranças: `POST /api/webhooks/efi-charges/{segredo}`, com o campo `notification`.
- Segredo próprio de 48 caracteres na URL, comparado com `hash_equals`.
- Cada evento é deduplicado em `saas_payment_events`: Pix pelo e2eId e devoluções, Cobranças pelo token. Evento concluído não reprocessa; o que falhou é reprocessado no reenvio.
- Processamento em fila: `ProcessEfiPayment` e `ProcessEfiChargesNotification`, com 5 tentativas e espera de 30 s a 30 min. Só falha temporária (HTTP 429/5xx ou conexão) volta para a fila.
- **O conteúdo da notificação nunca é confiado:** toda confirmação consulta a API.
- txid ou cobrança que esta aplicação não gerou é ignorado (protege contra outra aplicação ou conta).
- Conciliação periódica `vetoros:reconcile-saas-payments` a cada 30 min: pagamento atrasado, webhook perdido, expiração.

**Divergências:**
- Pagamento que chega para uma competência já quitada ou cancelada não quita de novo. Vira `saas_payment.divergence` auditado, e o RootAdmin pode devolver o Pix pela tela.
- Estorno ou contestação informados pela API estornam a competência (o acesso volta ao vencimento).

**Navegador nunca libera:** `GET /api/subscription/check?payment=` só responde `active` quando a transação foi confirmada no provedor.

**RootAdmin → Financeiro · Efí Bank** (`/admin/finance/efi`, só `root.admin`):
- Ambiente homologação ou produção.
- Credenciais da API Cobranças e da API Pix (somente escrita, criptografadas com o cast `encrypted`, nunca enviadas ao navegador, exigem a senha do RootAdmin).
- Certificado da API Pix: o `.p12` é convertido em PEM e guardado criptografado; o certificado só vai para um arquivo temporário 0600 durante a chamada.
- Chave Pix, identificador de conta (tokenização), referência da conta recebedora e meios habilitados.
- Teste de conexão (token das duas APIs, sem criar cobrança) e cadastro do webhook Pix.
- Situação das notificações (última Pix e última Cobranças), últimos erros sem dados sensíveis e últimas transações, com ações "Conciliar" e "Devolver Pix".
- **Trava de homologação:** novas cobranças só vão para a Efí depois do teste de conexão ok e do webhook Pix cadastrado (se o Pix estiver habilitado), mais confirmação com senha. Trocar ambiente, credencial, chave ou certificado desfaz a homologação e volta as novas cobranças ao Mercado Pago.

**Transição do Mercado Pago:**
- O Mercado Pago continua o provedor padrão até a homologação, e nada dele foi removido.
- Um PIX do Mercado Pago ainda válido para a mesma competência é reutilizado mesmo com a Efí ativa, para nunca cobrar a mesma competência pelos dois.
- O webhook do Mercado Pago continua concluindo as cobranças antigas.
- Auditoria na tela: PIX pendentes no Mercado Pago, histórico aprovado, competências abertas e recorrências ativas. O VetorOS não tinha assinatura recorrente no Mercado Pago, só PIX avulso.
- A rota antiga `subscription.pay`/`generate_pix` passa a gerar o Pix pela Efí quando ela está ativa, sem mudar a tela antiga.

**Fiscal:** sem mudança. A NFS-e segue pela Spedy com o emitente ABrasil e conforme a opção individual; pagamento e emissão continuam independentes.

## 3. APIs efetivamente integradas

Os endpoints abaixo foram implementados conforme a documentação pública da Efí e **validados contra um simulador controlado** (`Http::fake` com estado). **Nenhum foi chamado de verdade**, porque não há credenciais de homologação.
- **API Pix** (`pix-h.api.efipay.com.br` / `pix.api.efipay.com.br`, OAuth2 client_credentials com mTLS): `POST /oauth/token`, `PUT|GET|PATCH /v2/cob/{txid}`, `GET /v2/loc/{id}/qrcode`, `PUT /v2/pix/{e2eId}/devolucao/{id}`, `PUT|GET /v2/webhook/{chave}`.
- **API Cobranças** (`cobrancas-h.api.efipay.com.br` / `cobrancas.api.efipay.com.br`): `POST /v1/authorize`, `POST /v1/charge/one-step` (boleto e cartão), `GET /v1/charge/{id}`, `PUT /v1/charge/{id}/cancel`, `GET /v1/notification/{token}`, `POST /v1/plan`, `POST /v1/plan/{id}/subscription/one-step`, `GET /v1/subscription/{id}`, `PUT /v1/subscription/{id}/cancel`.

## 4. Arquivos

**Novos:**
- Serviços: `app/Services/Payments/SaasPaymentService.php`, `SaasPaymentTarget.php`, `Efi/EfiPixClient.php`, `Efi/EfiChargesClient.php`, `Efi/EfiException.php`; `app/Services/Commercial/PlanRenewalService.php`
- Models: `app/Models/Admin/SaasPaymentSetting.php`, `SaasPaymentEvent.php`, `SaasRecurringAuthorization.php`
- Controllers: `app/Http/Controllers/EfiWebhookController.php`, `App/SaasCheckoutController.php`, `Admin/Finance/EfiSettingsController.php`
- Jobs e comando: `app/Jobs/ProcessEfiPayment.php`, `ProcessEfiChargesNotification.php`; `app/Console/Commands/ReconcileSaasPayments.php`
- Rotas e migration: `routes/admin-finance.php`; `database/migrations/2026_10_16_130000_create_efi_saas_payments.php`
- Frontend: `resources/js/components/Payment/EfiCheckout.tsx`, `resources/js/pages/admin/finance/efi.tsx`
- Testes: `tests/Feature/Admin/EfiSaasPaymentsTest.php`

**Alterados:**
- `app/Models/App/Payment.php`: campos, casts e vínculos
- `app/Http/Controllers/App/WebhookController.php`: usa o `PlanRenewalService`, com as mesmas respostas
- `app/Http/Controllers/App/PaymentController.php`: delega à Efí quando ativa
- `app/Http/Controllers/App/SubscriptionController.php`: props do checkout e status amarrado à transação
- `app/Services/Commercial/SaasContractService.php`: boleto automático da competência e instruções da Efí na fatura
- `resources/views/emails/saas-charge-invoice.blade.php`: instruções em várias linhas, escapadas
- `resources/js/pages/Subscription/Blocked.tsx`: checkout Efí; fluxo do Mercado Pago intacto
- `resources/js/Utils/navLinks.ts`
- `bootstrap/app.php`, `routes/api.php`, `routes/web.php`, `routes/console.php`
- `package.json`, `package-lock.json`

## 5. Migration

`2026_10_16_130000_create_efi_saas_payments` é aditiva:
- `saas_payment_settings`: linha única, segredos criptografados.
- Colunas novas em `payments`: vínculos, `method`, `reference` único, `provider_subscription_id`, `due_date`, `paid_at`, `paid_amount`, `refunded_amount`, `details`, `last_checked_at`, `error` e índice `(gateway, status)`.
- `saas_payment_events`: único por provedor, canal e chave.
- `saas_recurring_authorizations`.

O `down()` desfaz tudo.

## 6. Testes executados

- `EfiSaasPaymentsTest`: **22 testes**, cobrindo:
  - Pix: txid único e reutilização; webhook confirmado por API e eventos repetidos; payload não confiado e segredo errado ou txid alheio; falha da API com erro seguro; falha temporária reprocessada.
  - Boleto: vencimento contratado e Pix do Bolix reaproveitado; boleto automático do agendador e fatura com os dados; notificação de boleto pago cancelando o Pix irmão; pagamento atrasado reativando tenant bloqueado.
  - Cartão: cartão confirmado por API sem guardar dados sensíveis; cartão recusado.
  - Recorrência: exige autorização e concilia cada competência sem cobrança externa duplicada; falha de renovação e cancelamento.
  - Estorno pela API; divergência com devolução do Pix.
  - Plano tradicional pelo mesmo processamento; PIX do Mercado Pago reaproveitado na transição; Efí bloqueada antes da homologação; isolamento entre tenants.
  - RootAdmin: acesso só do root e segredos fora do navegador e do banco em claro; teste de conexão, webhook e trava de homologação; conversão de `.p12` para PEM criptografado.
- Suíte completa no SQLite: **748 testes passaram (4908 asserções)**.
- **Banco real:** por orientação do responsável, as migrations rodaram no **MariaDB 11.8.6 local**, num banco descartável `vetoros_migration_test` (não no `vetoros`). Todas as migrations rodaram do zero, e as duas novas desfizeram e reaplicaram limpas. As suítes de contrato e Efí (**64 testes**) também passaram contra esse MariaDB. **Não testado em MySQL 8.4:** a tentativa com o binário oficial 8.4.9 no scratchpad foi interrompida a pedido; o servidor temporário foi encerrado. Recomendo repetir as migrations em MySQL 8.4 na homologação.
- Pint: ok. Prettier: ok nos arquivos alterados (os 69 avisos do projeto já existiam). `tsc --noEmit`: ok. `npm run build`: ok.
- Guarda de segredos (`scripts/security/secret-guard.php`): ok nos 48 arquivos alterados. O teste usa certificado gerado em tempo de execução; não há material de chave versionado.

## 7. Pontos que exigem credenciais da Efí

1. Aplicação **exclusiva do VetorOS** na conta Efí existente, com os escopos de Cobranças e de Pix (cob, webhook, devolução). Não reutilizar as aplicações das outras operações.
2. Client ID e Client Secret de homologação das duas APIs, o certificado `.p12` de homologação e a chave Pix de homologação.
3. Identificador de conta (*payee code*) para a tokenização do cartão.
4. Confirmar na conta: se o Bolix está habilitado (o Pix vem dentro do boleto); se a recorrência no cartão está liberada; o comportamento de boleto vencido (multa, juros, pagamento após o vencimento); e se a Efí exige mTLS no webhook Pix ou aceita `x-skip-mtls-checking` (o código usa o segredo na URL mais a confirmação por API).
5. Nomes de campos dependentes da conta, a validar no primeiro teste real: `paid_value` (o código cai para `total`), `pix.qrcode_image` do Bolix e eventos de assinatura do tipo `subscription_charge`.

## 8. Pendências de homologação

- Rodar em homologação: teste de conexão, cadastro do webhook Pix, um Pix, um boleto (pago pela linha e pelo Pix do Bolix), um cartão avulso aprovado e um recusado (cartões de teste da Efí), uma recorrência com renovação e cancelamento, uma devolução Pix e um estorno de cartão.
- Conferir o certificado `.p12` real: com OpenSSL 3, `.p12` legado pode falhar; a tela orienta converter com `-legacy`.
- Worker de fila ativo em produção. Sem ele, a conciliação periódica ainda confirma, mas com até 30 min de atraso.
- **Segurança (item 9), só verificação:**
  - O dump do P0-1 já não aparece em nenhuma referência.
  - Os commits com `APP_KEY`, `DB_PASSWORD` e `MAIL_PASSWORD` no `.env.example` (ex.: `22a5a86a`) **ainda existem no histórico**: reescrita e rotação seguem pendentes, como no VETOR-SEC-03.
  - A `APP_KEY` **não foi alterada**. Como as credenciais da Efí são cifradas com ela, a troca exige o procedimento de recifragem (`APP_PREVIOUS_KEYS`) já descrito.
- O banco descartável `vetoros_migration_test` continua no MariaDB local para remoção: `DROP DATABASE vetoros_migration_test;`. O binário do MySQL 8.4.9 está no scratchpad desta sessão.

## 9. Implantação (quando liberada)

1. Commit e push revisados; deploy com `composer install`, `npm ci && npm run build`.
2. `php artisan migrate --force` (aditiva; o Mercado Pago continua ativo).
3. Garantir o worker de fila e o scheduler (`vetoros:reconcile-saas-payments` a cada 30 min e `vetoros:process-saas-contracts` às 07:00).
4. RootAdmin → Financeiro · Efí Bank:
   1. Ambiente **homologação**: credenciais, certificado, chave Pix e identificador de conta.
   2. Testar conexão e cadastrar o webhook Pix.
   3. Habilitar os meios e usar a Efí. Homologar com os casos do item 8.
5. Produção: trocar o ambiente e as credenciais (a homologação é desfeita automaticamente), testar, cadastrar o webhook e usar a Efí.

## 10. Rollback

- **Imediato, sem deploy:** RootAdmin → "Voltar ao Mercado Pago". As novas cobranças voltam ao Mercado Pago na hora. Transações Efí já criadas continuam sendo conciliadas e quitadas pelos webhooks e pela conciliação, então nada pago se perde. Recorrências no cartão ativas devem ser canceladas na tela ou na Efí, se o objetivo for parar a cobrança.
- **Código:** reverter o commit. A migration tem `down()` completo, mas **não** rodar o rollback em produção se já houver transações Efí, porque as colunas novas de `payments` guardam o vínculo com a competência. Prefira manter o schema e só desligar o provedor.

---

# VETOR-SAAS-CONTRATO-02 — Contrato personalizado com trava de planos e cobrança recorrente. Resultado: **IMPLEMENTADO E VALIDADO LOCALMENTE, SEM COMMIT, SEM PUSH, SEM DEPLOY**

Data: 2026-10-16. Executor: estação de desenvolvimento local (WSL). Nenhum push, deploy ou migration de produção.

## Comparação do correio

`correio.md` estava diferente da versão em `HEAD` (`ff5061c0`): a instrução anterior era VETOR-SAAS-CONTRATO-01, cujo código já está no commit `ff5061c0` ("Push"), mas que não tinha registro neste arquivo. A atual é VETOR-SAAS-CONTRATO-02, que complementa a 01.

## 1. Auditoria: o que já existia (CONTRATO-01, reaproveitado)

- `SaasContractService` (`app/Services/Commercial`): contrato por tenant (`tenant_commercial_contracts`), uma cobrança por ciclo (`tenant_subscription_charges`, índice único contrato + início do ciclo), pagamento manual ou PIX, estorno, ajuste auditado, cobertura de acesso por `tenants.expires_at` (vencimento → 3 dias de carência → bloqueio, regra já existente de `Tenant::subscriptionBucket`).
- Mercado Pago: `PaymentController::generatePix` já cobrava a cobrança em aberto (nunca o preço do plano) para tenant com contrato. `WebhookController` valida o token por `hash_equals`, confirma o pagamento consultando a API (`getPayment`), é idempotente e ignora PIX de plano aprovado depois da conversão.
- Fiscal e e-mail: NFS-e pela Spedy com o emitente da ABrasil (`SaasInvoiceService`), programada pela competência e independente da quitação. Fatura e aviso fiscal saem pelo SMTP institucional (`AppliesSystemMailConfig`). PDF e XML vão por links assinados e temporários (`/nfse-saas/...`). Reenvio sem reemissão.
- Agendador: `vetoros:process-saas-contracts`, diariamente às 07:00.
- UI: RootAdmin → Empresas → Editar → "Condições comerciais e faturamento", com os campos do contrato visíveis só com a opção ligada e a opção individual "Emitir NFS-e para este cliente SaaS".

## 2. Lacunas encontradas e corrigidas

| # | Lacuna | Correção |
|---|---|---|
| 1 | O RootAdmin ainda podia trocar o plano de um tenant com contrato pelo formulário geral da empresa | `TenantController::update` recusa a troca de `plan_id` (erro de validação) e preserva o `period_id`. Na tela, o seletor fica desabilitado e explica o motivo |
| 2 | Na área do tenant, o modal de assinatura listava os planos e seus preços | `HandleInertiaRequests` envia `plans = []` e `custom_contract = true`. O modal mostra "Ver cobrança e gerar PIX" (página da cobrança do contrato) |
| 3 | A rota de PIX aceitava `plan_id` em silêncio | Com contrato, `plan_id` na requisição retorna **403** |
| 4 | A volta ao plano não tinha data efetiva | Agora exige **data efetiva** (não anterior a hoje) e confirmação que cita a data. Hoje: aplica na hora. Data futura: fica agendada (`revert_*`). Até lá o contrato vale e não gera cobrança com vencimento a partir dessa data. O agendador aplica no dia. Também é possível desagendar (`DELETE /admin/tenants/{id}/commercial/revert`). Tudo é auditado |
| 5 | Vencimentos em dias 29/30/31 escorregavam (31/01 → 28/02 → 28/03) | `anchor_day` (dia contratual) e `TenantCommercialContract::nextDueAfter`: 31/01 → 28/02 → 31/03; 29/02 anual → 28/02 → … → 29/02 no bissexto. O ciclo termina na véspera do próximo vencimento |
| 6 | Cada clique gerava um PIX novo para a mesma cobrança, com risco de pagamento em dobro | `SaasContractService::reusablePixFor` devolve o PIX pendente ainda válido (com folga de 5 min) da mesma cobrança e do mesmo valor |
| 7 | Um segundo PIX aprovado para cobrança já quitada só gerava log | Também fica em `fiscal_admin_audits` (`commercial.payment_not_applied`) para o RootAdmin devolver. A cobrança não é quitada de novo |

Requisitos já atendidos pela 01 e mantidos: o valor do ciclo é cobrado integralmente (nunca o mensal multiplicado); mudança de valor ou periodicidade vale do próximo ciclo (as cobranças geradas não são recalculadas; o ajuste de cobrança em aberto é explícito e auditado); na conversão, o vencimento não pode cair em período já pago ou cobrado; não há duas assinaturas faturáveis (PIX de plano é recusado com contrato e ignorado no webhook); permissões continuam vindo do plano, que fica intacto; histórico preservado (sem exclusão de empresa com contrato); a inadimplência, a carência, a suspensão e a reativação seguem as regras atuais.

## 3. Arquivos alterados

- `database/migrations/2026_10_16_120000_add_anchor_and_scheduled_revert_to_saas_contracts.php` (**nova, aditiva**)
- `app/Models/Admin/TenantCommercialContract.php`: `anchor_day`, `nextDueAfter`, `cycleFor` ancorado, relações `revertPlan` e `revertPeriod`
- `app/Services/Commercial/SaasContractService.php`: `anchor_day` ao salvar; `revertToPlan` com data efetiva; `cancelScheduledRevert`; `applyScheduledReverts`; trava de geração após a data efetiva; `reusablePixFor`; snapshot de auditoria ampliado
- `app/Console/Commands/ProcessSaasContracts.php`: aplica as voltas agendadas antes de gerar cobranças
- `app/Http/Controllers/Admin/TenantCommercialController.php`: `effective_date`, `cancelRevert`, props `scheduled_revert` e `suggested_revert_date`
- `app/Http/Controllers/Admin/TenantController.php`: trava de plano no backend
- `app/Http/Controllers/App/PaymentController.php`: 403 para `plan_id` com contrato; reaproveitamento do PIX
- `app/Http/Controllers/App/WebhookController.php`: auditoria de pagamento não aplicado
- `app/Http/Middleware/HandleInertiaRequests.php`: `plans` vazio e `custom_contract`
- `routes/admin.php`: rota de desagendamento
- `resources/js/components/admin/tenant-commercial-section.tsx`: "Data inicial (data efetiva)", "Primeiro vencimento", data efetiva no diálogo de volta, aviso de agendamento com "Desagendar"
- `resources/js/pages/admin/tenants/edit-tenant.tsx`: seletor de plano bloqueado
- `resources/js/components/Payment/SubscriptionModal.tsx`: modo contrato sem lista de planos
- Testes: `tests/Feature/Admin/SaasContractPlanLockTest.php` (novo) e `SaasCommercialContractTest.php` (ajustado à trava e à data efetiva)

## 4. Migration

Aditiva. Em `tenant_commercial_contracts`: `anchor_day` (tinyint, nulo), `revert_scheduled_for` (date, nulo), `revert_plan_id` e `revert_period_id` (FK, nullOnDelete) e `revert_requested_by` (FK users). O backfill de `anchor_day` usa o dia do `next_due_date` atual. Testada só no SQLite dos testes; **não executada em produção**.

## 5. Resultados

- Testes de contrato: **42 passaram** (26 da 01, ajustados, e 16 novos: âncora de dia para mensal 31/30/29, semestral 31 e anual 29/02; geração atrasada mantendo o dia 31; troca de periodicidade no ciclo seguinte; trava no admin; trava na área do tenant com 403; tenant de plano ainda vê planos; reaproveitamento do PIX; segundo PIX não aplicado e auditado; validação da data efetiva; volta agendada sem sobreposição; desagendar só pelo RootAdmin; volta com efeito imediato).
- Suíte completa: **726 testes passaram (4689 asserções)**.
- Pint: ok. Prettier: ok. `tsc --noEmit`: sem erros. `npm run build`: ok (só o aviso de tamanho de chunk que já existia).

## 6. Pendências e riscos

- **Mercado Pago:** continua só o PIX. Boleto, cartão e assinatura recorrente do MP não foram ampliados, como pede o correio; fica para depois de auditar os recursos da conta (credenciais e produtos habilitados). A assinatura `x-signature` do webhook não é validada (a autenticação é o token na URL mais a confirmação pela API); recomendo adicioná-la quando o segredo do MP estiver configurado.
- Segundo PIX pago para a mesma cobrança: fica auditado, mas a devolução é manual no painel do Mercado Pago.
- Volta agendada: na data efetiva, as cobranças em aberto sem NFS-e são canceladas (mesma regra da volta imediata da 01). Se houver cobrança em aberto com NFS-e ativa, a aplicação é recusada, auditada (`commercial.scheduled_revert_blocked`) e tentada de novo no dia seguinte.
- Contratos criados antes desta migration que já tinham escorregado (por exemplo, 31 → 28) ficam ancorados no dia atual; corrija o "Próximo vencimento" no RootAdmin se necessário.
- Nada commitado; nenhum push, deploy ou migration de produção, conforme o correio.

## 7. Homologação sugerida (ambiente de teste)

1. `php artisan migrate` no ambiente de teste.
2. RootAdmin → Empresa → ativar o contrato com primeiro vencimento no dia 31. Conferir que o seletor de plano fica bloqueado e que a troca pelo formulário é recusada.
3. Entrar como tenant: o modal não lista planos; gerar o PIX duas vezes deve devolver o mesmo QR Code.
4. Pagar no sandbox do MP: a cobrança fica paga e o webhook repetido não muda nada.
5. Agendar a volta ao plano para uma data futura, rodar `php artisan vetoros:process-saas-contracts` antes e na data: nenhuma cobrança do contrato sai a partir da data efetiva, e na data a empresa volta ao plano escolhido.
6. Com "Emitir NFS-e" ligado, conferir a emissão na competência (sandbox Spedy) e os links de PDF e XML no e-mail.

---

# VETOR-FISCAL-02 — Publicação, implantação e homologação real da integração Spedy. Resultado: **BLOQUEADO NA PUBLICAÇÃO (push sem credencial); NADA IMPLANTADO**

Data: 2026-10-15. Executor: estação de desenvolvimento local (WSL), **não a VPS**. Aqui não existem `/opt/infra-abrasil` nem Docker, e não há credencial de push para o GitHub. Nenhum push, deploy, backup, migration, alteração de container ou chamada real à Spedy foi feito.

## Comparação do correio

`correio.md` estava diferente da versão em `HEAD` (`36ea985c`). A instrução anterior era VETOR-FISCAL-01, já executada (commit `3ffec982`). A atual é VETOR-FISCAL-02 (publicação, deploy e homologação no sandbox).

## 1. Verificações (somente leitura)

| Item | Resultado |
|---|---|
| Repositório / branch | `gateway/vetoros` em `main`. Não foi usado `infra-vetor` |
| HEAD local / `origin/main` | `36ea985c` / `832906cf`: **2 à frente, 0 atrás**, fast-forward possível (`origin/main` é ancestral) |
| Commit `3ffec982` | Presente. Contém só o VETOR-FISCAL-01 (12 arquivos: serviço/cliente Spedy, controllers fiscais, modelo, migration, 2 telas, testes). `36ea985c` contém só `correio.md` e `executed.md` |
| `git fsck` | Sem erros (só blobs soltos) |
| Árvore de trabalho | Alterações **de outra frente**, não incluídas: `app/Support/TenantMailConfig.php`, `config/mail.php`, `tests/Unit/TenantMailTransportTest.php` |
| Migrations pendentes (MySQL local, `--pretend`) | A do FISCAL-01 adiciona `spedy_environment` e `last_synced_at` em `fiscal_settings`, é aditiva e preenche esses campos só nos vínculos existentes. A base local também tem migrations antigas pendentes. **Produção: não verificável daqui** |
| Serviços `vetoros`, `vetoros-worker`, `vetoros-scheduler`, `nginx`, `mysql` / integração Spedy em produção | **Não verificáveis daqui** (sem acesso à VPS) |

## 2. Segurança antes do push

| Item | Situação em 2026-10-15 |
|---|---|
| `brasilgb/vetoros` | **Público** (API responde 200 sem autenticação) |
| `brasilgb/infraabrasil` | **Público** (200) |
| Rotação de credenciais / reescrita do histórico (P0 do SEC-03 e do RELEASE-01) | Sem registro de conclusão |
| `secret-guard` nos 14 arquivos dos 2 commits novos | Nada encontrado |
| `secret-guard tracked` | 1.127 arquivos, nada encontrado |

**Decisão:** os P0 continuam abertos, mas o responsável **autorizou expressamente nesta sessão** o push convencional do VetorOS e da infra no estado atual. Force push e reescrita do histórico não foram autorizados nem feitos.

## 3. Publicação: bloqueada

- Suíte completa antes do push: **641 passaram (3.972 asserções), 0 falhas**. Rodou na árvore de trabalho; os 3 testes a mais são da frente de e-mail, não commitada.
- `git push origin main` **falhou**: `fatal: could not read Username for 'https://github.com'`. O remoto é HTTPS, e esta máquina não tem credential helper, chave SSH nem `gh`. O `fetch` funciona só porque o repositório é público.
- **Infra (`infra-abrasil`) não foi tocada:**
  - está **divergente**: 5 commits atrás e 1 à frente (`ed94ddc`) de `origin/main`;
  - os 5 commits remotos alteram `executed.md`, `gateway/correio.md`, `.gitmodules` e ponteiros;
  - a árvore tem alterações não commitadas de outras frentes: `.env.example`, `correio.md`, `docker-compose.yml`, `executed.md`, submódulos `abrasilsistemas`/`desgarrados`, `SMTP.md`, `gateway/vetorleads/` e scripts de e-mail;
  - integrar com o remoto agora geraria conflito nesse trabalho, e o push falharia pelo mesmo motivo.
  - Ponteiro do VetorOS: `4c4ce74c` no HEAD local da infra; `68d31093` no `origin/main`.

## 4 a 16. Não executados

Backup, migration, rebuild, configuração do sandbox, cadastro do emitente, certificado A1, NFS-e de homologação, webhook, reconciliação e contrato automático **dependem do código publicado e de execução na VPS**. O certificado A1 e a senha também precisam ser enviados pelo administrador do tenant, pela tela. Ambiente da plataforma: nada foi alterado; produção continua fora desta etapa (§16).

## Para destravar

**A. Publicar (na sua máquina, com credencial do GitHub):**
```bash
cd ~/projects/laravel/infra-abrasil/gateway/vetoros
git push origin main                      # fast-forward a partir de 832906cf (3 commits: FISCAL-01 e os dois relatórios), sem force
```
Na infra, depois de decidir o destino das alterações locais das outras frentes:
```bash
cd ~/projects/laravel/infra-abrasil
git stash push -m "pre-fiscal-02"          # ou commitar as outras frentes antes
git pull --no-rebase origin main           # resolver executed.md se houver conflito, sem force
git -C gateway/vetoros checkout origin/main   # após o push do VetorOS
git add gateway/vetoros && git commit -m "Atualiza ponteiro do VetorOS (VETOR-FISCAL-01)"
git push origin main
git stash pop
```

**B. Na VPS (`/opt/infra-abrasil`), em ordem:**
```bash
ssh -T git@github-vetoros                   # deploy key (falhou no DEPLOY-01.1)
git pull --ff-only origin main && git submodule sync && git submodule update --init gateway/vetoros
git -C gateway/vetoros log -1 --format='%h' # esperado: o mesmo HEAD publicado em origin/main do VetorOS

for s in vetoros vetoros-worker vetoros-scheduler; do docker tag infra-abrasil-$s:latest infra-abrasil-$s:rollback-fiscal02; done
mkdir -p backups
docker exec infra-abrasil-mysql-1 sh -c 'exec mysqldump -uroot -p"$MYSQL_ROOT_PASSWORD" --single-transaction --routines --triggers --databases vetoros' \
  | gzip > backups/vetoros-fiscal02-$(date +%Y%m%d%H%M).sql.gz
gunzip -t backups/vetoros-fiscal02-*.sql.gz && zcat backups/vetoros-fiscal02-*.sql.gz | tail -1   # "Dump completed"

docker compose build vetoros vetoros-worker vetoros-scheduler
docker compose run --rm --no-deps vetoros php artisan migrate:status
docker compose run --rm --no-deps vetoros php artisan migrate --pretend   # conferir: só ALTER aditivo em fiscal_settings (+ pendentes já conhecidas)
docker compose up -d --no-deps vetoros vetoros-worker vetoros-scheduler
docker compose exec vetoros php artisan migrate --force
docker compose ps vetoros vetoros-worker vetoros-scheduler nginx && docker compose exec -T nginx nginx -s reload
```
**Rollback:** subir as tags `rollback-fiscal02`. A migration é aditiva, então o código antigo convive com as colunas novas, e `migrate:rollback --step=1` também as remove.

**C. Homologação no sandbox:** seguir as §7 a §15 do correio, que coincidem com a §7 do relatório do VETOR-FISCAL-01. Para registrar o vínculo sem expor credenciais:
```bash
docker compose exec vetoros php artisan tinker --execute="echo json_encode(DB::table('fiscal_settings')->where('tenant_id', <ID>)->first(['registration_status','registration_error','spedy_company_id','spedy_environment','last_synced_at']));"
```

## Pendências

1. Credencial de push nesta máquina (ou push manual) e decisão sobre as alterações locais da infra.
2. Deploy key da VPS para `brasilgb/vetoros` (pendência do DEPLOY-01.1).
3. P0 de segurança continuam abertos (repositórios públicos, rotação e histórico). A publicação foi autorizada no estado atual, mas não os resolve.
4. Certificado A1 válido da empresa de teste e chave titular da conta **sandbox** da Spedy.

---

# VETOR-FISCAL-01 — Integração automática Spedy e liberação do certificado A1. Resultado: **IMPLEMENTADO E VALIDADO, COMMIT LOCAL `3ffec982`, SEM PUSH, SEM DEPLOY**

Data: 2026-10-15. Ambiente: desenvolvimento local e testes automatizados. Nenhuma chamada real à Spedy e nenhuma migration aplicada (nem local, nem em produção).

## Comparação do correio

`correio.md` estava diferente da versão em `HEAD` (`832906cf`). A instrução anterior era VETOR-FISCAL-06 (autogestão do certificado A1), já executada e registrada abaixo. A atual é VETOR-FISCAL-01, executada a seguir.

## 1. Causa raiz do "Empresa emissora: Pendente"

A integração já existia completa: cadastro do emitente com a chave titular, sincronização, consulta e upload do certificado. O bloqueio vem de três pontos:

1. **"Fiscal habilitado" não cadastra a empresa.** O switch do RootAdmin só grava `tenants.automatic_fiscal_emission_enabled`. O cadastro na Spedy é outra ação, o botão "Cadastrar na Spedy" em RootAdmin → Fiscal → Empresas emissoras. Enquanto ele não roda com sucesso, `fiscal_settings.registration_status` continua `pending`, e o campo do certificado fica bloqueado de propósito, porque a API exige o id e a chave da empresa (`POST /companies/{id}/certificates`). A tela não deixava clara a diferença entre "habilitado" e "pronto para emitir".
2. **Falha de validação no cadastro não era gravada.** `SpedyCompanyService::sync` montava o payload (CNPJ, razão social, endereço, regime tributário) **fora** do `try`. Uma `FiscalValidationException` aparecia só como mensagem flash, uma única vez, e o registro continuava `pending`, sem erro. Assim, quem clicou "Cadastrar" com dados faltando (o regime tributário fica nas configurações fiscais, não em Dados da empresa) via depois apenas "Pendente".
3. **O vínculo não registrava o ambiente.** Segundo a [documentação](https://docs.spedy.com.br/start/ambiente-de-testes), sandbox e produção são **contas separadas**: ids e chaves de uma não funcionam na outra. A troca de ambiente pelo painel já descartava os vínculos de sandbox. A troca pela variável `SPEDY_ENVIRONMENT`, porém, deixava vínculos de outra conta marcados como "cadastrados".

**Não foi possível confirmar qual desses casos atinge a empresa real**, porque esta execução não acessou o banco de produção. Na base local, o tenant 1 está com emissão habilitada, `registration_status=pending` e `registration_error` vazio, e a integração não está configurada. É o quadro do item 1 ou do item 2. Para conferir na VPS (somente leitura):

```bash
docker compose exec vetoros php artisan tinker --execute="echo json_encode(DB::table('fiscal_settings')->join('tenants','tenants.id','=','fiscal_settings.tenant_id')->where('tenants.automatic_fiscal_emission_enabled',1)->get(['fiscal_settings.tenant_id','registration_status','registration_error','spedy_company_id','company_tax_regime']));"
```

## 2. O que foi implementado

| Componente | Alteração |
|---|---|
| Migration `2026_10_15_100000_add_spedy_link_environment_to_fiscal_settings` | Aditiva: `fiscal_settings.spedy_environment` e `last_synced_at`. Vínculos existentes recebem o ambiente atual da plataforma |
| `FiscalSetting` | `isRegisteredOnSpedy()` passa a exigir vínculo do ambiente atual; novo `hasEnvironmentMismatch()`; bloqueio de emissão com mensagem própria para vínculo de outro ambiente |
| `SpedyCompanyService::sync` | Valida dentro do `try` e grava **todo** erro (validação ou API) em `registration_error`. Status `error` se não há vínculo válido; falha de sincronização de vínculo válido mantém o cadastro. Grava `spedy_environment` e `last_synced_at`. Antes do `POST /companies`, procura o CNPJ na conta e, se achar, **não cria outra empresa** e informa o id existente. Vínculo de sandbox com a plataforma em produção é descartado no cadastro explícito do root (sem levar o certificado). Vínculo de produção com a plataforma em sandbox **nunca** é descartado |
| `SpedyClient` | `findCompanyByFederalTaxNumber()`, que pagina `GET /companies` (100 por página, até 20 páginas), já que a API não filtra por CNPJ. Mensagens: 401 chave inválida/revogada; 403 chave de outro ambiente ou plano/permissão insuficiente |
| `FiscalCompanyController` (RootAdmin) | Envia vínculo, ambiente do vínculo e da plataforma, divergência e última sincronização. "Consultar cadastro e certificado" grava `last_synced_at`; no 404, grava "Empresa emissora não encontrada" **sem apagar** o vínculo |
| `FiscalIntegrationController` | A troca de ambiente pelo painel também limpa os novos campos |
| `admin/fiscal/companies.tsx` | Colunas "Fiscal habilitado" e "Pronta para emitir", separadas. Cadastro: Cadastrada / Erro / Vínculo de outro ambiente, ID, ambiente, última sincronização e último erro. Certificado: vigente/vencido, validade e titular. Estado por modelo: fiscal não habilitado / não liberada / pendência real / pronta para homologação / apta em produção. Botões "Cadastrar na Spedy" e "Sincronizar". Ambiente da plataforma visível |
| `app/fiscal-settings/index.tsx` (tenant) | O certificado fica liberado só com vínculo confirmado no ambiente atual (`registered_on_spedy`, do servidor). Selo "Cadastro de outro ambiente" e explicação do bloqueio |

Itens do correio que **já estavam atendidos** e foram preservados: cadastro idempotente com `lockForUpdate`, para dois cliques não criarem duas empresas; chave da empresa cifrada e gravada antes de qualquer outra chamada; certificado `.pfx`/`.p12` com senha só no envio, sem registrar senha em log ou banco, confirmação `isActive` e validade (VETOR-FISCAL-06); liberação independente de NF-e, NFC-e e NFS-e; aprovação de produção com senha do root; bloqueio de produção pelo tenant; `environmentType` por modelo; e campos fiscais por modalidade (CSC só para NFC-e, tipo de tributação só para NFS-e, confirmação da contabilidade, sem valores tributários pré-preenchidos).

## 3. Endpoints da Spedy realmente utilizados (API v1)

| Uso | Endpoint | Chave |
|---|---|---|
| Verificar CNPJ na conta (novo) | `GET /companies?page=&pageSize=` | titular |
| Cadastrar emitente | `POST /companies` (retorna `id` e `apiCredentials.apiKey`, exibida uma única vez) | titular |
| Sincronizar cadastro | `PUT /companies/{id}` | titular |
| Configurações de emissão | `PUT /companies/{id}/settings` | da empresa |
| Consultar cadastro | `GET /companies/{id}` | titular |
| Consultar / enviar certificado | `GET` / `POST /companies/{id}/certificates` | da empresa |

Fontes: [llms.txt](https://docs.spedy.com.br/llms.txt), [OpenAPI v1](https://docs.spedy.com.br/openapi/v1.json), [Autenticação](https://docs.spedy.com.br/start/autenticacao), [Ambiente de testes](https://docs.spedy.com.br/start/ambiente-de-testes).

## 4. Configurações necessárias na Spedy

1. Conta **sandbox** própria (cadastro separado, Plano Desenvolvedor) e a chave da empresa titular em Minha empresa → Credenciais de API.
2. RootAdmin → Fiscal → Integração: ambiente `sandbox`, chave titular, "Diagnóstico" e "Configurar webhook".
3. Certificado A1 **válido** da empresa cliente: o sandbox também exige certificado real.
4. Produção: outra conta/chave. A troca de ambiente pelo painel descarta os vínculos de sandbox, e cada empresa precisa ser cadastrada de novo.

## 5. Testes

- Novo `tests/Feature/Admin/SpedyIssuerActivationTest.php` (11 testes): cadastro com vínculo do ambiente e liberação do certificado; dados incompletos gravados como erro, sem chamada à API; CNPJ já existente sem duplicação; timeout no `POST` seguido de nova tentativa sem duplicação; falha de sincronização mantendo o vínculo; vínculo de produção não usado nem descartado em sandbox, com upload bloqueado; vínculo de sandbox substituído só no cadastro em produção, sem levar o certificado; 404 gravado sem apagar o vínculo; mensagem de 401; liberação de um modelo sem liberar os outros; tenant sem acesso a cadastro e consulta.
- `FiscalCentralAdministrationTest` e `NativeFiscalEmissionTest` ganharam o fake da consulta de CNPJ.
- **Suíte completa: 638 passaram (3.956 asserções), 0 falhas.** `tsc --noEmit` ok, `vite build` ok, Prettier e Pint ok nos arquivos alterados. `migrate --pretend` no MySQL local confere o SQL da migration.

## 6. Pendências e limitações reais

- **Confirmar a causa na empresa real** com a consulta da §1. Se aparecer `error`, a mensagem gravada a partir de agora indica o que corrigir. Se aparecer `pending` sem erro, basta clicar "Cadastrar na Spedy".
- **CNPJ já existente na conta Spedy:** a API devolve a chave de outras empresas ofuscada, então não há como religar automaticamente uma empresa criada fora do VetorOS ou criada antes de um timeout. O sistema informa o id, e a providência é excluir o cadastro duplicado no painel da Spedy ou falar com o suporte deles.
- A busca de CNPJ percorre até 2.000 empresas. Acima disso, o cadastro é interrompido com mensagem, sem criar a empresa.
- "Não contratada" (por modelo) não existe como conceito no VetorOS: não há contratação por modelo nos planos. O estado mostrado é "Não liberada".
- A troca de `SPEDY_ENVIRONMENT` direto no `.env` continua possível. Agora o sistema detecta e bloqueia vínculos de outro ambiente, mas o caminho recomendado é a troca pelo painel.
- Migration pendente de deploy, sem impacto em dados existentes. **Sem push e sem deploy.**

## 7. Homologação pelo RootAdmin (sandbox)

1. Fiscal → Integração: confirmar ambiente **Sandbox**, chave titular configurada, "Diagnóstico" bem-sucedido e webhook configurado.
2. No tenant (como administrador): preencher Dados da empresa (CNPJ, razão social, endereço com CEP) e, em Emissão fiscal, o regime tributário e a IE/IM aplicáveis.
3. Fiscal → Empresas emissoras: ligar "Fiscal habilitado" e liberar **só** os modelos a homologar.
4. Clicar **Cadastrar na Spedy**. Esperado: "Cadastrada", ID, "(Sandbox)" e data de sincronização. Em erro, a mensagem fica na coluna Cadastro.
5. No tenant: o campo do certificado A1 fica liberado. Enviar o `.pfx`/`.p12` com a senha. Esperado: "Ativo — válido até …".
6. No RootAdmin: "Consultar cadastro e certificado" confirma o certificado ativo na Spedy.
7. No tenant: ativar os modelos, preencher série, CSC (NFC-e) ou tipo de tributação (NFS-e), confirmar os dados tributários e salvar. Isso sincroniza `/settings`.
8. Conferir "Pronta para emitir" = "Pronta para homologação" em cada modelo e emitir uma nota de teste (NFS-e em Simulação, NF-e/NFC-e em Homologação).
9. Só depois: "Aprovar produção" (pede senha) e, para produção real, a troca da plataforma para a conta de produção, com novo cadastro.

---

# VETOR-FISCAL-06 — Autogestão do certificado digital A1

Data: 2026-10-14. Auditoria, implementação e validações locais concluídas. Sem push, deploy ou migrations em produção.

## Comparação do correio

`correio.md` estava diferente da versão em `HEAD` (`4c4ce74c`): a instrução anterior era CRM-WA-30.1; a atual solicita VETOR-FISCAL-06. A execução seguiu a instrução atual. O relatório anterior permanece no histórico do Git.

## O que já existia

- Tela fiscal, rota `POST /app/fiscal-settings/certificate`, controller e serviço compartilhado de upload. O formulário só aparecia quando `registration_status` era `registered`, explicando sua ausência para empresas pendentes.
- `SpedyCompanyService::uploadCertificate` e `SpedyClient::uploadCertificate`: transmissão multipart com `certificateFile` e `password`, usando chave e identificador remoto da empresa. Apenas titular e validade eram persistidos. A chave da empresa já usa cast criptografado.
- Validação de `.pfx`/`.p12`, limite local de 2048 KB e exclusão de `password` do flash de entrada pelo Laravel. O tenant autenticado prevalece sobre o tenant informado na sessão.
- RootAdmin: credenciais/ambiente da plataforma, liberação fiscal por tenant/modelo, cadastro/sincronização de empresas e consulta de certificados. O emitente próprio do SaaS tem upload central separado, já existente. Esses controles foram preservados.
- Lacunas: operador com acesso às configurações podia enviar certificado; faltava confirmação de substituição; qualquer HTTP bem-sucedido era tratado como sucesso; erros do provedor podiam expor detalhes na mensagem e nos logs.

## O que foi implementado

- Campo de upload sempre visível em Sistema e módulos → Emissão fiscal, com instrução sobre A1/eCNPJ, extensões, limite e senha. Quando indisponível, fica desabilitado e explica o requisito de liberação/cadastro administrativo.
- Prop `certificateUploadAllowed`, calculada no servidor. Upload exige administrador do tenant, integração configurada, liberação fiscal do tenant e empresa registrada. Permissão de configurações sozinha não basta.
- Backend usa exclusivamente a empresa do usuário autenticado. IDs de tenant/empresa, credenciais e habilitações enviados pelo navegador não alteram destino nem controles do RootAdmin.
- Confirmação no navegador e `confirm_replacement` obrigatório no servidor. Vale para todo envio, pois pode existir certificado remoto ainda ausente no cache local.
- Validação antecipada de extensão/tamanho, erros junto aos campos, bloqueio durante transmissão e limpeza da senha ao enviar/cancelar/concluir. Arquivo selecionado é limpo após a requisição. Senha fica em estado transitório, fora do `useForm` e de recursos de memória/histórico do Inertia.
- Upload recusa URL sem HTTPS e não segue redirecionamentos HTTP. Nenhum armazenamento permanente do arquivo foi adicionado; o upload usa o arquivo temporário normal da requisição PHP.
- Resposta deve confirmar `id`, `isActive=true` e validade futura antes de salvar metadados e informar sucesso. Resposta vazia, inativa, vencida ou com validade inválida/ausente gera mensagem controlada.
- Logs e mensagens de erros dos endpoints de certificados não incluem o conteúdo retornado pelo provedor, que poderia ecoar senha, arquivo ou credencial.
- Tela diferencia validade vigente/vencida e mostra titular quando fornecido. Dados vêm da última confirmação armazenada, sem chamada automática à Spedy ao abrir a página.

## Contrato e limites da Spedy

| Operação | Endpoint da API v1 | Comportamento |
|---|---|---|
| Cadastro/substituição | `POST /companies/{id}/certificates` | Multipart com arquivo PKCS#12 e senha; último envio substitui o certificado ativo anterior. |
| Consulta/situação | `GET /companies/{id}/certificates` | Lista certificados com `isActive` e `expirationAt`; utilizada pelo diagnóstico central existente. |

Não foi identificado endpoint separado de validação ou substituição no catálogo consultado. A confirmação usa a resposta do próprio POST. O contrato inclui `id`, `expirationAt`, `subject`, `issuer` e `isActive`; titular/emissor podem ser nulos. O sistema persiste titular e validade, sem criar um segundo gerenciamento. [Upload](https://docs.spedy.com.br/api-reference/empresas/adicionar-certificado), [consulta](https://docs.spedy.com.br/api-reference/empresas/obter-certificados).

A referência especifica `.pfx`/PKCS#12; a ajuda oficial também aceita `.p12`. Ambos são recebidos pelo VetorOS e transmitidos com o nome neutro `certificado.pfx`, como no fluxo existente. Não foi encontrado limite numérico de tamanho na referência: 2 MB é o limite conservador **já existente no VetorOS**, não um limite atribuído à API. [Ajuda oficial sobre formatos](https://ajuda.spedy.com.br/pt-br/article/como-incluiralterar-o-certificado-digital-1ewfyjl/).

Timeout ou resposta inconsistente não permitem afirmar que o estado remoto permaneceu igual. Nesses casos, não há sucesso e os metadados locais anteriores são preservados; o suporte pode consultar a situação pelo diagnóstico central existente. Não foi criado rollback remoto de certificados. A interface mostra a última confirmação, não uma consulta em tempo real.

## Validações

- `vendor/bin/pest tests/Feature/App/TenantFiscalCertificateTest.php tests/Feature/App/NativeFiscalEmissionTest.php tests/Feature/Admin/FiscalCentralAdministrationTest.php --compact`: **54 testes passaram, 399 assertions**, em SQLite em memória, sem acesso ao banco de produção.
- Nova cobertura: `.pfx`/`.p12`, preservação de controles do RootAdmin, operador proibido, plataforma/tenant desabilitados, cadastro pendente, extensão/tamanho/senha inválidos, confirmação de substituição, sessão/IDs forjados, isolamento, erros sensíveis, respostas inconsistentes/inativas/vencidas, HTTPS, timeout, erro 500 e redirecionamento. A tela não expõe credenciais nem chama a API ao abrir.
- Teste anterior de upload atualizado para incluir confirmação obrigatória.
- `npm run types`, ESLint da tela e Pint dos arquivos PHP alterados: passaram.
- `npm run build`: passou; aviso de chunks maiores que 500 KB. Bundle gerado contém a mensagem final de confirmação de envio/substituição.
- Prettier da tela e `git diff --check`: passaram.

Não houve envio real de certificado nem emissão de nota: testes usam respostas HTTP simuladas e bloqueiam chamadas inesperadas. Validação com certificado/senha reais no sandbox e inspeção visual autenticada continuam pendentes. A suíte completa do projeto não foi executada; foram executadas as suítes fiscais relevantes. Nenhuma migration nova foi necessária.

## Commit e publicação

Na execução original, `.git` estava montado como somente leitura e o commit foi preparado fora do repositório (`/tmp/vetor-fiscal-06.git` e `storage/app/vetor-fiscal-06.bundle`). Em 2026-10-14 o trabalho foi commitado diretamente na `main`, rebaseado sobre `origin/main` (que já incluía VETOR-RELEASE-01 e os commits fiscais 05.x) e publicado junto com a atualização da landing page e do SEO das páginas públicas. Sem deploy e sem migrations.

---

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
