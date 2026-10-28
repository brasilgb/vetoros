# VETOROS-FISCAL-AVULSA-01 — resultado da execução

Data: 28/10/2026

## Resultado

**Parcial, implantado em produção.** Concluídos: mensagem amigável para SPD005, histórico de tentativas, visualização detalhada da nota e testes de regressão do payload. **Não concluído:** correção do E1235 (`nDPS = 0`). O VetorOS não envia número à Spedy, então o zero é gerado do lado da Spedy. Falta a Spedy confirmar o campo e a regra de numeração da DPS antes de qualquer alteração de numeração. Nenhuma chamada real à Spedy foi feita e nenhuma nota foi emitida.

## Deploy

- Commits em produção: `8e089083` (inativação de técnico libera OS e imagens para técnicos) e `1b7049e8` (NFS-e avulsa).
- Imagem: `vetoros:deploy-1b7049e8`. Rollback: `vetoros:rollback-20261028d` (imagem anterior `deploy-630893bc`).
- Migration `2026_10_28_100000_create_fiscal_document_attempts_table` aplicada no MySQL `vetoros` (batch 38). `migrate --force` posterior: nada pendente.
- Serviços `vetoros`, `vetoros-worker` e `vetoros-scheduler` healthy; nginx recarregado; `/healthz` 200; sem erros nos logs após o deploy.
- Arquivo alterado fora do repositório: `/opt/infra-abrasil/docker-compose.override.yml` (tag dos três serviços). Backup em `docker-compose.override.yml.bak-20261028-deploy1b7049e8`.

## Evidências

- `php artisan test` (suíte completa): **904 aprovados, 6389 asserções**.
- `tests/Feature/App/StandaloneInvoiceTest.php`: 22 aprovados (4 novos).
- Pint sem alertas nos PHP alterados; Prettier e ESLint sem erros no TSX alterado; `tsc` sem erros nas páginas fiscal e de pedidos.

## Pendências

1. Perguntar à Spedy o campo e a regra de numeração da DPS (NFS-e nacional) e o significado de E1235 e SPD005. Sem isso não há numeração a implementar com segurança.
2. Implementar numeração persistida depois da resposta, sem reiniciar sequências existentes.
3. Verificar em produção os fluxos de tela ("Ver detalhes", reprocessar, descartar) com uma nota de teste autorizada pelo responsável, sem emissão real não autorizada.
4. Rodar o cenário de homologação de `docs/homologacao-fiscal-avulsa.md` no sandbox da Spedy.

Relatório detalhado: `docs/VETOROS-FISCAL-AVULSA-01.md`.
