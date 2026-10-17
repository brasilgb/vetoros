# Homologação da emissão fiscal avulsa na VPS (VETOR-STABILIZE-01)

Roteiro para validar a VETOR-FISCAL-AVULSA-01 com a Spedy **em sandbox/homologação**, antes de liberar para produção. Nenhuma nota com valor fiscal deve ser emitida nesta etapa.

## 1. Condições para começar (todas obrigatórias)

| # | Condição | Como conferir |
|---|----------|---------------|
| 1 | Backup verificado do banco da VPS, tirado imediatamente antes do deploy | `mysqldump` concluído, tamanho coerente e `grep -c "CREATE TABLE"` > 0 |
| 2 | Código publicado contendo os commits da FISCAL-AVULSA-01 e da STABILIZE-01 | `git log --oneline -3` na VPS |
| 3 | Migration `2026_10_17_100000_create_standalone_invoices_table` aplicada | `php artisan migrate:status` sem pendências |
| 4 | `SPEDY_ENVIRONMENT=sandbox` e chave do **dono da conta sandbox** em `SPEDY_OWNER_API_KEY` | `php artisan tinker --execute='echo config("services.spedy.environment");'` → `sandbox` |
| 5 | Webhook cadastrado na Spedy sandbox apontando para `https://<domínio>/api/webhooks/spedy`, com o segredo em `SPEDY_WEBHOOK_SECRET` | um evento de teste gera linha em `fiscal_webhook_events` |
| 6 | Fila em execução (guarda de PDF/XML) e agendador ativo (`fiscal:sync-spedy` a cada 10 min) | `ps` mostra `queue:work`; `crontab -l` mostra `schedule:run` |
| 7 | Cache recriado depois de ajustar o `.env` | `php artisan optimize` (a imagem já roda no entrypoint) |
| 8 | Tenant **exclusivo de homologação** (nunca um cliente real) com: módulo fiscal ativo, empresa emissora cadastrada na Spedy sandbox, ambiente **Homologação**, NFS-e/NF-e/NFC-e liberadas no RootAdmin, certificado A1 de teste válido, CSC de homologação (NFC-e), dados tributários confirmados, módulo financeiro ativo | Tela Notas Fiscais: o modal não mostra "Indisponível" nos três tipos |
| 9 | `production_released_at` vazio para esse tenant | a trava "A emissão em produção ainda não foi aprovada" impede produção por engano |
| 10 | Caixa aberto no tenant de homologação (para o cenário de recebimento) | Financeiro → Caixa |
| 11 | Dois clientes de teste: um CNPJ e um CPF válidos, com endereço completo; uma peça com NCM/CFOP válidos e uma com NCM inválido (ex.: `00000000`) para forçar rejeição | Cadastros |

## 2. Cenários

Para cada cenário, registre: hora, tipo, número/situação na tela, `fiscal_documents.id`, `integration_id` e o resultado do financeiro.

| # | Cenário | Passos | Resultado esperado |
|---|---------|--------|--------------------|
| 1 | NFS-e avulsa sem financeiro | Emitir nota avulsa → NFS-e → cliente CNPJ → descrição e valor → Revisar → Emitir | Nota "Em processamento" e, após o webhook, "Autorizada"; nenhuma conta a receber criada |
| 2 | NFS-e com conta a receber | Igual ao 1, marcando "Registrar no financeiro" → Conta a receber, vencimento futuro | Conta em `accounts_receivable` (`source_type = standalone_invoice`), situação **a receber** mesmo depois da autorização |
| 3 | NF-e avulsa de produtos | NF-e → cliente com endereço → peça válida, quantidade e valor → forma de pagamento | Autorizada; estoque da peça **inalterado** |
| 4 | NFC-e avulsa | NFC-e → sem cliente → peça válida → Dinheiro | Autorizada sem destinatário |
| 5 | Pagamento já recebido | Qualquer tipo + "Recebimento já realizado" com data de hoje | Conta quitada, entrada no caixa aberto; sem caixa aberto a emissão é recusada sem gravar nada |
| 6 | Rejeição e reprocessamento | NF-e com a peça de NCM inválido → corrigir o NCM no cadastro → botão Reprocessar na listagem | Primeiro envio "Rejeitada" com o motivo; reprocessamento usa o **mesmo** `integration_id` e o mesmo documento |
| 7 | Descarte | Em uma nota rejeitada com conta a receber, usar Descartar | Solicitação descartada e conta cancelada; com recebimento ativo, recusa pedindo estorno |
| 8 | Cancelamento | Em nota autorizada, Cancelar com justificativa de 15+ caracteres | Situação "Cancelada"; financeiro **não** alterado |
| 9 | PDF/XML | Abrir PDF e baixar XML de uma nota autorizada e da cancelada | Arquivos em `storage/app/private/fiscal` (ou `FISCAL_STORAGE_ROOT`) e `xml_sha256` preenchido |
| 10 | Conciliação | Desligar o webhook temporariamente, emitir, religar e rodar `php artisan fiscal:sync-spedy` | Nota sai de "Em processamento" pela consulta por `integrationId` |
| 11 | Duplicidade | Clicar duas vezes em "Emitir nota" | Uma única nota e um único lançamento |

## 3. Critérios de aceite

- Os 11 cenários com o resultado esperado e sem erro no `storage/logs/laravel.log`.
- Nenhum documento com `environment = production` criado durante a homologação.
- Contagens de `accounts_receivable`, `account_receivable_payments` e `cash_session_movements` coerentes com os cenários.

Só depois disso a liberação de produção (`production_released_at`) pode ser considerada, por decisão do responsável.
