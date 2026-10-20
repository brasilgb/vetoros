# VETOROS-DEPLOY-SEC-01 — Commit, Push e Deploy em Produção

## Objetivo

Publicar no servidor VPS as implementações VETOROS-SECURITY-FIX-01 e VETOROS-EFI-TEST-01, preservando integralmente os dados e permitindo homologação operacional diretamente no ambiente real.

**Infraestrutura:** `/opt/infra-abrasil`

**Autorização:** realizar commit, push, build, migrations necessárias e deploy. Não executar exclusões destrutivas, limpeza de arquivos antigos ou alterações de dados comerciais reais.

## 1. Preparação local

1. Confirmar branch, alterações pendentes e diferenças contra `origin/main`.
2. Incluir os arquivos das implementações de segurança, Efí Bank e auditoria.
3. Verificar se há credenciais, certificados, tokens ou dados sensíveis nos arquivos que serão versionados.
4. Executar os testes automatizados, análise estática e build.
5. Verificar que a integração Efí existente permanece funcional.
6. Criar commit descritivo:
   `fix(vetoros): harden tenant security and add Efi payment tests`
7. Executar push para `origin/main`, sem force push.

Interromper se houver falhas relevantes ou divergências inesperadas.

## 2. Preparação da VPS

Acessar `/opt/infra-abrasil`.

Antes de publicar:

- Identificar o container VetorOS e o Compose efetivamente utilizados.
- Confirmar branch e revisão atualmente implantada.
- Verificar os volumes persistentes do banco e dos arquivos.
- Realizar backup do banco e dos arquivos de OS, com verificação de integridade.
- Confirmar espaço em disco.
- Garantir persistência de `ORDER_FILES_ROOT`.
- Preservar configurações e credenciais da Efí Bank, Spedy, WAHA e SMTP.
- Garantir que o serviço anterior possa ser restaurado em caso de falha.

Não sobrescrever arquivos `.env` de produção.

## 3. Publicação

1. Atualizar o código a partir do commit publicado.
2. Construir as imagens necessárias.
3. Verificar previamente as migrations pendentes.
4. Aplicar somente migrations conhecidas e compatíveis, incluindo `create_efi_integration_tests_table`.
5. Publicar a versão atualizada do VetorOS.
6. Executar os ajustes de cache do Laravel conforme a configuração existente.
7. Verificar logs, health checks, conexões e funcionamento da aplicação.

Não executar comandos que recriem volumes de banco ou removam arquivos persistentes.

## 4. Arquivos antigos das OS

Executar inicialmente:

`php artisan vetoros:migrate-order-files`

Analisar o relatório JSON da simulação.

Após confirmar backup, configuração persistente e ausência de conflitos impeditivos, executar:

`php artisan vetoros:migrate-order-files --execute`

Verificar quantidades, checksums e arquivos ambíguos.

**Não remover `public/storage/orders` nesta execução.** Registrar os riscos residuais e reservar a retirada pública para depois da homologação.

## 5. Homologação no servidor

Validar os seguintes fluxos:

- Login e painel administrativo.
- RootAdmin e acesso às empresas.
- Isolamento entre tenants.
- Cadastro e consulta de clientes.
- Criação, consulta e edição de ordens de serviço.
- Fotos, assinaturas e recibos de OS.
- Portal público de acompanhamento, com e sem chave.
- Orçamentos e permissões de técnicos.
- Estoque, vendas, PDV e cancelamentos.
- Financeiro e contratos SaaS.
- Integração Efí Bank e abertura do modal de pagamentos de teste.
- Acesso por dispositivos móveis.

Usar registros de teste identificáveis e evitar alterações em dados comerciais de clientes reais.

Não realizar cobrança financeira real antes de uma autorização específica para o valor e o método.

## 6. Critérios de aprovação

Considerar o deploy concluído somente quando:

- O commit publicado corresponder ao código implantado.
- Containers e serviços estiverem saudáveis.
- Migrations necessárias estiverem aplicadas.
- Banco e arquivos persistentes estiverem preservados.
- Login, OS, portal público e PDV estiverem funcionais.
- Isolamento entre empresas estiver validado.
- O RootAdmin conseguir abrir o modal Efí.
- Não houver erros críticos novos nos logs.

Se houver falha crítica, interromper a homologação e executar o rollback previsto, sem eliminar os registros necessários à investigação.

## 7. Relatório final

Atualizar `executed.md` com:

- Hash do commit e resultado do push.
- Revisão anterior e revisão publicada.
- Containers atualizados.
- Migrations executadas.
- Resultado dos backups.
- Resultado da migração de arquivos.
- Testes funcionais realizados.
- Evidências dos testes Efí.
- Erros encontrados e correções.
- Pendências e riscos residuais.
- Status final: PUBLICADO E HOMOLOGADO, PUBLICADO COM PENDÊNCIAS ou ROLLBACK EXECUTADO.

**Não declarar homologação completa apenas porque os containers iniciaram.**
