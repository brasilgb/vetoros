# Relatório de execução — VETOR-PUBLISH-01

## Comparação

O `correio.md` atual difere da versão anterior, `VETOR-STABILIZE-03`. A nova ordem consolida entregas de VetorOS e VetorLeads, solicita testes e publicação no GitHub, atualização do procedimento de implantação e confirmação do CI. Proíbe acesso à VPS, deploy, migrations em produção, reinício de containers e mudanças de infraestrutura.

## Entregas consolidadas

- **VetorOS — dashboards:** validação de payloads, estados de carregamento/erro/sem dados, opção de nova tentativa e preservação dos cálculos existentes.
- **VetorOS — SMTP institucional:** configuração Laravel por `ABRASIL_MAIL_*`, separada do SMTP de cada tenant. A inspeção do `docker-compose.yml` do infra-abrasil confirmou que os valores vêm do ambiente protegido da VPS; `.env.example` documenta as chaves e mantém a senha vazia. Nenhuma credencial foi lida ou versionada.
- **VetorOS — Modo Suporte:** RootAdmin reautenticado, motivo obrigatório, sessão vinculada ao tenant no banco, expiração, isolamento, encerramento e trilha de ações. Escritas permitidas ficam em allowlist; operações críticas são bloqueadas.
- **VetorOS — contato e landing:** WhatsApp oficial configurável, CTAs conectados ao canal, cópia de fallback removida do frontend e hero com imagem de manutenção de celulares, PCs, notebooks e eletrônicos.
- **VetorLeads — WAHA:** número e sessão configurados individualmente por empresa, reaproveitados nos envios e na conexão; o contato institucional do site permanece separado do remetente dos tenants. Os fluxos existentes de conversa foram preservados, sem expandir o escopo para um chat novo.
- **VetorLeads — landing:** hero atualizado com imagem profissional de equipe de software, otimizada para WebP (87 KB). O telefone dos dados estruturados agora usa o contato configurado.
- **Procedimento de publicação:** `README.md` lista as migrations relacionadas ao suporte e ao canal comercial. `docs/production-checklist.md` agora exige conferência das migrations e configuração de SMTP no ambiente protegido.

## Validação local

- **VetorOS:** suíte PHP completa — 803 testes e 5.601 asserções aprovados; 4 testes JS; TypeScript; ESLint e Prettier na superfície alterada; Pint; build Vite; `secret-guard` em 1.216 arquivos versionados, sem achados.
- **VetorLeads:** suíte PHP completa — 1.180 testes e 12.507 asserções aprovados; TypeScript; ESLint direcionado às telas WAHA, contatos e páginas públicas; build Vite. O build final inclui a nova imagem WebP.
- `git diff --check` passou para as alterações desta execução. Os artefatos temporários de `public/build` foram removidos depois das validações.
- O lint global do VetorOS aponta 485 erros e 14 avisos em arquivos fora da superfície alterada. O lint global do VetorLeads termina com 223 erros em outras áreas; sem excluir bundles antigos, a execução encerrou por falta de memória. Os arquivos diretamente relacionados a esta entrega passaram na checagem direcionada.
- O VetorOS tem uma migration para sessões de suporte e outra para o canal comercial: `2026_10_17_200000_create_support_sessions_table` e `2026_10_17_210000_add_commercial_whatsapp_settings`. Elas foram testadas localmente e devem ser aplicadas somente em uma futura publicação autorizada, após backup.
- A configuração SMTP foi validada pelos testes de configuração e isolamento. Não foi feito envio para destinatários reais.

## Limitações de validação

- Chromium não está instalado. Firefox está instalado, mas o servidor local não conseguiu abrir a porta de loopback neste ambiente; por isso não foi possível completar uma inspeção visual automatizada nos navegadores nem confirmar screenshots desktop/mobile.
- Há workflow de CI nos dois projetos, mas o estado remoto não pôde ser consultado: `gh auth status` reporta token inválido e a sessão não resolveu `github.com`. Os resultados abaixo confirmam os pushes, não o estado do CI.
- O VetorLeads não contém o script `scripts/security/secret-guard.php`; os testes de segurança da suíte passaram, e esta execução não introduziu credenciais.

## Commits, branches e publicação

- **VetorOS — `main`:** suporte/SMTP/dashboards `1bd11b0c`; WhatsApp e landing VetorOS `03591fb8`. Ambos já estão incluídos no histórico publicado da branch.
- **VetorLeads — `main`:** configuração WAHA por tenant `8190eb2`; contato comercial `d7c3892`; ajustes desta execução e hero WebP `da7cd21` — push confirmado.
- O relatório e o procedimento de publicação do VetorOS serão registrados na branch `main` depois da revisão final desta rodada.
- Nenhum deploy, acesso à VPS, migration em produção, reinício de container ou alteração de infraestrutura foi realizado.

## Preservação de alterações preexistentes

As alterações preexistentes em `correio.md` foram mantidas fora dos commits. O `composer.lock` modificado no VetorLeads também foi preservado sem inclusão. Nenhum ponteiro de submódulo ou arquivo de outro projeto foi alterado.
