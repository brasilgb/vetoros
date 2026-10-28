# VETOROS-FISCAL-AVULSA-01 — Correção da emissão de NFS-e avulsa

Data: 28/10/2026

## Resumo

- **E1235 (nDPS = 0): não reproduzido e não corrigível no VetorOS sem informação da Spedy.** O VetorOS não envia número algum à Spedy. Nenhum campo `nDPS`, `number` ou número de série sequencial é montado no payload. O zero não nasce no código deste repositório. Não foi criada numeração local: enviar um campo que a Spedy não documentou no contrato usado aqui quebraria a emissão. Detalhes na seção 1.
- **SPD005:** mensagem amigável implementada, sem troca automática de ambiente.
- **Histórico de tentativas:** implementado (tabela `fiscal_document_attempts`, painel "Ver detalhes").
- **Operações da tela:** verificadas; visualização detalhada adicionada. Reenvio, consulta, PDF/XML, descarte e cancelamento já existiam e foram conferidos.
- **Testes:** suíte completa `php artisan test`: **904 aprovados (6389 asserções)**. Não houve chamada real à Spedy (sandbox ou produção).

## 1. Auditoria

Fluxo avulso: `StandaloneInvoiceController::store` → `StandaloneInvoiceService::request` (valida, grava a solicitação e o financeiro na mesma transação, depois emite) → `NativeFiscalService::emitStandalone` → `NativeFiscalService::emit` (reserva o documento com `integration_id` UUID, monta o payload) → `SpedyPayloadBuilder::standaloneInvoice` → `SpedyClient::createInvoice` (`POST service-invoices`).

Onde os campos fiscais são gerados:

| Campo | Origem | Observação |
|---|---|---|
| `integrationId` | `NativeFiscalService::emit` (UUID, uma vez por documento; reenvio reaproveita) | Idempotência |
| `series` | `FiscalSetting::default_nfse_series` (padrão `'1'`) | Configuração do tenant |
| `environmentType` (`simulation`/`production`) | `FiscalSetting::emission_environment` | Não muda sozinho |
| `number` / `nDPS` | **Não enviado** | Número vem da Spedy na resposta (`SpedyInvoiceState::attributes`) |

Verificação do E1235:

- Busca em todo o código (`app`, `config`, `resources`, `database`, `tests`, `docs`) por `nDPS`, `E1235` e `SPD005`: nenhuma ocorrência de envio de número. Os únicos `number` no payload são o endereço do tomador.
- O VetorOS trata `number` devolvido pela Spedy como ausente quando vale `'0'` (`SpedyInvoiceState`, linha 66). Então um zero retornado não é gravado como número válido.
- Busca pública na web por "Spedy E1235" e "SPD005" não retornou documentação da Spedy. O significado dos códigos não foi confirmado.
- Conclusão: pelo que existe no repositório, o `nDPS = 0` é gerado do lado da Spedy (ou do mapeamento dela para a NFS-e nacional, `issueType = annfs`), a partir da solicitação recebida. **Confirmar com o suporte da Spedy** qual campo ela espera para a numeração da DPS e como a sequência é inicializada no emissor. Só depois disso é seguro implementar numeração no VetorOS.
- Sequências fiscais existentes **não foram alteradas**.

## 2. Entregue (E1235 — parte que o VetorOS controla)

- Teste de regressão `test_payload_never_sends_a_document_number_so_ndps_cannot_be_zero`: o payload da NFS-e avulsa nunca contém `number` nem `nDPS` (em qualquer nível) e leva o `integrationId` do documento.
- Idempotência já coberta (`duplicate request key…`, `timeout keeps processing…`): reenvio e reprocessamento usam o mesmo `integration_id` e o mesmo documento.
- Teste `test_attempt_history_keeps_failures_and_reprocess_under_the_same_integration_id`: o reprocessamento não cria outro documento nem outra chave.

Não implementado (depende da Spedy): numeração sequencial persistida no VetorOS, reserva de número com `lockForUpdate` e reconciliação de número em reenvio. A concorrência de solicitações já é coberta pela chave única `(tenant_id, request_key)` (`standalone_invoices_request_unique`).

## 3. SPD005

- `SpedyInvoiceState::friendlyMessage()` reconhece `SPD005` no código de rejeição (`processingDetail.code`) e nas mensagens de erro HTTP (`SpedyClient::exceptionFor`).
- Mensagem exibida: "A emissão de NFS-e não está disponível para o município e o ambiente configurados. Confira o código IBGE do município e o ambiente nas configurações fiscais; nenhuma troca para produção é feita automaticamente." O código continua na frente da mensagem (`[SPD005] …`).
- Nenhuma troca de ambiente. Nenhuma emissão real foi criada durante os testes (Spedy simulada com `Http::fake`).

