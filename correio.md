# VETOROS-EFI-RENEWAL-02.2 — Publicação e homologação da renovação

**Objetivo:** concluir a publicação e homologação do fluxo de renovação Efí implementado na etapa 02.1, preservando integralmente as funcionalidades existentes e os dados operacionais.

## 1. Recuperação do Git

- Auditar o checkout original e o clone temporário `/tmp/vetoros-renewal-publish-20261027`.
- Identificar todas as alterações da etapa 02.1.
- Resolver as permissões de escrita do Git sem comprometer o histórico.
- Verificar o acesso SSH ao GitHub e a chave autorizada.
- Criar commit com os arquivos efetivamente validados.
- Sincronizar com `origin/main`, preservando alterações remotas.
- Efetuar push sem `force`, salvo justificativa e autorização expressa.
- Registrar o SHA publicado.

## 2. Preparação e deploy

- Confirmar acesso autorizado à VPS.
- Verificar backup válido do banco.
- Confirmar que a migration de disponibilidade dos planos é compatível com os registros existentes.
- Executar a migration antes de disponibilizar o novo código.
- Publicar a versão validada.
- Verificar logs, rotas, jobs, filas e funcionamento dos webhooks.

Não executar containers locais.

## 3. Homologação funcional

Testar o percurso completo:

1. Tenant com assinatura vencida acessa o sistema.
2. Visualiza a tela de bloqueio.
3. Clica em Renovar assinatura.
4. Seleciona um plano público e ativo.
5. Confere valores, periodicidade e resumo.
6. Clica em Prosseguir para pagamento.
7. Visualiza os métodos Efí habilitados.
8. Inicia o pagamento.
9. Em caso de pagamento pendente, pode recuperar e consultar a cobrança posteriormente.
10. Após confirmação confiável do pagamento, a assinatura é ativada e o acesso é restabelecido.

Validar também isolamento entre tenants, autorização administrativa, webhook duplicado, plano desativado, falha de pagamento e ausência de cobrança retroativa após cortesia expirada.

## 4. Homologação visual

Inspecionar no navegador desktop e mobile:

- Tela de acesso suspenso.
- Seleção de planos.
- Resumo da contratação.
- Modal de pagamento Efí.
- Situação de pagamento pendente.
- Confirmação e retorno ao dashboard.

Manter a identidade visual padronizada do VetorOS, com interface simples e sem configurações técnicas desnecessárias para o cliente.

## 5. Critérios de conclusão

Somente considerar a tarefa concluída quando:

- Commit e push estiverem confirmados.
- Migration e deploy tiverem sido executados com sucesso.
- Testes automatizados permanecerem aprovados.
- Fluxo em navegador estiver homologado.
- A conciliação Efí tiver sido comprovada com segurança, sem simular pagamentos reais.
- Nenhum tenant existente tiver perdido dados, permissões ou funcionalidades.

Caso não haja acesso à VPS ou credenciais Efí autorizadas, documentar o bloqueio real e não declarar homologação concluída.

Gerar relatório em `docs/VETOROS-EFI-RENEWAL-02.2.md` e atualizar `executed.md`, com evidências, commits, testes e pendências.
