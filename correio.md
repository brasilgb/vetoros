# VETOR-STABILIZE-03 — Estabilidade, SMTP e acesso administrativo aos tenants

**Projeto:** VetorOS  
**Situação:** Autorizada implementação local, testes, commit e push.  
**Produção:** Sem deploy e sem migrations em produção nesta etapa.

## 1. Estabilidade dos dashboards

Auditar as abas Financeiro, Compras e Agendamentos, aplicando as melhorias da STABILIZE-02.

- Validar respostas das APIs antes de processar os dados.
- Tratar corretamente respostas vazias, incompletas e inválidas.
- Exibir estados de carregamento, ausência de dados e erro.
- Disponibilizar botão "Tentar novamente".
- Não apresentar informações financeiras fictícias.
- Impedir erros fatais de JavaScript.

Preservar os gráficos, indicadores e regras existentes.

## 2. Configuração SMTP da plataforma

Revisar a configuração global de envio de e-mails no ecossistema `infra-abrasil`.

Configuração institucional:

- E-mail: `contato@abrasilsistemas.com.br`
- Servidor: `smtp.hostinger.com`
- Porta: `465`
- Segurança: SSL/TLS
- Senha: variável de ambiente protegida, nunca versionada.

Garantir que o Laravel utilize as variáveis corretas e que o Docker Compose não dependa de `.env.example` como fonte de credenciais de produção.

**Separação obrigatória:**

- RootAdmin e notificações da plataforma utilizam o SMTP institucional.
- Cada tenant continua utilizando sua própria configuração SMTP.
- Não substituir configurações particulares das empresas.

Validar avisos de cobrança, vencimentos, assinatura e documentos fiscais.

## 3. NOVO — Acesso administrativo ao painel dos tenants

### Objetivo

Permitir que o RootAdmin entre diretamente no painel de qualquer empresa cadastrada para realizar suporte, configurações, correções e verificações operacionais, sem utilizar a senha do cliente.

### 3.1. Acesso pela administração

Na página:

**RootAdmin → Empresas**

Adicionar à tabela de empresas a ação:

**"Acessar painel"**

A ação deverá estar disponível exclusivamente para usuários RootAdmin autorizados.

Ao clicar, abrir um modal com:

- Nome da empresa.
- CNPJ, quando disponível.
- Situação da assinatura.
- Identificação do ambiente.
- Motivo obrigatório do acesso.
- Botão "Entrar no painel".

### 3.2. Modo Suporte

Implementar autenticação administrativa temporária, utilizando a arquitetura existente de sessões e permissões do Laravel.

Requisitos:

- Não solicitar nem modificar a senha do tenant.
- Não alterar o usuário administrador original da empresa.
- Manter a identidade real do RootAdmin responsável pelo acesso.
- Limitar rigorosamente a sessão à empresa selecionada.
- Impedir acesso cruzado entre tenants.
- Não permitir que parâmetros de URL sejam utilizados para trocar a empresa.
- Impedir sessões de suporte encadeadas.
- Exigir reautenticação recente antes de iniciar o acesso.
- Estabelecer expiração e encerramento seguros.

### 3.3. Painel operacional

Ao entrar, o RootAdmin deverá visualizar o painel da empresa e acessar as funcionalidades administrativas permitidas ao suporte.

O objetivo é permitir, por exemplo:

- Revisar configurações da empresa.
- Verificar módulos e funcionalidades.
- Conferir cadastros e parâmetros operacionais.
- Investigar problemas em ordens de serviço.
- Verificar configurações fiscais e o estado das integrações.
- Conferir configurações de notificações.
- Identificar erros relatados pelos clientes.
- Realizar correções administrativas autorizadas.

Não conceder automaticamente permissão para executar operações financeiras, fiscais, destrutivas ou alterações sensíveis de segurança.

Essas operações deverão permanecer bloqueadas por padrão no Modo Suporte, salvo se existir autorização específica, segura e auditada.

### 3.4. Identificação visual

Durante o acesso, exibir uma faixa discreta e permanente no topo:

**Modo Suporte — Empresa: [Nome da empresa]**

Incluir:

- Identificação da empresa.
- Indicação de acesso administrativo.
- Botão "Encerrar suporte".

O botão deverá invalidar a sessão temporária e retornar ao RootAdmin, preferencialmente à listagem de empresas.

A identidade visual deve seguir o tema atual do VetorOS, inclusive no modo escuro.

### 3.5. Registro e auditoria

Registrar:

- RootAdmin responsável.
- Empresa acessada.
- Motivo informado.
- Data e horário de início.
- Data e horário do encerramento.
- IP de origem.
- Ações administrativas executadas.
- Alterações realizadas, identificando o administrador real.

Os registros devem respeitar as políticas de retenção e segurança, sem gravar senhas, tokens ou outros segredos.

### 3.6. Segurança e isolamento

Antes da implementação, auditar:

- Autenticação atual do Laravel.
- Middlewares.
- Policies e roles.
- Sessões.
- Resolução de tenant.
- Permissões administrativas.
- Rotas financeiras e fiscais.

Reutilizar os mecanismos existentes, sem criar autenticação paralela desnecessária.

Não permitir que o RootAdmin fique identificado nos registros como se fosse um funcionário comum da empresa.

## 4. Testes obrigatórios

### Dashboards

Validar as respostas das APIs e os componentes no Chromium e Firefox.

### SMTP

Validar envio institucional e preservação do SMTP individual dos tenants.

### Modo Suporte

Cobrir:

1. RootAdmin entra no tenant autorizado.
2. Usuário comum não consegue utilizar a funcionalidade.
3. A sessão permanece limitada ao tenant escolhido.
4. A tentativa de acessar outro tenant é recusada.
5. Encerramento retorna ao RootAdmin.
6. Sessão expirada perde acesso.
7. Logs identificam corretamente o administrador.
8. Operações críticas ficam protegidas.
9. Logout encerra o acesso temporário.
10. Fluxos Efí, Spedy e assinatura não sofrem regressões.

Executar testes PHP, JavaScript, TypeScript, build e verificações de segurança.

## 5. Publicação e infraestrutura

Após a implementação:

- Revisar `git status`.
- Conferir alterações de outras frentes.
- Executar verificações de segredos.
- Realizar commit e push na `main`, após testes aprovados.
- Confirmar CI verde.
- Registrar o resultado no `executed.md`.
- Atualizar o procedimento de deploy da VPS.

Considerar todas as migrations acumuladas, inclusive as eventualmente necessárias para registrar sessões e auditoria de suporte.

Não executar migrations em produção, reiniciar containers ou realizar deploy sem autorização.

## 6. Entrega esperada

A VETOR-STABILIZE-03 deverá entregar:

- Dashboards resistentes a respostas inválidas.
- SMTP institucional preparado.
- SMTP individual dos tenants preservado.
- Acesso direto e seguro do RootAdmin aos painéis das empresas.
- Modo Suporte identificado visualmente.
- Auditoria e encerramento seguro da sessão.
- Testes aprovados e código publicado.
- Plano de implantação atualizado.

**Prioridade:** segurança, simplicidade operacional e preservação das regras existentes do VetorOS.