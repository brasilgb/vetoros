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
