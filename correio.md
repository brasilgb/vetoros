# VETOR-CHAT-10.1 — Central omnichannel VetorLeads

**Objetivo:** consolidar o atendimento WebChat e WhatsApp em uma única interface operacional, utilizando os recursos existentes e preparando a integração com n8n e OpenAI.

## 1. Central unificada

Implementar no VetorLeads uma central acessível pelo menu "Atendimento", contendo:

- Caixa de entrada unificada para WhatsApp e WebChat.
- Filtros por canal, situação, responsável e mensagens não lidas.
- Lista de contatos com nome, origem e última mensagem.
- Histórico cronológico por conversa e identificação do canal.
- Área de resposta humana.
- Associação a leads e oportunidades.
- Atribuição de responsável pelo atendimento.
- Alteração de situação: pendente, em andamento e encerrada.
- Atualização de novas mensagens sem recarregar a página.

Priorizar interface responsiva com três áreas no desktop: conversas, mensagens e informações do contato. No mobile, usar navegação adaptada.

## 2. Reutilização

Reaproveitar APIs, models, serviços e estruturas de WebChat e WhatsApp existentes.

Evitar duplicar histórico, mensagens, contatos ou regras de negócio.

Se for necessário criar uma camada unificada, utilizar serviços e DTOs que normalizem os canais sem perder a identificação original das mensagens.

Manter `/web-chat` e as funcionalidades atuais compatíveis durante a transição.

## 3. WhatsApp

- Manter isolamento de sessão WAHA por tenant.
- Receber novas mensagens na central.
- Responder diretamente pela central usando a sessão autorizada.
- Exibir mensagens enviadas, recebidas e seus estados.
- Preservar deduplicação e validação HMAC existentes.
- Tratar desconexões sem perder mensagens.
- Impedir atribuição incorreta de mensagens quando contatos forem ambíguos.

## 4. WebChat do VetorOS

- Mostrar conversas do widget público na central.
- Permitir que o atendente responda diretamente.
- Entregar a resposta ao visitante pelo mecanismo existente.
- Preservar identificação do tenant de atendimento.
- Respeitar horários, disponibilidade e configurações já existentes.

Não converter automaticamente WebChat em WhatsApp. Cada canal deverá funcionar independentemente.

## 5. Integração n8n

Auditar os workflows existentes antes de criar novos.

Preparar eventos internos para:

- Nova conversa.
- Nova mensagem recebida.
- Lead identificado.
- Atendimento atribuído.
- Atendimento encerrado.
- Follow-up agendado.

Entregar eventos ao n8n com autenticação, identificadores de tenant e conversa, idempotência e política de repetição.

A operação humana básica deve continuar funcionando se o n8n estiver indisponível.

Não enviar segredos, tokens de visitante ou dados desnecessários aos workflows.

## 6. Preparação para IA

Criar apenas a base de integração futura com OpenAI, sem consumo real nesta etapa:

- Configuração administrativa para habilitar IA por tenant.
- Estrutura para limites de consumo.
- Identificação de mensagens humanas e automatizadas.
- Mecanismo para transferência ao atendente humano.
- Pontos de integração com workflows n8n.

Não inventar respostas automáticas nem ativar agentes nesta fase.

## 7. Testes

Comprovar:

1. Recebimento WebChat na central.
2. Resposta do atendente chegando ao widget.
3. Recebimento WhatsApp na central.
4. Resposta enviada pela sessão WAHA correspondente.
5. Isolamento entre tenants.
6. Mensagens duplicadas não gerando registros repetidos.
7. Conversas encerradas e atribuições.
8. Falha do n8n sem impedir atendimento humano.
9. Segurança e autorização dos endpoints.

Executar testes automatizados e homologação visual quando possível.

## 8. Entrega

Não encerrar a tarefa somente com auditoria.

Implementar os módulos necessários e registrar em `executed.md`:

- Arquivos alterados.
- Funcionalidades implementadas.
- Testes aprovados.
- Fluxos E2E efetivamente executados.
- Limitações de infraestrutura.
- Pendências específicas.

Não executar deploy em produção sem autorização.