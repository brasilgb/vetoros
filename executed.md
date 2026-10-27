# VETOROS-EFI-RENEWAL-02.4 — resultado da execução

Data: 27/10/2026
SHA-256 de `correio.md`: `09c143cce2fc96f72ab23676f1bac0c624dfa2bcf74145b4f62fb73e5f079cd5`

`correio.md` difere da versão commitada (02.2). Este arquivo foi limpo e substituído pelo resultado da etapa 02.4. O histórico anterior permanece no Git (commit `630893bc`).

## Resultado

**Causa raiz encontrada. Correção depende de preço dos planos, decisão comercial pendente.** A tela de renovação funciona; não há planos disponíveis para renovação porque todos os planos públicos estão cadastrados com valor R$ 0,00 e sem período. Nenhum preço foi definido e nenhum dado financeiro do tenant foi alterado.

## Evidências

- Tenant Sigma Operating Software: plano Cortesia, vencimento 23/10/2026, carência até 25/10/2026, situação `blocked`.
- Consulta somente leitura em produção (`php artisan tinker` no container `infra-abrasil-vetoros-1`): os 7 planos (Trial, Cortesia, Mensal, Trimestral, Semestral, Anual, Cobrança Presencial) têm `value = 0.00` e nenhum período. Nenhum passa em `Plan::availableForSelfService()`.
- Fluxo: `Subscription/Blocked` mostra "Renovar assinatura" → `SubscriptionPanel` recebe `plans = []` → mostra "Fale com o atendimento".
- Efí Bank: homologada em 19/10/2026, `efi_payee_code` com 32 caracteres, métodos Pix, boleto, cartão e cartão recorrente habilitados.
- Build em produção (`vetoros:deploy-630893bc`) contém o botão "Renovar assinatura".
- Teste novo `tests/Feature/App/SubscriptionCourtesyRenewalTest.php`: 2 casos, ambos aprovados. Um com plano precificado (lista o plano e os métodos Efí); outro com planos a R$ 0,00 (reproduz o estado de produção).
- Testes: `SubscriptionCourtesyRenewalTest`, `SubscriptionFlowTest` e `EfiSaasPaymentsTest`: **45 aprovados, 653 asserções**. Pint sem alertas.

## Alterações

- Novo: `tests/Feature/App/SubscriptionCourtesyRenewalTest.php`.
- Novo: `docs/VETOROS-EFI-RENEWAL-02.4.md` (relatório detalhado).
- Nenhuma alteração de código de produção. Nenhuma migration, deploy ou commit.

## Pendências

1. Definir valor e período dos planos públicos no RootAdmin. Sem isso, empresas bloqueadas não conseguem renovar pelo sistema. Depois disso a renovação aparece sem alteração de código.
2. Homologação visual não realizada: depende do item 1. Percorrer em navegador: acesso suspenso → Renovar assinatura → escolher plano → prosseguir para pagamento → modal Efí.
3. Decidir se a tela de bloqueio deve explicar ao administrador o motivo quando não há plano disponível (hoje mostra apenas "Fale com o atendimento").
4. Commit e push das alterações (teste novo, relatório e executed.md) aguardam autorização.

Relatório detalhado: `docs/VETOROS-EFI-RENEWAL-02.4.md`.
