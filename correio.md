# VETOR-STABILIZE-01 — Proteção dos bancos e consolidação fiscal

## Objetivo

Consolidar as entregas UX-ADMIN-03 e FISCAL-AVULSA-01, corrigir definitivamente o risco de exclusão acidental do banco e preparar a homologação da emissão fiscal avulsa na VPS.

## 1. Proteção obrigatória do banco

- Auditar `tests/TestCase.php`, `phpunit.xml`, `.env.testing` e a configuração de banco.
- Manter a proteção já implementada contra testes que utilizem MySQL operacional.
- Reforçar a proteção para impedir `migrate:fresh`, `db:wipe` e operações destrutivas nos bancos reais de desenvolvimento e produção.
- Criar configuração isolada e exclusiva para testes.
- Garantir que cache de configuração não consiga redirecionar testes para o banco operacional.
- Revisar scripts e entrypoints que executam `php artisan optimize`.
- Validar a proteção com testes seguros, sem executar operações destrutivas sobre bancos reais.

## 2. Recuperação e integridade

- Preservar os backups disponíveis.
- Verificar se o MySQL local possui binlogs ou outros mecanismos de recuperação.
- Não executar nova restauração sobre o banco atual sem backup verificado e autorização.
- Comparar as estruturas e contagens do banco restaurado.
- Registrar eventuais dados não recuperados.

## 3. Consolidação das entregas

- Revisar as alterações não commitadas das frentes anteriores.
- Preservar CONTRATO-02, EFI-02/03/04, UX-FISCAL-02, UX-ADMIN-03 e FISCAL-AVULSA-01.
- Executar novamente a suíte completa.
- Validar migrations em banco de testes isolado, compatível com MySQL 8.4.
- Verificar permissões, isolamento multitenant e integração financeira das notas avulsas.
- Revisar a interface da emissão avulsa em desktop e celular.

## 4. Preparação da homologação Spedy

Preparar os seguintes cenários, utilizando empresa e credenciais de homologação:

- NFS-e avulsa sem financeiro.
- NFS-e com conta a receber.
- NF-e avulsa de produtos.
- NFC-e avulsa.
- Pagamento já recebido com caixa aberto.
- Rejeição, reprocessamento e cancelamento.
- Verificação de PDF/XML e conciliação.

Não emitir documentos fiscais reais em produção durante essa etapa.

## 5. Publicação

Preparar commits e checklist de publicação, verificando previamente a segurança dos repositórios, as credenciais e a compatibilidade das migrations.

Não executar push, deploy, force push ou alterações de produção sem autorização específica.

## Resultado esperado

Entregar relatório em `executed.md` contendo:

- Proteções implementadas e comprovadas.
- Situação dos dados recuperados.
- Testes executados.
- Migrations pendentes.
- Estado dos commits.
- Condições objetivas para homologação na VPS.