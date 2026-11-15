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
# VETOROS-NFSE-09.4 — resultado da execução

Data: 30/10/2026 (UTC).
Correio executado: SHA-256 `fc9d64890d93e2bf86cc8ff0d697192b365328617ee484a17c78d74c4a97a12c`.

## Resultado

O correio mudou de NFSE-09.3 para NFSE-09.4. As alterações fiscais anteriores foram revisadas, complementadas, testadas, commitadas e implantadas. O diagnóstico ganhou consulta de leitura no rootAdmin. **E1235 continua sem causa comprovada e não está resolvido.** O envio que explica E0617 foi corrigido no código; a aceitação fiscal não foi validada por emissão real.

Nenhuma nota foi emitida, reemitida ou cancelada e nenhuma configuração ou numeração fiscal foi alterada na Spedy nesta execução. Alterações de pedidos que já estavam no workspace foram preservadas e ficaram fora do commit e da imagem.

## Diagnóstico em produção

- A documentação oficial confirma `GET /v1/companies/{id}/settings`, autenticado por `X-Api-Key`. As consultas reais utilizaram a chave titular já existente no backend, sem exibi-la.
- Para o vínculo do tenant 9, antes e depois do deploy: HTTP 200, `serviceInvoice.nextNumber = null`, série `3`, ambiente `production`, emissão `annfs`. Localmente: próximo número `1`, confirmação de numeração em `2026-10-30T11:03:23Z`. A API aceita a autenticação existente, mas não fornece um contador utilizável para esse vínculo.
- O número local é o último número definido, não um contador que acompanha cada emissão. Não foi usado como prova do número remoto atual.
- Documento 15, rejeitado em `2026-10-30T11:39:30Z`: E1235 informa expressamente `nDPS = 0`. O payload armazenado não envia `number`, `nDPS` nem `nextNumber`. Portanto o zero surge na composição da DPS fora desse payload do VetorOS. **Não foi possível comprovar por que a Spedy usa zero, nem atribuir isso a uma sincronização específica.**
- Não foi enviada configuração de teste para investigar o efeito de omitir `nextNumber`, porque isso poderia alterar a numeração fiscal, o que está proibido pelo correio. É necessário esclarecer com a Spedy por que o GET retorna `null` após uma confirmação local anterior e por que a DPS foi composta com zero. Nenhuma mensagem foi enviada ao suporte.

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
## E0617, analisado separadamente

- Documento 14: alíquota enviada `2,5`; a rejeição diz que não é permitido informar alíquota quando o prestador não é optante do Simples Nacional, na competência e no município descritos pelo retorno.
- Cadastro local: regime `3`; cadastro remoto consultado: `regimeNormal`. Assim, o problema observado não é uma divergência entre esses dois cadastros.
- Documento 15 enviou alíquota `0`, mostrando que zerar ainda envia o campo. Essa tentativa parou em E1235, portanto não prova aceitação da tributação.
- Corrigido o builder compartilhado por OS, contratos e notas avulsas: no modo nacional com regime normal, omite `total.issRate`. Os demais modos/regimes mantêm o comportamento anterior. O contrato da Spedy admite esse campo opcional/nulo. Cobertura de regressão verifica a omissão no pedido de emissão.
- Não houve alteração do regime fiscal da empresa. A validação fiscal definitiva permanece pendente de uma emissão especificamente autorizada.

## Implementação

- Preservadas a migration e a proteção da NFSE-09.3: contador remoto conhecido igual a zero bloqueia a emissão, inclusive automática. Uma resposta sem contador não libera esse bloqueio nem renova a data da observação anterior.
- Adicionado `SpedyClient::getCompanySettings` e serviço `checkNfseNumbering`, usando somente GET. Contador ausente gera mensagem explícita, sem inventar valor.
- Nova ação rootAdmin `admin.fiscal.companies.check-numbering`, protegida pelo grupo rootAdmin e limite de requisições, com auditoria em caso de sucesso.
- Aba Spedy mostra o número local como histórico, o último contador remoto e a data da observação. Aviso explícito explica que emissões posteriores podem alterar o contador. Botão “Consultar contador da DPS” obtém um novo retrato quando a API fornece o valor.
- Testes novos verificam consulta GET com chave titular, preservação do histórico quando a API retorna null e omissão do ISS no regime normal nacional.

## Validação

- Suíte PHP completa final: **934 testes passaram, 6492 assertions**. SQLite em memória, credenciais operacionais vazias, HTTP simulado. Permanecem avisos de metadados legados do PHPUnit.
- JavaScript: `npm run test:js` passou, 6 testes.
- TypeScript: `npm run types` passou.
- Frontend: `npm run build` passou; build Docker do commit também passou. Aviso de tamanho de chunks permanece.
- Pint e `git diff --check`: passaram.
- Chromium/Playwright: renderização real da página rootAdmin produzida pelo Laravel com usuário e empresa sintéticos; diálogo Configurações e aba Spedy abertos; histórico, zero, data e botão conferidos; nenhum erro JavaScript. Evidência: `docs/nfse094/rootadmin.png`. Essa validação foi local com dados de teste, não uma sessão autenticada na produção.
- Logs de testes e tipos em `docs/nfse094/`.

## Backup, commit e deploy

- Backup consistente do banco antes da migration: `backups/nfse094/production-before.sql`, cerca de 16 MB, permissão 0600, fora do git. SHA-256: `766b7c240c507bb1aa34e5318f2ae31025b6290c5dc5ef82e5dc1b7c5e3ffacc`.
- Commit de código: `b16b9e8824876adf89267a4a8c10ba38c25d18f3`, enviado e confirmado no origin, branch `fix/efi-identificador-invalido`.
- Contexto Docker gerado por `git archive` desse commit, sem alterações locais de pedidos nem arquivos de ambiente do workspace.
- Imagem: `vetoros:deploy-nfse094-b16b9e88`; ID `sha256:3a4a67e2414bc8869c173e8e1715436112a79ed6578fe0811ff2f329b7811692`.
- Preservada a imagem anterior como `vetoros:rollback-before-nfse094`. A configuração existente do Compose foi mantida; seu tag `vetoros:deploy-nfse-20261030` aponta agora para a imagem nova.
- Aplicada somente a migration `2026_10_30_100000_add_spedy_numbering_check_to_fiscal_settings_table`, confirmada no lote 41. Ela adiciona campos de diagnóstico, sem alterar numeração.
- Web, worker e scheduler recriados e **healthy**. Nova rota presente no cache. `https://vetoros.com.br/up`: HTTP 200.
- Consulta GET após o deploy confirmou a preservação da configuração: local `1`, remoto `null`, série `3`.

## Pendências reais

1. Obter explicação da Spedy para o contador nulo e a geração de `nDPS = 0`; corrigir a origem somente quando comprovada. Não presumir que sincronizar novamente ou copiar o número local seja seguro.
2. Confirmar a aceitação fiscal da omissão de ISS e da correção futura de numeração quando houver autorização específica para emissão real.

Fontes oficiais consultadas: [obter configurações](https://docs.spedy.com.br/api-reference/empresas/obter-configura%C3%A7%C3%B5es), [alterar configurações](https://docs.spedy.com.br/api-reference/empresas/alterar-configura%C3%A7%C3%B5es), [OpenAPI](https://docs.spedy.com.br/openapi/v1.json).
