# Checklist de Produção

Este checklist foi pensado para o momento de publicar o VetorOS com segurança em ambiente real.

## 1. Pré-deploy

- Confirmar que a branch de release está com a CI verde.
- Validar localmente:
  - `php artisan test`
  - `npm run types`
- Revisar `.env.example` e garantir que nenhuma credencial real está versionada.
- Confirmar que rotas de desenvolvimento, preview e debug não estão expostas.

## 2. Ambiente

- Configurar variáveis obrigatórias no servidor:
  - `APP_ENV=production`
  - `APP_DEBUG=false`
  - `APP_URL=https://seu-dominio`
  - `APP_KEY` exclusiva do ambiente
  - credenciais reais de banco e e-mail (as credenciais da Efí Bank ficam no RootAdmin, criptografadas no banco)
- Garantir permissões de escrita em:
  - `storage/`
  - `bootstrap/cache/`
- Garantir HTTPS funcional no domínio principal e no endpoint público do webhook.

## 3. Banco de dados

- Executar backup antes de qualquer deploy.
- Rodar `php artisan migrate --force`.
- Validar restore em ambiente separado pelo menos uma vez antes do go-live comercial.

## 4. Build e otimização

- Instalar dependências com lockfile:
  - `composer install --no-dev --optimize-autoloader`
  - `npm ci`
- Gerar assets:
  - `npm run build`
- Otimizar Laravel:
  - `php artisan config:cache`
  - `php artisan route:cache`
  - `php artisan view:cache`

## 5. Filas e agendamentos

- Garantir worker de fila ativo para a conexão configurada em produção.
- Monitorar `failed_jobs`.
- Configurar cron a cada minuto:

```bash
* * * * * cd /caminho/do/projeto && php artisan schedule:run >> /dev/null 2>&1
```

- Confirmar execução dos comandos agendados:
  - `vetoros:send-payment-followups`
  - `vetoros:send-budget-followups`
  - `vetoros:send-subscription-status-notifications`

## 6. Billing e assinatura

- Gateway único: **Efí Bank** (Pix, boleto/Bolix, cartão e recorrência no cartão). Não há variável de ambiente de gateway.
- Em RootAdmin → Financeiro · Efí Bank:
  - ambiente, credenciais das APIs Cobranças e Pix, certificado da API Pix, chave Pix e identificador de conta
  - testar a conexão e cadastrar o webhook Pix
  - homologar (sem homologação, novas cobranças externas ficam bloqueadas)
- Garantir o worker de fila e o scheduler (`vetoros:reconcile-saas-payments` a cada 30 min e `vetoros:process-saas-contracts`).
- Validar ponta a ponta:
  - Pix, boleto e cartão
  - recebimento dos webhooks Pix e Cobranças (`/api/webhooks/efi/...` e `/api/webhooks/efi-charges/...`)
  - gravação do pagamento e renovação do tenant
  - bloqueio por expiração e reativação após pagamento
- Antes da primeira implantação sem o gateway anterior: `php artisan vetoros:legacy-payments-report` deve terminar sem pendente válido.

## 7. E-mail

- Validar SMTP global e SMTP por tenant.
- Testar:
  - recuperação de senha
  - envio de e-mail de teste nas configurações
  - follow-up de orçamento
  - follow-up de cobrança
  - notificações de assinatura

## 8. Fluxos críticos

- Login e logout
- Cadastro e edição de tenant
- Cadastro de cliente
- Abertura, atualização e entrega de OS
- Portal público por `tracking_token`
- Registro de pagamento da OS
- Caixa diário
- Venda e cancelamento de venda
- Relatórios principais

## 9. Observabilidade

- Definir estratégia de logs e retenção.
- Monitorar pelo menos:
  - exceções de aplicação
  - jobs com falha
  - erros de webhook
  - falhas de envio de e-mail
  - falhas na geração de PIX

## 10. Segurança

- Rotacionar toda credencial que já tenha sido usada em ambiente inseguro.
- Revisar usuários admin iniciais.
- Garantir menor privilégio possível no banco e nos serviços externos.
- Validar rate limit e autenticação dos endpoints de API usados externamente.

## 11. Pós-deploy

- Abrir o sistema e validar a home.
- Conferir build do frontend carregando sem erro.
- Executar uma bateria curta manual:
  - login
  - dashboard
  - criação de OS
  - pagamento
  - portal público
  - PIX
- Acompanhar logs por pelo menos 15 a 30 minutos após o deploy.

## Go-live mínimo

Antes de chamar de publicado com segurança:

- CI verde
- testes backend verdes
- TypeScript verde
- webhook validado
- cron validado
- fila validada
- backup validado
- SMTP validado
