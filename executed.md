# VETOROS-FISCAL-AVULSA-01 — resultado da execução

Data: 28/10/2026

## Resultado

**Parcial.** Concluídos: SPD005 com mensagem amigável, histórico de tentativas, visualização detalhada da nota e testes de regressão do payload. **Não concluído:** correção do E1235 (`nDPS = 0`). O VetorOS não envia número à Spedy, então o zero é gerado do lado da Spedy. Falta a Spedy confirmar o campo e a regra de numeração da DPS antes de qualquer alteração de numeração. Nenhuma chamada real à Spedy foi feita e nenhuma nota foi emitida.

## Evidências

- `php artisan test` (suíte completa): **904 aprovados, 6389 asserções**.
- `tests/Feature/App/StandaloneInvoiceTest.php`: 22 aprovados (4 novos).
- Pint sem alertas nos PHP alterados; Prettier e ESLint sem erros no TSX alterado; `tsc` sem erros nas páginas fiscal e de pedidos.

## Alterações

- SPD005: `app/Services/Fiscal/Spedy/SpedyInvoiceState.php` e `SpedyClient.php`.
- Histórico de tentativas: migration `2026_10_28_100000_create_fiscal_document_attempts_table.php`, modelo `FiscalDocumentAttempt`, registro em `NativeFiscalService.php`, `attempts` na listagem (`FiscalDocumentController.php`).
- Tela: diálogo "Ver detalhes" em `resources/js/pages/app/fiscal-documents/index.tsx`.
- Testes: `tests/Feature/App/StandaloneInvoiceTest.php`.
- Relatório: `docs/VETOROS-FISCAL-AVULSA-01.md`.

## Pendências

1. Perguntar à Spedy o campo e a regra de numeração da DPS (NFS-e nacional) e o significado de E1235 e SPD005. Sem isso não há numeração a implementar com segurança.
2. Implementar numeração persistida depois da resposta, sem reiniciar sequências existentes.
3. Rodar o cenário de homologação de `docs/homologacao-fiscal-avulsa.md` no sandbox da Spedy.
4. Commit e push: ver a mensagem de entrega.

Relatório detalhado: `docs/VETOROS-FISCAL-AVULSA-01.md`.
