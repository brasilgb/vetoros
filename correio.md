# VETOR-STABILIZE-02 — Correção do dashboard e preparação de deploy

A VETOR-UX-MONEY-02 está aprovada, com o commit `559d0111` publicado na branch `main` e CI verde.

## 1. Corrigir o gráfico do dashboard

Investigar `resources/js/components/Charts/chart-area-dashboard.tsx`.

- Tratar respostas da API com `lines` ausente, `null` ou vazio.
- Exibir um estado vazio amigável quando não houver dados.
- Não substituir erros reais da API silenciosamente por valores fictícios.
- Preservar o gráfico e seus indicadores quando houver informações válidas.
- Garantir que o dashboard não apresente erro fatal por ausência de dados.

## 2. Validar

- Criar testes de regressão para resposta vazia, incompleta e válida.
- Validar no Chromium e Firefox.
- Executar testes PHP e JavaScript, TypeScript e build.
- Não alterar regras financeiras, fiscais ou de assinatura.

## 3. Git

- Revisar `git status`.
- Realizar commit e push após aprovação dos testes.
- Confirmar CI verde.
- Registrar resultados em `executed.md`.

## 4. Preparação da VPS

Após a publicação, verificar o procedimento de deploy pelo repositório `infra-abrasil`.

- Confirmar o caminho real do submódulo `gateway/vetoros`.
- Conferir o commit remoto e o ponteiro do submódulo.
- Conferir o estado do ambiente e eventuais alterações locais.
- Planejar backup do banco e rollback.
- Confirmar que não existem migrations necessárias nesta entrega.
- Preparar os comandos para implantação.

**Não executar deploy, reiniciar containers ou modificar o banco de produção nesta etapa.**

Entregar relatório com a correção, commits, testes e procedimento de implantação.