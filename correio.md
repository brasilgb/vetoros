# VETOR-FISCAL-02 — Publicação, implantação e homologação real da integração Spedy

Objetivo: colocar em ambiente operacional a implementação concluída no VETOR-FISCAL-01, validar a migration, configurar corretamente o sandbox da Spedy e comprovar o fluxo completo de cadastro do emitente e certificado A1.

## 1. Antes de alterar qualquer coisa

Trabalhar no repositório oficial do VetorOS dentro de:

`/opt/infra-abrasil` em produção  
e no submódulo correspondente `gateway/vetoros`.

Não usar `infra-vetor`.

Verificar:

- branch atual;
- `git status`;
- HEAD local;
- `origin/main`;
- commit `3ffec982`;
- migrations pendentes;
- serviços `vetoros`, `vetoros-worker`, `vetoros-scheduler`, `nginx` e `mysql`;
- estado atual da integração Spedy.

Não imprimir nenhuma chave, certificado, senha ou segredo.

## 2. Segurança antes do push

Antes de publicar, conferir a situação atual dos P0 registrados anteriormente.

Verificar especialmente:

- se `brasilgb/vetoros` está privado;
- se `brasilgb/infraabrasil` está privado;
- se o procedimento anteriormente definido para credenciais/histórico já foi resolvido ou formalmente aceito.

Não executar force push nem reescrita de histórico sem autorização explícita.

Caso os bloqueios de segurança permaneçam, não realizar push e informar objetivamente o bloqueio.

Caso estejam resolvidos ou o responsável tenha autorizado a publicação no estado atual, prosseguir com push convencional.

## 3. Publicação

Confirmar que o commit:

`3ffec982`

é o HEAD esperado e contém somente as alterações do VETOR-FISCAL-01 e dependências já aprovadas.

Executar a suíte relevante antes do push.

Se estiver íntegro:

- push da `main`;
- atualizar o ponteiro do submódulo em `infra-abrasil`;
- commit do ponteiro da infra;
- push da infra.

Não executar force push.

## 4. Backup de produção

Antes de migrations ou recriação de containers:

- gerar backup completo do banco `vetoros`;
- compactar;
- validar integridade do arquivo;
- registrar nome e data do backup;
- não versionar o backup.

## 5. Migration

Revisar com:

`php artisan migrate:status`

e:

`php artisan migrate --pretend`

Aplicar somente após conferir as migrations pendentes.

A migration do VETOR-FISCAL-01 deverá adicionar em `fiscal_settings`:

- `spedy_environment`;
- `last_synced_at`.

Confirmar que é aditiva e não destrói informações existentes.

Depois executar:

`php artisan migrate --force`

## 6. Atualização dos serviços

Rebuild/recriação somente dos serviços necessários do VetorOS:

- `vetoros`;
- `vetoros-worker`;
- `vetoros-scheduler`.

Depois validar nginx e health checks.

Não alterar MySQL, Redis, WAHA, n8n ou outros sistemas sem necessidade.

## 7. Configuração real da Spedy Sandbox

No RootAdmin:

### Fiscal → Integração

Confirmar:

- ambiente = Sandbox;
- chave titular da conta Sandbox configurada;
- diagnóstico funcionando;
- webhook configurado;
- URL HTTPS correta.

Importante:

Sandbox e Produção são contas/credenciais independentes.

Nunca utilizar chave ou vínculo de produção como se fosse sandbox.

## 8. Tenant de homologação

Escolher uma empresa de teste controlada.

No cadastro da empresa conferir:

- CNPJ;
- razão social;
- nome fantasia;
- CEP;
- endereço;
- número;
- bairro;
- município;
- UF;
- e-mail;
- telefone.

Em Emissão Fiscal conferir:

- regime tributário;
- inscrição estadual, quando aplicável;
- inscrição municipal, quando aplicável;
- demais dados necessários ao modelo que será homologado.

## 9. RootAdmin → Fiscal → Empresas emissoras

Para o tenant de teste:

1. habilitar o fiscal;
2. liberar inicialmente somente NFS-e, se esse for o primeiro modelo homologado;
3. clicar **Cadastrar na Spedy**.

