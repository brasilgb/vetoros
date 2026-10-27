# VETOROS-EFI-RENEWAL-02.2 — publicação e homologação

Data: 27/10/2026

## Estado

**NÃO PUBLICADO.** A implementação de renovação da etapa 02.1 passou na validação local. Publicação e homologação real dependem de acesso GitHub com escrita e acesso autorizado à VPS.

## Git e escopo auditado

- `correio.md` atual substitui a instrução anterior de deploy por esta etapa de renovação. SHA-256: `c2c5b8f5a88db4da0e54a706f84fe1aafec0ba2fe142ce4d4e00317ee521f14e`.
- Checkout original: `main` em `e1a9489868f205afa2013306e299d79a942d32af`. As mudanças 02.1 seguem como alterações locais porque o diretório Git do checkout é somente leitura nesta sessão.
- Clone em `/tmp/vetoros-renewal-publish-20261027`: commit `7824414c` (`feat(vetoros): enable self-service subscription renewal`). Os 15 arquivos de código e migration da etapa 02.1 foram comparados com o checkout original e coincidem.
- `origin/main` foi consultado por HTTPS e sincronizado no clone: `e1a9489868f205afa2013306e299d79a942d32af`. O commit de código está à frente do remoto, sem divergência de histórico observada.
- A chave SSH disponível autenticou no GitHub como `brasilgb/vetorleads`. O push simulado para `brasilgb/vetoros` foi negado com `Permission to brasilgb/vetoros.git denied to deploy key`. Portanto, não houve push real, SHA publicado ou alteração do remoto. Nenhum force push foi tentado.

## Validação local

| Verificação | Resultado |
| --- | --- |
| Testes Efí: `php artisan test --compact tests/Feature/Admin/EfiSaasPaymentsTest.php` | 26 testes, 336 asserções, aprovados |
| Suíte PHP: `php artisan test --compact` | 894 testes, 6.275 asserções, aprovados |
| TypeScript: `npm run types` | aprovado |
| Frontend: `npm run build` | aprovado; aviso de chunks acima de 500 kB |
| `git diff --check` | aprovado |

O primeiro comando da suíte completa foi iniciado sem `.env.testing` e apresentou falhas; foi interrompido. Após criar `.env.testing` a partir de `.env.testing.example` e gerar uma chave local de testes, a suíte completa passou. O arquivo está ignorado pelo Git. Os testes usam SQLite em memória e simulação da Efí; não constituem homologação no gateway real.

A migration `2026_10_27_000000_add_availability_to_plans.php` adiciona dois campos booleanos (`is_active`, `is_public`) com padrão `true`. Pela definição, os planos existentes permanecem ativos e públicos; nenhuma atualização de dados é feita na migration. A aplicação em banco de produção e a verificação de dados existentes dependem do acesso à VPS e de backup confirmado.

## Produção e homologação

- Não foi identificado acesso autorizado à VPS nesta sessão; `/opt/infra-abrasil` não existe no ambiente local e não há destino SSH da VPS configurado aqui. Backup de produção: não verificado.
- Migration em produção: não executada. Deploy: não executado. Containers locais: não executados.
- Logs, rotas, jobs, filas, webhooks e estado dos tenants em produção: não verificados.
- Fluxo visual desktop e mobile, pagamento pendente, retorno ao dashboard e conciliação real da Efí: não homologados. Nenhum pagamento real foi criado ou simulado no ambiente de produção.

## Próximos passos para concluir

1. Conceder ao operador uma credencial GitHub com escrita em `brasilgb/vetoros`, confirmar novamente `origin/main` e publicar os commits sem force push.
2. Obter acesso autorizado à VPS, confirmar backup íntegro e estado do banco, validar migrations pendentes e aplicar a migration de disponibilidade antes de servir o novo código.
3. Publicar a revisão, verificar logs, rotas, jobs, filas e webhooks, e homologar o percurso completo em desktop e mobile com tenant de teste identificável.
4. Comprovar a conciliação Efí por eventos confiáveis e consulta ao gateway, sem simular pagamento real, e verificar que dados e acesso dos demais tenants foram preservados.

Somente após essas etapas registrar o SHA remoto e declarar a publicação e a homologação concluídas.
