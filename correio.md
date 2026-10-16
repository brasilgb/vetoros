# VETOR-SAAS-CONTRATO-01 — Contrato comercial personalizado por tenant

## Objetivo

Implementar no RootAdmin do VetorOS a possibilidade de estabelecer condições comerciais personalizadas para cada empresa (tenant), sem depender da seleção de planos tradicionais.

**Não criar um novo sistema financeiro ou fiscal.** Reutilizar os serviços existentes de assinaturas, cobrança, pagamentos, inadimplência, bloqueio, emissão Spedy, entrega de faturas e auditoria.

## 1. Auditoria inicial obrigatória

Inspecionar o código existente e identificar:

- Cadastro e edição de empresas no RootAdmin.
- Tabelas e serviços dos planos e assinaturas.
- Geração de cobranças recorrentes e vencimentos.
- Regras de inadimplência, tolerância, suspensão e reativação.
- Integração atual com Mercado Pago ou outros canais disponíveis.
- Emissão da NFS-e da ABrasil Sistemas aos tenants.
- Serviços de envio de faturas, SMTP e links fiscais.
- Jobs, scheduler e webhooks existentes.

Não presumir nomes de tabelas, métodos ou contratos. Reutilizar o desenho comprovado no repositório.

## 2. Modalidade de contratação

Adicionar ao cadastro administrativo da empresa:

**Checkbox: "Contrato personalizado — não utilizar planos tradicionais".**

Quando ativado:

- Ignorar o preço e a periodicidade dos planos tradicionais.
- Informar valor comercial contratado.
- Informar periodicidade mensal, semestral ou anual.
- Informar data de início e próximo vencimento.
- Informar vendedor ou responsável pela negociação.
- Permitir observações comerciais internas.
- Preservar regras existentes de autorização, módulos e funcionalidades.
- Manter integralmente o controle de assinatura e bloqueio do tenant.

O preço personalizado é o **valor total de cada ciclo**, não um valor mensal automaticamente multiplicado por 6 ou 12.

Não conceder funcionalidades indiscriminadamente pelo simples fato de a empresa possuir contrato personalizado.

## 3. Cobrança e ciclo de vida

Integrar a modalidade personalizada ao mecanismo atual de assinaturas.

Garantir:

- Uma cobrança por ciclo.
- Idempotência em jobs e webhooks.
- Registro de pagamento e estorno.
- Histórico de cobranças.
- Regras atuais de vencimento e tolerância.
- Bloqueio por inadimplência.
- Reativação mediante regularização.
- Nenhuma duplicação de cobrança ao converter plano tradicional em contrato personalizado.
- Preservação das cobranças anteriores.
- Alterações de valor aplicadas aos próximos ciclos, salvo operação explícita e auditada.

Na reversão para plano tradicional, exigir seleção de plano válido e confirmação administrativa, sem apagar histórico nem produzir cobranças sobrepostas.

## 4. Emissão fiscal do SaaS

Adicionar a configuração individual:

**"Emitir NFS-e para este cliente SaaS".**

Essa opção deve funcionar independentemente de o cliente utilizar contrato tradicional ou personalizado.

Usar exclusivamente o emitente fiscal da ABrasil Sistemas, mantido no RootAdmin, e a integração Spedy já implementada.

O tomador será a empresa contratante (tenant).

Não utilizar o certificado nem as credenciais fiscais próprias do tenant para emitir a nota da assinatura do SaaS.

A opção fiscal só poderá ser efetivada quando o emitente da ABrasil estiver apto e os dados obrigatórios do tomador estiverem corretos.

Respeitar competência, fato gerador e calendário fiscal aplicável. Não presumir que a emissão deve ocorrer apenas após o pagamento nem que todo vencimento exige automaticamente uma nova nota.

Reutilizar documentos fiscais existentes, reconciliação, status, idempotência e tratamento de rejeições.

## 5. Comunicação ao cliente

Enviar automaticamente a fatura conforme a programação de cobrança.

A fatura deverá conter:

- Nome da empresa contratante.
- Identificação da ABrasil Sistemas.
- Número ou referência do contrato.
- Competência.
- Valor contratado.
- Vencimento.
- Situação financeira.
- Meio ou instruções de pagamento disponível.

Quando a opção fiscal estiver habilitada e a NFS-e for autorizada:

- Incluir links assinados e temporários para PDF e XML.
- Se a nota ainda estiver processando, enviar primeiro a fatura e posteriormente a comunicação fiscal.
- Nunca enviar link de nota inexistente ou ainda não autorizada.
- Registrar tentativas, sucessos e falhas de entrega.
- Permitir reenvio administrativo sem emitir nota duplicada.

Quando a opção fiscal estiver desabilitada, enviar somente a fatura.

Utilizar a infraestrutura SMTP central da plataforma, quando disponível. A ausência dessa infraestrutura não deve provocar implementação de outro sistema de e-mails.

## 6. Interface administrativa

No RootAdmin → Empresas → Editar:

Criar seção "Condições comerciais e faturamento".

Exibir:

- Tipo de contratação: plano tradicional ou personalizado.
- Checkbox de contrato personalizado.
- Valor e periodicidade.
- Início, vencimento e responsável comercial.
- Situação da assinatura.
- Próxima cobrança.
- Emissão fiscal habilitada ou desabilitada.
- Histórico resumido de cobranças.
- Acesso às cobranças e às notas fiscais correspondentes.

Impedir que o tenant altere preço, tipo de contratação, vencimento contratual ou autorização fiscal da assinatura do SaaS.

## 7. Segurança e integridade

- Alterações comerciais exclusivas do RootAdmin.
- Validação de valores monetários decimais, positivos e dentro dos limites suportados.
- Armazenamento financeiro em DECIMAL, nunca float.
- Auditoria com usuário, horário, estado anterior e posterior.
- Isolamento entre tenants.
- Ausência de credenciais em logs e respostas públicas.
- Compatibilidade com assinaturas e planos existentes.
- Migration aditiva, com padrão de contrato tradicional.
- Não recalcular retroativamente cobranças quitadas.

## 8. Testes obrigatórios

Cobrir ao menos:

1. Contrato tradicional permanece inalterado.
2. Contrato personalizado mensal.
3. Contrato personalizado semestral.
4. Contrato personalizado anual.
5. Alteração de valor entre ciclos.
6. Vencimento e inadimplência.
7. Suspensão e reativação.
8. Cobrança repetida não gera duplicidade.
9. Pagamento integrado e manual, conforme canais existentes.
10. Estorno preserva auditoria.
11. Emissão fiscal desabilitada.
12. Emissão fiscal habilitada.
13. Emissão sem depender indevidamente do pagamento.
14. Fatura enviada antes da autorização fiscal.
15. PDF e XML disponibilizados após autorização.
16. Reenvio não duplica nota.
17. Falha da Spedy não elimina cobrança.
18. Falha SMTP não elimina nota.
19. Tenant não altera condições comerciais.
20. Retorno ao plano tradicional sem duplicação.
21. Isolamento completo entre tenants.

## 9. Entrega

Implementar localmente, executar testes, TypeScript, Pint, Prettier e build.

Registrar no `executed.md`:

- Arquitetura reaproveitada.
- Arquivos modificados.
- Migrations.
- Regras comerciais e fiscais.
- Resultados dos testes.
- Pendências.
- Plano de homologação.

Preservar trabalhos de outras frentes, especialmente SMTP universal e integração Spedy.

Não executar deploy, migrations em produção, force push ou alterações de credenciais sem instrução posterior.