Resultado esperado:

- status `Cadastrada`;
- ID Spedy presente;
- indicação `(Sandbox)`;
- data da última sincronização;
- ausência de erro.

Se ocorrer erro, NÃO contornar manualmente.

Registrar:

- `registration_status`;
- `registration_error`;
- `spedy_company_id`;
- `spedy_environment`;
- `last_synced_at`.

Não exibir credenciais.

## 10. Certificado A1

Depois que a empresa estiver realmente cadastrada:

entrar como administrador do tenant em:

Sistema e módulos → Emissão fiscal.

Confirmar que o campo de certificado foi liberado.

Enviar certificado:

- `.pfx` ou `.p12`;
- certificado A1 válido;
- senha correta.

Resultado esperado:

`Ativo — válido até ...`

A senha não pode ser persistida nem aparecer em logs.

## 11. Sincronização

Voltar ao RootAdmin e executar:

**Consultar cadastro e certificado**

Esperado:

- empresa encontrada;
- certificado ativo;
- validade coerente;
- titular identificado quando retornado pela Spedy;
- `last_synced_at` atualizado.

## 12. Configuração da NFS-e

No tenant configurar somente os campos realmente necessários para NFS-e, incluindo:

- série;
- item da LC 116;
- ISS;
- tipo de tributação;
- município/código IBGE quando necessário;
- confirmação dos dados fiscais/contábeis.

Não pré-preencher artificialmente dados tributários.

Salvar e confirmar que `/companies/{id}/settings` foi sincronizado com sucesso.

## 13. Primeira emissão real de homologação

Emitir uma NFS-e de teste com valor pequeno e cliente controlado.

Registrar:

- `integrationId`;
- status inicial;
- retorno Spedy;
- número da nota, se autorizado;
- código de verificação;
- PDF;
- XML;
- webhook recebido;
- eventual reconciliação por `fiscal:sync-spedy`.

Não registrar chave API ou conteúdo sensível.

Comprovar que uma segunda tentativa não gera duplicidade.

## 14. Contrato de manutenção

Depois da emissão manual estar homologada, testar o fluxo que já foi implementado:

- contrato com NFS-e automática;
- cobrança com vencimento do ciclo;
- nota emitida pela competência, independentemente do pagamento;
- autorização;
- envio da fatura ao cliente;
- links assinados PDF/XML;
- pagamento posterior;
- nenhuma segunda nota criada pelo pagamento.

Executar também novamente o agendador para comprovar idempotência.

## 15. Critério de aprovação

Somente considerar o fiscal homologado quando estiver comprovado:

- integração Sandbox válida;
- empresa cadastrada automaticamente pelo VetorOS;
- vínculo gravado no ambiente correto;
- certificado A1 enviado pelo próprio tenant;
- certificado confirmado pela Spedy;
- configurações fiscais sincronizadas;
- NFS-e emitida;
- webhook/reconciliação funcionando;
- PDF/XML disponíveis;
- nenhuma emissão duplicada;
- nenhum segredo exposto em tela/log;
- isolamento entre tenants mantido.

## 16. Não migrar para produção ainda

Mesmo depois da homologação Sandbox, NÃO alterar o ambiente da plataforma para Produção nesta etapa.

Produção deverá ser uma etapa própria, porque exige:

- conta/chave de produção;
- novo cadastro das empresas na conta de produção;
- novos vínculos;
- revisão de certificado;
- aprovação explícita do RootAdmin;
- emissão real controlada.

## 17. Relatório

Ao final, atualizar `executed.md` com:

- commit publicado;
- migration aplicada;
- serviços atualizados;
- situação do sandbox;
- resultado do cadastro do emitente;
- resultado do certificado;
- resultado da emissão;
- resultado do webhook;
- resultado da reconciliação;
- resultado do contrato automático;
- testes executados;
- erros encontrados;
- pendências;
- rollback disponível.

Não registrar chaves, senhas, conteúdo do certificado nem outros segredos.

Se surgir qualquer falha da Spedy durante a homologação, diagnosticar e corrigir a integração antes de avançar para produção.