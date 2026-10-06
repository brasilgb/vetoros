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
