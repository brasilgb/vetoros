# VETOROS-PERFORMANCE-OS-04 — Remoção da consulta pública de OS

**Objetivo:** simplificar o VetorOS, eliminando a estrutura de acesso público às Ordens de Serviço e o processamento associado, sem comprometer as funcionalidades administrativas.

**Diretório:** projeto VetorOS existente.

### 1. Auditoria inicial

- Identificar todas as rotas públicas de acompanhamento de OS.
- Localizar chaves, hashes bcrypt, HMAC, middlewares, controllers, serviços e templates relacionados.
- Verificar dependências em WhatsApp, e-mail, orçamento, contratos e área do cliente.
- Identificar links públicos eventualmente utilizados.
- Verificar o estado das alterações da PERFORMANCE-OS-03, que ainda não receberam commit ou deploy.

### 2. Remoção funcional

- Desativar a consulta pública de OS.
- Remover geração e validação de chaves públicas de OS.
- Remover processamento bcrypt/HMAC exclusivo dessa funcionalidade.
- Remover botões, menus e links de acompanhamento público.
- Ajustar os templates que utilizam `{{ link_os }}`, preservando as demais variáveis.
- Não remover autenticação de usuários nem outras proteções do sistema.

### 3. Banco de dados

- Identificar colunas e índices exclusivos da consulta pública.
- Planejar a remoção por migration posterior, somente após confirmar que não existem dependências.
- Não eliminar dados de OS, clientes, orçamentos ou histórico.
- Não executar imediatamente o backfill de chaves previsto na OS-03.

### 4. Preservação operacional

Garantir que continuem funcionando:

- Criação, edição e listagem de OS.
- Alterações de status.
- Orçamentos e aprovações.
- Mensagens manuais por WhatsApp e e-mail.
- Histórico e auditoria.
- Operações multi-tenant.
- Integrações existentes.

### 5. Testes e desempenho

- Atualizar testes relacionados à consulta pública.
- Executar a suíte completa.
- Medir novamente listagem, abertura e criação de OS.
- Verificar se a remoção do bcrypt da criação reduz a latência.
- Confirmar inexistência de rotas públicas que exponham dados de clientes.

### 6. Entrega

Registrar diagnóstico, alterações, testes, impacto no banco e plano de migração em `docs/VETOROS-PERFORMANCE-OS-04.md`.

Atualizar `executed.md` com o resultado.

**Não executar commit, push, deploy ou alterações em produção sem autorização explícita.**
# VETOROS-NFSE-09.4 — Finalização operacional

Continuar a VETOROS-NFSE-09.3, preservando todas as alterações realizadas.

**Objetivo:** colocar em funcionamento o diagnóstico da numeração e resolver a origem do erro E1235.

### Execução

1. Revisar e testar as alterações não commitadas da VETOROS-NFSE-09.3.
2. Verificar se a Spedy permite consultar o contador DPS atual pela API, sem alterar configurações.
3. Utilizar as credenciais já existentes no backend, sem expô-las em logs.
4. Se a consulta não existir ou exigir outro tipo de autenticação, registrar a limitação claramente. Não inventar endpoints.
5. Identificar por que a Spedy está utilizando `nDPS = 0` apesar da configuração local.
6. Corrigir a sincronização que provoca o problema, caso a causa seja comprovada.
7. Garantir que informações antigas do contador não sejam apresentadas como situação atual.
8. Revisar também o erro E0617 do Simples Nacional, independentemente da numeração.
9. Validar a interface do rootAdmin no navegador.
10. Executar testes completos, commit e push seguro das alterações.
11. Preparar backup, migration e deploy na VPS, respeitando a configuração existente.
12. Registrar tudo no `executed.md`, substituindo integralmente o conteúdo anterior.

### Restrições

Não alterar a numeração fiscal da empresa nem realizar emissões reais sem autorização específica.

Não considerar o erro resolvido até confirmar a causa e validar a correção.

**Importante:** a configuração fiscal deve ser administrada pelo VetorOS durante a operação normal, sem exigir ajustes manuais a cada nota.
