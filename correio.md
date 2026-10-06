# CRM-WA-30.1 — Continuar Fase 4C com tenant controlado

O usuário confirmou explicitamente:

tenant_id = 1

Esse tenant pertence ao próprio usuário e está autorizado para o teste.

Prosseguir SOMENTE com as Fases 4C e 4D.

## 4C — Cadastro existente

1. Ler internamente o tenant ID 1.

2. Preferir o WhatsApp como identificador, porque será o identificador
   configurado posteriormente no produto VetorOs do CRM.

3. Normalizar o WhatsApp exatamente como esperado pelo contrato.

4. NÃO exibir nem registrar:
   - WhatsApp;
   - telefone;
   - e-mail;
   - CNPJ;
   - token.

5. Antes da chamada, obter somente:
   - tenant_id;
   - created_at.

6. Executar UMA chamada real:

POST https://vetoros.com.br/api/integrations/registration-check

Authorization: Bearer <token configurado>

{
  "lookup_field": "whatsapp",
  "lookup_value": "<whatsapp-normalizado-do-tenant-1>"
}

## Resultado esperado

HTTP 200

{
  "registered": true,
  "registered_at": "<data>"
}

Validar:

- registered === true boolean;
- registered_at presente;
- ISO-8601 com timezone;
- registered_at representa exatamente tenants.created_at,
  considerando conversão/formatação de timezone;
- nenhuma informação adicional é retornada;
- nenhuma escrita ocorre no banco.

## 4D — Contrato

Confirmar explicitamente:

- positivo: 200 + registered=true + registered_at;
- negativo já validado: 200 + registered=false;
- autenticação já validada: 401;
- validação já validada: 422;
- resposta positiva não contém dados pessoais;
- endpoint permanece somente leitura.

Se qualquer validação falhar:

PARAR.

NÃO configurar o CRM.

## Se 4C e 4D passarem

Atualizar executed.md informando apenas:

- tenant controlado: ID 1;
- registered=true;
- registered_at retornado;
- tenants.created_at;
- confirmação de equivalência;
- HTTP status;
- confirmação de nenhuma escrita;
- Fase 4 aprovada.

Não registrar o identificador utilizado.

Depois disso, pode avançar para a FASE 5:

Configurar o produto VetorOs no CRM:

Acompanhamento de cadastro = API
URL = https://vetoros.com.br/api/integrations/registration-check
Identificador = WhatsApp
Token = mesmo segredo do VetorOS

Antes de salvar:
- confirmar HTTPS;
- porta 443;
- DNS/IP público;
- validação SSRF aprovada.

Depois de salvar confirmar:

PROSPECT_REGISTRATION_CHECK_ENABLED=false

e que a consulta automática NÃO aparece em schedule:list.

IMPORTANTE:
NÃO executar ainda o TESTE B que inicia trial em um lead.

Após configurar o produto, procurar um lead controlado compatível com
tenant 1, mas NÃO executar "Verificar cadastro agora".

Apresentar somente:

- lead_id;
- status;
- prospect_product_id;
- trial_started_at;
- trial_ends_at;
- registration_check_status;
- registration_checked_at;
- quantidade de activities;
- se o WhatsApp do lead corresponde ao tenant 1: sim/não;
- registered_at que a API retornou;
- trial_ends_at que seria calculado pelo CRM.

NÃO mostrar telefone/e-mail.

PARAR nesse ponto e aguardar autorização.
