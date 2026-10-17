# VETOR-UX-MONEY-02 — Consolidação e publicação

A VETOR-UX-MONEY-01 está aprovada.

Executar os seguintes ajustes finais:

1. Padronizar todos os campos monetários restantes no modo `cents` do componente `MoneyInput`.
2. Preservar a colagem de valores em formatos brasileiro e internacional.
3. Garantir que os valores enviados ao Laravel permaneçam no formato decimal normalizado.
4. Não alterar migrations, valores existentes ou regras financeiras.
5. Manter percentuais, quantidades e alíquotas fora da máscara monetária.
6. Revisar a apresentação da licença em Sistema e módulos, mantendo informações essenciais visíveis e detalhes complementares em modal, quando apropriado.
7. Preservar integralmente os fluxos de pagamento Efí Bank e a tela de desbloqueio.
8. Executar testes PHP, JavaScript, TypeScript, build e testes visuais no Firefox e Chromium.
9. Verificar o `git status`, incluindo alterações anteriores não relacionadas, para evitar commits acidentais.
10. Estando tudo aprovado, realizar commit e push na branch atual, sem executar deploy ou migrations em produção.

Registrar os resultados e o hash do commit no `executed.md`.

Não modificar regras de negócio, emissão fiscal ou cobrança recorrente além do necessário para os ajustes de interface.