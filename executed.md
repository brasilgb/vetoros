# VETOROS-EFI-RENEWAL-02.2 — resultado da execução

Data: 27/10/2026
SHA-256 de `correio.md`: `c2c5b8f5a88db4da0e54a706f84fe1aafec0ba2fe142ce4d4e00317ee521f14e`

`correio.md` difere da versão anterior no Git. A instrução atual pede publicação e homologação da renovação Efí. Este arquivo foi limpo e substituído pelo resultado da etapa 02.2.

## Resultado

**NÃO PUBLICADO; homologação em produção pendente.** O código da etapa 02.1 foi validado localmente, mas o push foi recusado por falta de permissão de escrita no repositório. Não houve migration ou deploy na VPS, nem cobrança real.

## Evidências

- Checkout original: `main` em `e1a9489868f205afa2013306e299d79a942d32af`, com as alterações da etapa 02.1 ainda no diretório de trabalho. O diretório Git desse checkout está fora da área gravável.
- Clone temporário `/tmp/vetoros-renewal-publish-20261027`: commit de código `7824414c` (`feat(vetoros): enable self-service subscription renewal`). Os arquivos de código e a migration coincidem com os do checkout original.
- `origin/main` consultado e sincronizado em `e1a9489868f205afa2013306e299d79a942d32af`; o commit `7824414c` está um commit à frente, sem conflito remoto observado. Nenhum SHA foi publicado.
- A chave SSH disponível autentica como `brasilgb/vetorleads`, mas `git push --dry-run` para `brasilgb/vetoros` retorna `Permission to brasilgb/vetoros.git denied to deploy key`. Não foi usado force push.
- Testes Efí focados: **26 aprovados, 336 asserções**. Suíte PHP completa: **894 aprovados, 6.275 asserções**. Para isso foi criado `.env.testing` local e ignorado pelo Git, a partir do modelo do projeto; banco SQLite em memória e simulador Efí. Houve avisos de depreciação de metadados PHPUnit.
- `npm run types`, `npm run build` e `git diff --check`: aprovados. O build emitiu aviso sobre chunks acima de 500 kB.
- A migration de planos adiciona `is_active` e `is_public` com padrão `true`, sem alterar registros existentes na definição. Compatibilidade em produção e backup do banco ainda não foram verificados.

## Pendências

- Disponibilizar uma identidade GitHub com permissão de escrita em `brasilgb/vetoros`; publicar o commit validado sem force push e registrar o SHA remoto.
- Disponibilizar acesso autorizado à VPS e confirmar backup íntegro; aplicar a migration antes do novo código, fazer deploy e verificar serviços, logs, filas, rotas e webhooks.
- Homologar em navegador desktop e mobile o fluxo de renovação e os casos de isolamento, autorização, plano inativo, falha e webhook repetido. Comprovar a conciliação Efí com credenciais autorizadas e sem simular pagamentos reais.

Relatório detalhado: `docs/VETOROS-EFI-RENEWAL-02.2.md`.
