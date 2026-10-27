# VETOROS-EFI-RENEWAL-02.4 — Diagnóstico e correção definitiva

**Situação confirmada:** o código atualizado já recebeu commit e push. Não tratar ausência de commit ou push como causa presumida.

A tela real do tenant Sigma Operating Software continua sem a opção de renovar a assinatura, apesar da implementação da VETOROS-EFI-RENEWAL-02.1.

## 1. Encontrar a causa

Rastrear o fluxo completo desde a rota de acesso suspenso até a renderização do componente.

Verificar:

- Middleware de bloqueio de assinaturas.
- Controller responsável pela tela suspensa.
- Props enviadas pelo backend.
- Componente React/Inertia efetivamente renderizado.
- Condições de exibição do botão Renovar assinatura.
- Permissões do administrador do tenant.
- Endpoint de consulta dos planos.
- Filtros do catálogo de planos.
- Rotas e permissões do checkout Efí.
- Diferença entre cortesia expirada e assinatura paga vencida.

Utilizar logs e testes direcionados para identificar exatamente onde o fluxo deixa de funcionar.

Não simplesmente criar outro botão sem localizar a causa.

## 2. Corrigir

O administrador do tenant com cortesia expirada ou assinatura vencida deve conseguir:

1. Visualizar Renovar assinatura.
2. Abrir a seleção de planos.
3. Selecionar um plano válido.
4. Visualizar o resumo.
5. Prosseguir para pagamento.
6. Escolher o método Efí habilitado.
7. Efetuar ou acompanhar o pagamento.

As rotas necessárias devem permanecer acessíveis mesmo com a assinatura suspensa, sem liberar os módulos protegidos antes da confirmação financeira.

## 3. Testes obrigatórios

Reproduzir o cenário:

- Tenant: Sigma Operating Software.
- Plano anterior: Cortesia.
- Situação: Bloqueada.
- Vencimento: 23/10/2026.
- Carência encerrada: 25/10/2026.

Testar o fluxo com um administrador desse tenant e com um usuário sem permissão administrativa.

Verificar respostas HTTP, dados retornados, erros do navegador e renderização da interface.

Não modificar artificialmente o estado financeiro real do tenant.

## 4. Critério de conclusão

Não encerrar a atividade somente com PHPUnit, TypeScript ou build aprovados.

Comprovar visualmente que o administrador consegue:

**Acesso suspenso → Renovar assinatura → Escolher plano → Prosseguir para pagamento → Modal Efí.**

Apresentar a causa raiz encontrada, os arquivos corrigidos e evidências da navegação.

Preservar dados e funcionalidades existentes. Não executar containers locais.

Registrar o resultado em `docs/VETOROS-EFI-RENEWAL-02.4.md` e atualizar `executed.md`.

