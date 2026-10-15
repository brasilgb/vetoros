# VETOR-FISCAL-01 — Integração automática Spedy e liberação de certificado A1

**Projeto:** VetorOS — ABrasil Sistemas  
**Prioridade:** Alta  
**Ambiente inicial:** Sandbox/Homologação  
**Execução:** Desenvolvimento local, testes e commits locais. Sem push ou deploy sem autorização.

## 1. Objetivo

Concluir o processo de ativação fiscal dos tenants do VetorOS por meio da API da Spedy, sem exigir que os clientes acessem a plataforma externa.

Existe atualmente uma empresa com dados cadastrais preenchidos e emissão fiscal habilitada pelo rootAdmin, porém a interface informa "Empresa emissora: Pendente" e impede o envio do certificado A1.

Investigar e corrigir a causa real antes de liberar os campos.

## 2. Auditoria inicial obrigatória

Examinar a implementação fiscal existente para identificar:

- Configurações e credenciais da Spedy.
- Cliente HTTP e endpoints utilizados.
- Processo de cadastro da empresa emissora.
- Persistência do identificador da empresa na Spedy.
- Relação entre empresa, tenant e ambiente fiscal.
- Regras que bloqueiam o envio do certificado.
- Flags de autorização de NF-e, NFC-e e NFS-e.
- Possíveis divergências entre rootAdmin e interface do tenant.

Consultar a documentação atual em:

https://docs.spedy.com.br/llms.txt

Utilizar somente endpoints, campos e formatos confirmados pela documentação oficial.

**Não criar uma segunda integração caso os serviços necessários já existam. Corrigir e reutilizar a implementação atual.**

## 3. Cadastro automático na Spedy

Implementar ou concluir uma operação centralizada que permita:

1. Validar os dados cadastrais e fiscais da empresa.
2. Verificar se já existe vínculo com uma empresa emissora.
3. Consultar a Spedy quando houver suporte ao recurso.
4. Cadastrar o CNPJ pelo endpoint oficial adequado.
5. Registrar o identificador retornado.
6. Armazenar o ambiente associado ao vínculo.
7. Atualizar o status da empresa emissora.
8. Retornar mensagens compreensíveis em caso de falha.

Impedir duplicações e preservar os vínculos existentes.

O processo deve ser idempotente e seguro contra execuções simultâneas.

## 4. rootAdmin — Gerenciamento fiscal

Na administração da empresa, manter o controle de habilitação fiscal e acrescentar ou corrigir os seguintes recursos:

- Status da integração com a Spedy.
- Botão "Cadastrar na Spedy", quando ainda não cadastrada.
- Botão "Sincronizar", quando já cadastrada.
- Identificador da empresa emissora.
- Ambiente de operação.
- Status do certificado digital.
- Permissões individuais por tipo de nota.
- Última sincronização e último erro.

O administrador deve visualizar claramente a diferença entre "fiscal habilitado" e "empresa pronta para emitir".

A habilitação fiscal não deve ser confundida com autorização de emissão em produção.

## 5. Cliente — Certificado digital A1

Depois de confirmado o cadastro da empresa na Spedy:

- Liberar o campo de seleção do certificado.
- Permitir arquivos .pfx e .p12, se aceitos pela API.
- Solicitar a senha somente durante o envio.
- Enviar arquivo e senha no formato exigido pela API.
- Confirmar o resultado retornado pela Spedy.
- Atualizar o estado do certificado.
- Apresentar a validade quando fornecida pela integração.
- Permitir atualização de certificado vencido ou substituído.

Nunca registrar a senha em logs, banco de dados, filas persistentes ou mensagens de erro.

Não expor credenciais nem permitir acesso de outro tenant ao certificado.

## 6. Configuração fiscal

Reutilizar as telas fiscais existentes e verificar a necessidade de:

- Inscrição Estadual.
- Inscrição Municipal.
- Regime tributário/CRT.
- Série e numeração de documentos.
- Código de serviço e parâmetros municipais.
- CFOP e outras informações fiscais pertinentes.

Exigir somente os campos aplicáveis a cada modalidade de emissão.

Não preencher informações tributárias fictícias para fazer a validação passar.

## 7. Controle por documento

Preservar habilitações independentes:

- NF-e.
- NFC-e.
- NFS-e.

Cada modalidade deve apresentar seu estado real: não contratada, não habilitada, configuração pendente, pronta para homologação ou apta à emissão no ambiente correspondente.

A ativação de uma modalidade não pode liberar automaticamente as demais sem autorização.

## 8. Sandbox e produção

Manter configurações e identificadores separados por ambiente.

- Nunca utilizar a chave de produção em chamadas de homologação.
- Não transportar automaticamente certificados ou vínculos entre ambientes.
- Não emitir documentos fiscais reais durante os testes.
- Bloquear alterações em produção sem autorização.
- Não interpretar um teste bem-sucedido no sandbox como habilitação automática de produção.

Se a API ou o contrato Spedy não permitir determinada operação, apresentar o impedimento real e documentar a providência necessária.

## 9. Tratamento de erros

Implementar mensagens claras para:

- API indisponível.
- Chave de API inválida.
- Permissão ou plano insuficiente.
- CNPJ já cadastrado.
- Dados cadastrais incompletos.
- Certificado inválido.
- Senha do certificado incorreta.
- Empresa emissora não encontrada.
- Divergência entre ambiente e identificador.
- Falha de sincronização.

Preservar códigos técnicos e identificadores de correlação em logs seguros, sem dados sensíveis.

## 10. Testes e homologação

Criar ou atualizar testes automatizados para:

- Cadastro de empresa emissora.
- Sincronização de cadastro existente.
- Repetição da operação sem duplicação.
- Falhas de API e timeout.
- Controle de acesso do rootAdmin.
- Isolamento entre tenants.
- Liberação condicional do certificado.
- Upload do certificado.
- Separação de ambientes.
- Bloqueio de emissão antes da configuração necessária.

Validar também a interface do cliente que atualmente apresenta os campos desabilitados.

## 11. Critérios de aceite

A entrega estará concluída quando:

1. O rootAdmin conseguir cadastrar/sincronizar uma empresa na Spedy no sandbox autorizado.
2. O identificador for associado corretamente à empresa do tenant.
3. O painel fiscal deixar de apresentar "Pendente" quando houver confirmação real do cadastro.
4. O campo do certificado A1 ficar disponível quando seus pré-requisitos forem atendidos.
5. O cliente conseguir enviar o certificado com segurança.
6. O status do certificado for atualizado de acordo com a resposta da Spedy.
7. A habilitação de NF-e, NFC-e e NFS-e respeitar os requisitos específicos.
8. Os testes automatizados forem aprovados.

Não marcar etapas como concluídas apenas porque uma flag local foi alterada.

## 12. Entrega

Apresentar um relatório com:

- Causa raiz do bloqueio atual.
- Componentes e arquivos modificados.
- Configurações necessárias na Spedy.
- Endpoints realmente utilizados.
- Resultados dos testes.
- Pendências externas, caso existam.
- Passo a passo de homologação pelo rootAdmin.

Realizar commits locais das alterações validadas, sem push ou deploy.

**Priorizar a solução operacional, reaproveitando a arquitetura fiscal atual do VetorOS e evitando refatorações desnecessárias.**