# Deploy na HostGator Turbo

Este guia assume o cenário já informado:

- HostGator Linux compartilhado
- aplicação já publicada
- HTTPS ativo
- banco MySQL ativo
- acesso por cPanel/SSH

O objetivo aqui é padronizar deploys seguros para o VetorOS nesse ambiente.

## 1. Premissas

Antes de cada deploy, confirme:

- o repositório está atualizado
- a CI está verde
- `php artisan test` passou localmente
- `npm run types` passou localmente
- o build do frontend foi gerado sem erro

## 2. Estrutura recomendada

Idealmente:

- código Laravel fora do diretório público
- somente o conteúdo de `public/` exposto na web

Exemplo:

```text
/home/USUARIO/saasos
/home/USUARIO/public_html
```

Se sua aplicação já está funcionando com outra estrutura, não troque isso sem testar antes em ambiente seguro.

## 3. Variáveis de ambiente

No `.env` de produção, garanta pelo menos:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://seudominio.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database
CACHE_STORE=file
SESSION_DRIVER=file

# Gateway de pagamento (Efí Bank): configurado no RootAdmin, sem variável de ambiente.
```

Observações:

- em shared hosting, `file` costuma ser mais previsível para cache e sessão
- use `database` na fila se você realmente for consumir jobs com cron ou outra estratégia controlada

## 4. Fluxo recomendado de deploy

Conecte via SSH e rode os comandos na pasta do projeto.

### 4.1 Entrar no projeto

```bash
cd /home/USUARIO/saasos
```

### 4.2 Ativar manutenção

```bash
php artisan down
```

Se o seu fluxo comercial não permitir indisponibilidade breve, faça o deploy em janela controlada.

### 4.3 Atualizar código

Se você usa `git` no servidor:

```bash
git pull origin main
```

Se você publica por upload, envie os arquivos antes de continuar.

### 4.4 Instalar dependências PHP

```bash
composer install --no-dev --optimize-autoloader --no-interaction
```

### 4.5 Instalar dependências Node

Se o ambiente permitir `npm` normalmente:

```bash
npm ci
npm run build
```

Se o Node da hospedagem for limitado, o caminho mais seguro é gerar o build localmente e subir `public/build`.

### 4.6 Rodar migrations

```bash
php artisan migrate --force
```

### 4.7 Limpar e recriar caches

```bash
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

### 4.8 Retirar manutenção

```bash
php artisan up
```

## 5. Cron do Laravel

No cPanel ou Customer Portal, configure:

```bash
* * * * * cd /home/USUARIO/saasos && php artisan schedule:run >> /dev/null 2>&1
```

Esse cron é obrigatório para:

- follow-up de cobrança
- follow-up de orçamento
- notificações de assinatura

## 6. Fila em hospedagem compartilhada

Em HostGator compartilhado, worker persistente nem sempre é a melhor aposta.

Se você depender de jobs assíncronos, há 3 caminhos práticos:

1. manter `QUEUE_CONNECTION=sync`
2. usar `database` e consumir com cron
3. migrar a parte crítica para VPS

Para início de operação comercial pequena, `sync` pode ser mais previsível do que uma fila mal processada.

Se optar por consumir fila via cron, use algo como:

```bash
* * * * * cd /home/USUARIO/saasos && php artisan queue:work --stop-when-empty --tries=1 >> /dev/null 2>&1
```

Só use isso se você realmente souber que os jobs foram desenhados para esse comportamento.

## 7. Webhooks Efí Bank

Valide sempre após o deploy, em RootAdmin → Financeiro · Efí Bank:

- a URL pública continua em HTTPS
- o teste de conexão passa nas APIs Cobranças e Pix
- o webhook Pix está cadastrado (botão "Cadastrar webhook Pix")
- as notificações chegam (última notificação Pix e Cobranças)

Teste ponta a ponta:

- gerar Pix, boleto e cartão
- pagar
- receber o webhook e a confirmação por consulta à API
- atualizar o tenant
- gravar o pagamento

## 8. Verificação rápida pós-deploy

Após publicar:

- abrir a home
- fazer login
- abrir dashboard
- abrir tenant admin
- abrir uma OS
- testar portal público por `tracking_token`
- testar geração de PIX
- validar logs

## 9. Checklist de rollback

Se algo falhar:

- restaurar backup do banco se migration quebrou dados
- voltar código para a versão anterior
- limpar caches:

```bash
php artisan optimize:clear
```

- reativar aplicação:

```bash
php artisan up
```

## 10. Recomendações honestas

Para poucos clientes pagantes, HostGator Turbo pode sustentar a operação.

Mas considere migração para VPS quando aparecer qualquer um destes sinais:

- lentidão em horário comercial
- falhas em jobs ou cron
- webhook com atraso
- deploys demorados ou instáveis
- aumento de tenants e ordens simultâneas

Nesse momento, VPS deixa de ser luxo e passa a ser controle operacional.
