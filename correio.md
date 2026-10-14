# VETOR-FISCAL-06 — Autogestão do certificado digital A1 pelo tenant

Auditar e aprimorar a tela de Emissão fiscal do VetorOS.

**Problema:** a tela informa "Certificado digital A1 — Não enviado", mas não apresenta um campo para o administrador da empresa cadastrar seu certificado.

## Implementação

1. Auditar o fluxo existente de certificados digitais na integração Spedy e no RootAdmin.
2. Identificar os endpoints disponíveis para cadastro, consulta, substituição e situação do certificado.
3. Reutilizar a integração existente, sem criar um segundo gerenciamento de certificados.
4. Adicionar, na área **Configurações → Sistema e módulos → Emissão fiscal**, uma interface de upload do certificado digital A1.
5. Aceitar arquivos `.pfx` e `.p12`, respeitando os requisitos e limites da API.
6. Solicitar a senha do certificado exclusivamente durante a transmissão.
7. Nunca persistir a senha em texto puro, logs, sessão ou histórico.
8. Garantir transmissão segura, autorização administrativa e isolamento entre tenants.
9. Validar o certificado com a Spedy antes de apresentar sucesso.
10. Após a confirmação, apresentar situação, identificação e validade, caso esses dados sejam fornecidos pelo provedor.
11. Permitir substituição segura do certificado, mediante confirmação.
12. Não permitir que o tenant modifique credenciais ou habilitações fiscais controladas pelo RootAdmin.
13. Preservar o gerenciamento central do RootAdmin para suporte administrativo.
14. Exibir instruções claras sobre certificado A1, extensão aceita e senha.
15. Tratar mensagens de erro sem expor detalhes sensíveis.

## Critérios de aceite

- Administrador do tenant consegue enviar o certificado A1 da própria empresa quando autorizado.
- Certificado vinculado à empresa correta na Spedy.
- RootAdmin continua controlando a habilitação fiscal.
- Nenhum tenant pode acessar certificado de outra empresa.
- Não há armazenamento inseguro de arquivo ou senha.
- A tela mostra corretamente o status retornado pelo provedor.
- Testes automatizados validam permissões, upload, substituição, falhas e isolamento.

Executar auditoria, implementação, testes e commit local.

**Sem push, deploy ou migrations em produção.**

Registrar em `executed.md` o que já existia, o que foi implementado e eventuais limitações reais da API Spedy.
