# VETOROS-PERFORMANCE-OS-04: resultado da execução

Data: 13/11/2026
SHA-256 de `correio.md`: `9ba31e6ca39c1e3d09c0c8c255d876d69f3eab73286b9e28b9c0afe3ccf021ef`

O `correio.md` mudou desde a execução anterior. A anterior era a PERFORMANCE-OS-03 (SHA `02c9bfe2…`); a atual é a PERFORMANCE-OS-04, remoção da consulta pública de OS. Este arquivo foi limpo e substituído pelo resultado desta execução.

## Resultado

**Implementado e validado localmente. Sem commit, push ou deploy**, que dependem de autorização explícita. A VPS não foi acessada.

## O que foi feito

- **Consulta pública desativada.**
  - As 11 rotas `/os/{token}/...` foram substituídas por uma página estática com **HTTP 410**. Ela trata acompanhamento, chave, orçamento, aviso, retirada, recibos, comprovantes, PDF da NFS-e e feedback.
  - A página não consulta o banco e não mostra dados.
  - Foram removidos `OsController`, `PublicOrderPresenter` e as páginas `serviceorders/*`.
- **Chave pública removida.**
  - Sem geração na criação da OS (formulário e contratos), sem bcrypt, sem HMAC e sem decifrar por linha.
  - Saíram a regeneração por administrador, o comando de backfill da OS-03, a auditoria da chave e a opção "Solicitar chave de acesso".
- **Links removidos:**
  - item "Acompanhamento público" na listagem;
  - botão nos follow-ups;
  - diálogo "Acesso do cliente" na edição;
  - recibo impresso e PDF;
  - cinco e-mails.
- **`{{ link_os }}`:**
  - Saiu dos templates padrão, do seeder e da tela de templates.
  - Templates já salvos pelos tenants não foram alterados: no envio, servidor e navegador descartam a linha que contém o link.
  - As demais variáveis continuam iguais.
- **NFS-e da OS:** o link passou a usar a rota autenticada. Uma migration reescreve os links antigos gravados.
- **Mantido:**
  - criação, edição, listagem e status de OS;
  - orçamento com versões e aprovação interna;
  - mensagens manuais de WhatsApp e e-mail;
  - histórico, auditoria e multi-tenant;
  - autenticação;
  - URLs assinadas de fotos e assinatura e links assinados de NFS-e de contrato e SaaS.

## Banco de dados

- **Migration da OS-03:** reescrita para criar só o índice `(tenant_id, id)`. A coluna `public_access_key_check` não é mais criada e **o backfill não foi executado**.
- **Nova migration `repoint_order_fiscal_document_urls`:** só reescreve URL. Nenhum dado foi apagado.
- **Remoção planejada, não executada:** `orders.public_access_key`, `orders.public_access_key_hash`, `others.public_order_access_key_required` e, em duas fases, `orders.tracking_token`. O plano está na seção 3 do relatório.

## Testes

- **Suíte completa:** 884 aprovados, 1 ignorado e 0 falhas.
  - Há 6 testes novos em `OrderPerformanceOs04Test`, e criar, listar e abrir OS são verificados sem nenhum bcrypt.
  - Os testes que só exercitavam o portal ou a chave foram removidos.
  - Os fluxos de orçamento, entrega e NFS-e foram adaptados ao fluxo interno.
- **Demais verificações:** types, build e Pint aprovados. ESLint sem problemas novos (mesma contagem do `HEAD`).

## Desempenho

Mesmo ambiente de carga da OS-03: 50 tenants, 202 mil OS e 10 workers.

- **Criar OS, 1 usuário:** p50 caiu de **~300 ms para 35 ms**; a remoção do bcrypt confirmou o ganho.
- **Criar OS, p95 com carga:** caiu de 536–1.727 ms para **81–532 ms**.
- **50 tenants × 50 usuários:** a listagem caiu de 4.039 para 804 ms de p95, a abertura de 1.536 para 426 ms e o formulário de 1.357 para 326 ms.
- **Criação concorrente:** 1.832 OS em 30 s, com 0 erros e 0 números duplicados.
- **Listagem e abertura com 1 usuário:** ficaram na faixa da OS-03.

## Pendências

1. **Confirmar com o negócio o impacto para o cliente final.** O cliente não acompanha mais a OS pela internet, não aprova orçamento nem avalia o atendimento pelo link, e não baixa recibos ou NFS-e. Tudo isso passa a ser registrado pela equipe, e não há mais canal de nota do cliente.
2. **Autorizar commit e deploy.** A ordem é:
   1. Rodar as migrations.
   2. Publicar o código.
   3. Reiniciar o `vetoros-worker`.

   A OS-03 deve ir junto com esta etapa.
3. **Migration posterior** de remoção das colunas legadas, depois que esta versão estiver estável em produção.
4. **Limpeza posterior do código que ficou sem uso:**
   - `OrderBudgetService::respondAsCustomer`;
   - `OrderStatusService::recordCustomerAction`;
   - os eventos e listeners de ações do cliente.
5. **Pendências que vêm da OS-03:**
   - rotacionar a senha do banco exposta no histórico do Git;
   - medir o buffer pool do MySQL na VPS;
   - confirmar p50 e p95 em produção.
6. **Remover o banco local de carga, se quiser:** `DROP DATABASE vetoros_perf_os03`. Ele recebeu as migrations desta etapa e cerca de 2,6 mil OS de teste.

Relatório completo: `docs/VETOROS-PERFORMANCE-OS-04.md`.
