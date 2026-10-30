# VETOROS-WHATSAPP-01 — Correção de telefones e travamento no envio

**Objetivo:** corrigir definitivamente o envio de WhatsApp nos cadastros de clientes e demais módulos do VetorOS.

## 1. Cadastro de clientes

- Padronizar os campos Telefone e WhatsApp.
- Aceitar números brasileiros com DDD, com ou sem máscara.
- Normalizar o número para o formato exigido pelo provedor, preservando o original para apresentação.
- Permitir que o telefone de contato seja diferente do WhatsApp.
- Não presumir que todo celular possui WhatsApp.
- Não alterar números já cadastrados sem validação.

## 2. Validação antes do envio

- Validar DDD, quantidade de dígitos e formato.
- Verificar se o número é compatível com o canal WhatsApp configurado.
- Quando o provedor oferecer consulta de existência, utilizá-la com tratamento de erros e timeout.
- Não bloquear indefinidamente o envio enquanto aguarda uma consulta externa.
- Diferenciar número inválido, número sem WhatsApp, sessão desconectada e falha temporária.

## 3. Corrigir o botão Enviar

- Apresentar estado de carregamento somente durante a operação.
- Implementar timeout para requisições ao backend e ao WAHA.
- Garantir recuperação do botão em todos os resultados, inclusive erros e exceções.
- Evitar envios duplicados por cliques repetidos.
- Exibir mensagens compreensíveis ao usuário.
- Não fechar o modal em caso de falha.
- Disponibilizar nova tentativa quando apropriado.

## 4. Integração WAHA

Revisar os endpoints utilizados para envio, validação e identificação dos destinatários.

Verificar o tratamento de respostas HTTP, erros de rede, sessão desconectada, números inexistentes e requisições demoradas.

Não considerar a mensagem entregue apenas porque a API aceitou a requisição.

Preservar o isolamento entre tenants e as configurações individuais de WhatsApp.

## 5. Mensagens ao usuário

Utilizar mensagens claras:

- **Número inválido:** "Confira o número de WhatsApp cadastrado para este cliente."
- **WhatsApp não localizado:** "Não foi possível confirmar uma conta WhatsApp neste número."
- **Sessão desconectada:** "O WhatsApp da empresa está desconectado."
- **Erro de conexão:** "Não foi possível enviar a mensagem. Tente novamente."
- **Envio aceito:** "Mensagem encaminhada ao WhatsApp. Acompanhe o status de entrega."

## 6. Aplicar em todo o VetorOS

Verificar os componentes compartilhados de envio e corrigir os módulos que os utilizam, incluindo clientes, orçamentos, ordens de serviço, cobranças e notificações.

Evitar implementar validações diferentes em cada tela.

## 7. Testes obrigatórios

Testar números válidos, inválidos, sem WhatsApp, com máscara, sem máscara, com código internacional, sessão desconectada, falha de rede, timeout e cliques repetidos.

Confirmar que o botão sempre retorna ao estado normal e que os erros são apresentados sem travar a interface.

Executar os testes automatizados e registrar as alterações no executed.md.

**Critério de conclusão:** nenhum número inválido, incompatível ou indisponível poderá deixar o botão Enviar permanentemente bloqueado.
