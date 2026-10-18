# Execução — VETOR-CHAT-05

Data: 18/10/2026  
Escopo comparado: `correio.md` mudou da tarefa STABILIZE-03 para VETOR-CHAT-05. Esta execução substitui integralmente o relatório anterior.

## Resultado

As correções locais de estabilização foram aplicadas nos repositórios VetorLeads e VetorOS. Nenhuma migração ou publicação em produção foi executada.

### VetorLeads

- Fixado o PHP da plataforma Composer em 8.3 para resolver o lockfile de acordo com `php:^8.3`. Dependências Symfony incompatíveis foram atualizadas para a linha 7.4; o lockfile continua compatível com o requisito declarado. Foram adicionados scripts de lint e análise estática que os workflows já invocavam.
- ESLint agora ignora somente `public-old/build/**`, os artefatos antigos gerados. Regras não foram desativadas. Erros preexistentes encontrados nos fontes mantidos foram corrigidos.
- Prettier foi alinhado a largura de 80 colunas e aplicado aos fontes.
- PHPStan/Larastan: corrigidos 88 problemas preexistentes, incluindo tipos de requests, relações e datas; um argumento indevido na consulta de prospecção; e configuração do seeder. Resultado final: zero erros, sem baseline ou supressões.
- Removido do `.env.example` um valor de senha SMTP que estava preenchido. A credencial já esteve no histórico Git e precisa ser rotacionada antes do uso operacional.
- Inbox do chat ganhou navegação responsiva: em telas estreitas alterna entre lista e conversa e permite voltar à lista; o vínculo manual com lead e o acesso ao lead associado continuam disponíveis.

### VetorOS

- Corrigido o alinhamento do botão do widget e o conflito visual mobile com os controles de consentimento de cookies. O painel aberto também respeita a altura disponível em telas pequenas.

## Validações executadas

- VetorLeads: `composer validate`, `composer install --no-scripts` e `composer check-platform-reqs` passaram. O hook `post-update-cmd` do Composer não executa neste ambiente porque o Boost do projeto não está configurado; a instalação sem scripts e as verificações de plataforma passaram.
- VetorLeads: Pint (`composer lint:check`) passou; PHPStan (`composer types:check`) passou com zero erros; ESLint, Prettier (`format:check`), TypeScript (`types:check`) e build Vite passaram.
- VetorLeads: suíte PHP passou com 1.185 testes e 12.535 assertions usando SQLite temporário e PHP 8.4.26.
- VetorLeads: conferidos visualmente desktop e mobile em Chromium/Firefox. Login, lista/detalhe de conversas e vínculo manual de lead foram exercitados no ambiente isolado; as capturas mobile mostram a alternância entre lista e conversa. Uma tentativa adicional de automação/captura mobile falhou ao iniciar o navegador por erro de memória/sandbox, depois das capturas visuais anteriores.
- VetorOS: checagem TypeScript e build de produção passaram. Widget inspecionado visualmente em Chromium e Firefox, desktop e mobile; estados fechado, aberto e API indisponível foram conferidos. O envio quando a API não está configurada apresenta o estado de falha esperado. Os servidores e bancos usados eram locais e temporários.
- O PHP 8.3 não está instalado localmente; a resolução do lockfile foi feita com `platform.php=8.3.0` e verificada no ambiente PHP 8.4.26. A matriz CI real ainda precisa confirmar 8.3.
- Não foi possível obter links ou status CI para esta execução: `gh run list` falhou por indisponibilidade de conexão com `api.github.com`. Os workflows locais cobrem a matriz PHP 8.3/8.4/8.5, lint, análise estática, testes e build; os resultados remotos ficam pendentes de conectividade.

## Preparação para produção

Não houve acesso ao banco, proxy ou ambiente de produção. O estado real das migrações não foi consultado. As migrações existentes devem ser revisadas e aplicadas apenas no procedimento de release aprovado: VetorLeads primeiro (schema e serviço de chat), VetorOS depois (configuração/widget). Antes disso:

1. Confirmar e guardar backup verificável dos bancos dos dois sistemas.
2. Configurar no gerenciador de segredos os nomes usados pelo serviço: `WEB_CHAT_SERVICE_TOKEN` e `WEB_CHAT_TENANT_ID` no VetorLeads; URL interna, token e tenant do Web Chat no VetorOS, conforme `config/services.php` e `.env.example`. Não copiar segredos para arquivos versionados.
3. Confirmar resolução do endereço interno e encaminhamento do proxy entre VetorOS e VetorLeads, incluindo autenticação e limites de requisição.
4. Aplicar migrations na ordem indicada, validar logs e executar smoke tests de abertura de sessão, envio/recebimento, identidade do tenant, inbox e vínculo de lead.
5. Manter plano de rollback de código e banco baseado no backup; validar compatibilidade/rollback de cada migration antes de aplicá-la.

## Pendências

- Confirmar workflows CI remotos quando GitHub estiver acessível, incluindo execução na versão PHP 8.3.
- Conferir migrations pendentes, variáveis, proxy e smoke tests no ambiente de homologação/produção com acesso autorizado.
- Rotacionar a credencial SMTP removida do exemplo antes de qualquer uso.
- Produção permanece sem migrations e sem deploy.
