# VETOROS-EFI-RENEWAL-02.4 — Diagnóstico da renovação com cortesia expirada

Data: 27/10/2026

## Resultado

**Causa encontrada, correção pendente de decisão comercial.** O código do fluxo de renovação está correto e foi comprovado por testes. O problema está nos dados: nenhum plano público está precificado em produção, então a tela de renovação não tem o que oferecer. Nenhum preço foi inventado nem alterado, e nenhum dado financeiro do tenant foi modificado.

## Causa raiz

1. A tela de bloqueio (`resources/js/pages/Subscription/Blocked.tsx`) mostra o botão **Renovar assinatura** quando a situação é `blocked`. O build em produção (`vetoros:deploy-630893bc`) contém esse botão.
2. Ao clicar, o painel (`SubscriptionPanel.tsx`) recebe a lista `plans` de `SubscriptionController::pageData()`.
3. A lista só inclui planos que passam em `Plan::availableForSelfService()`: ativo, público, não trial, não cortesia, **valor > 0** e com periodicidade.
4. Consulta somente leitura em produção (2026-10-27): todos os 7 planos cadastrados estão com `value = 0.00` e sem períodos. Nenhum passa no filtro.
5. Resultado: `plans` vem vazio, `payable` fica falso, e a tela mostra apenas "Fale com o atendimento da ABrasil Sistemas para regularizar a assinatura".

Tenant consultado: plano anterior **Cortesia**, vencimento 23/10/2026, carência até 25/10/2026, situação `blocked`.

## Itens verificados sem problema

- Efí Bank homologada em 19/10/2026, ambiente homologação, `efi_payee_code` com 32 caracteres (o problema de `identificador_invalido` do teste anterior não se repete).
- Métodos habilitados: Pix, boleto, cartão e cartão recorrente.
- Build em produção contém o botão "Renovar assinatura" e a tela "Acesso suspenso".
- Testes existentes de assinatura (`SubscriptionFlowTest`) e Efí SaaS (`EfiSaasPaymentsTest`) passam.

## Teste adicionado

`tests/Feature/App/SubscriptionCourtesyRenewalTest.php`:

- `courtesy_expired_tenant_sees_priced_plan_to_renew`: com um plano mensal precificado e período, a tela de bloqueio lista o plano e os métodos Efí. **Aprovado.**
- `unpriced_public_plans_leave_courtesy_expired_tenant_without_renewal_options`: reproduz o estado de produção (planos a R$ 0,00) e documenta que não há opção de renovação. **Aprovado.**

Resultado: 45 testes aprovados (653 asserções) nos três arquivos de assinatura e Efí. Pint sem alertas.

## Pendências

1. **Decisão comercial:** definir o valor e o período dos planos públicos (Mensal, Trimestral, Semestral, Anual) no RootAdmin. Sem isso, nenhuma empresa bloqueada consegue renovar pelo sistema. Depois de cadastrar, a renovação aparece sem alteração de código.
2. **Homologação visual não realizada:** o caminho até o modal Efí depende dos planos precificados, e não houve subir containers locais. Depois do item 1, percorrer em navegador: acesso suspenso → Renovar assinatura → escolher plano → prosseguir para pagamento → modal Efí.
3. **Mensagem de bloqueio:** quando não há plano disponível, a empresa vê apenas "Fale com o atendimento". Se quiser, a tela pode explicar o motivo ao administrador, mas isso é uma mudança de interface que não foi feita nesta etapa.
4. Alterações ainda não commitadas: teste novo e este relatório.
