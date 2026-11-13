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
