# VETOR-RELEASE-01 — Preparação e publicação segura dos commits

Projeto: `infra-abrasil/gateway/vetoros`

**Objetivo:** publicar os commits locais aprovados, incluindo `ec9cf0e6`, preservando todas as alterações de outras frentes.

Antes de qualquer publicação:

1. Auditar `git status`, branches, commits locais e remotos e alterações não commitadas.
2. Verificar os bloqueios P0 documentados em VETOR-SEC-03.
3. Confirmar a privacidade dos repositórios e o tratamento das credenciais expostas.
4. Não publicar enquanto os requisitos de segurança não estiverem cumpridos.
5. Preparar a preservação cifrada das evidências e um plano de limpeza do histórico, sem executar operações destrutivas sem autorização específica.
6. Verificar a integridade dos commits fiscais e demais commits locais.
7. Executar `secret-guard` e `gitleaks` e apresentar os resultados.
8. Preparar a atualização do submódulo `gateway/vetoros` no repositório `infraabrasil`.

**Se todos os requisitos estiverem comprovadamente atendidos e não for necessária reescrita do histórico, executar o push convencional dos commits aprovados.**

Caso seja necessário `force push`, interromper e solicitar aprovação explícita apresentando o plano, os riscos e o rollback.

Não executar deploy, migrations ou alterações na VPS.

Gerar relatório completo da publicação ou dos bloqueios encontrados.