## 4. Operações da tela

| Operação | Estado |
|---|---|
| Visualização detalhada | **Adicionada**: botão "Ver detalhes" com situação, ambiente, série, número, chave, emissão, erro e histórico |
| Correção e reenvio de rejeitadas | Já existia (`Reprocessar`, mesmo `integration_id`) |
| Consulta do estado na Spedy | Já existia (`Atualizar status`, `refresh`) |
| Download de XML e PDF | Já existia; aparece só quando o arquivo está disponível (nota autorizada ou cancelada) |
| Histórico de tentativas e mensagens | **Adicionado** (ver abaixo) |
| Exclusão controlada | Já existia: `Descartar` só em nota não autorizada; com recebimento, recusa e pede estorno. Cancelamento só em nota autorizada, com justificativa |
| Ambiente | Coluna "Ambiente" no detalhe: "Produção" ou "Homologação (simulação)" |

Histórico de tentativas:

- Tabela `fiscal_document_attempts` (somente acréscimo) com ação (`send` = envio, `sync` = atualização vinda da Spedy), situação local, situação na Spedy, código de erro, mensagem, usuário e data.
- Registrado em: falha de validação antes do envio, falha de envio (transitória ou definitiva) e cada atualização de status aplicada (`NativeFiscalService::applyInvoice`).
- A listagem traz as 10 últimas tentativas de cada nota em uma consulta só.

## 5. Multitenant

- `FiscalDocumentAttempt` usa a trait `Tenantable` e grava `tenant_id` da nota.
- A listagem de notas e o histórico passam pelo escopo do tenant. Teste existente `other tenant records are not reachable` continua passando.
- Credenciais, certificado e sequência vêm das configurações fiscais do próprio tenant (`FiscalSetting`), sem alteração.

## 6. Arquivos alterados

- `app/Services/Fiscal/Spedy/SpedyInvoiceState.php`: `friendlyMessage()` (SPD005) e uso em `rejectionMessage()`.
- `app/Services/Fiscal/Spedy/SpedyClient.php`: SPD005 nas mensagens de erro HTTP.
- `app/Services/Fiscal/NativeFiscalService.php`: registro de tentativas (`recordAttempt`), ação `send` em `emit`, parâmetro `$action` em `applyInvoice`.
- `app/Models/App/FiscalDocumentAttempt.php` (novo) e `app/Models/App/FiscalDocument.php` (relação `attempts()`).
- `database/migrations/2026_10_28_100000_create_fiscal_document_attempts_table.php` (novo).
- `app/Http/Controllers/App/FiscalDocumentController.php`: `attempts` na listagem.
- `resources/js/pages/app/fiscal-documents/index.tsx`: botão e diálogo "Ver detalhes".
- `tests/Feature/App/StandaloneInvoiceTest.php`: 4 testes novos (payload sem número, SPD005 em erro HTTP, SPD005 em rejeição, histórico com reenvio).

## 7. Testes realizados

- `php artisan test tests/Feature/App/StandaloneInvoiceTest.php`: 22 aprovados.
- `php artisan test` (suíte completa): 904 aprovados, 6389 asserções.
- Pint: sem alertas nos arquivos PHP alterados.
- Prettier e ESLint: sem erros no TSX alterado. `tsc --noEmit`: sem erros nas páginas fiscal e de pedidos.

## 8. Bloqueios externos

- **Sem chamada real à Spedy.** Não há ambiente de sandbox autorizado para esta etapa nesta sessão, e a documentação da API não está disponível aqui. Por isso o cenário "emitir na simulação" do `docs/homologacao-fiscal-avulsa.md` continua pendente.
- **E1235:** depende de confirmação da Spedy sobre numeração da DPS (seção 1).
- **SPD005:** a condição exata em que a Spedy devolve o código precisa ser confirmada na simulação; a mensagem cobre os dois formatos que o VetorOS já trata.
- Nenhuma nota real foi emitida. Nenhum deploy foi feito.

## 9. Próximos passos

1. Perguntar à Spedy o campo e a regra de numeração da DPS (NFS-e nacional) e o significado de E1235 e SPD005.
2. Com a resposta, implementar numeração persistida (contador atômico por tenant, prestador, série e ambiente), sem reiniciar sequências existentes.
3. Rodar o cenário de homologação do `docs/homologacao-fiscal-avulsa.md` no sandbox da Spedy, com o tenant de homologação.
