# Execução do correio

## Comparação

O `correio.md` está diferente da versão anterior (`d566b8cf`): acrescenta o objetivo `VETOR-MKT-WAHA-01`, com canal oficial configurável de WhatsApp para VetorOS e VetorLeads, melhorias comerciais na página pública e uma imagem temática para o hero. O pedido autoriza implementação, validação, commit e push; deploy em produção está expressamente fora desta etapa.

## Resultado

- VetorOS: canal comercial configurável no painel, com número normalizado, nome, mensagem padrão e controles de exibição pública/uso interno. CTAs e botão flutuante passaram a usar essa configuração; sem número público ativo, os contatos de WhatsApp são ocultados.
- VetorOS: hero redesenhado com imagem de fundo de uma assistência técnica reparando celulares, computadores e eletrônicos. O texto e os recursos foram atualizados para comunicar melhor os benefícios do sistema.
- VetorLeads: canal institucional independente das conexões WAHA de cada tenant, configurável pelo Root Admin e compartilhado nas páginas institucionais. O fallback hardcoded do telefone no frontend foi removido.
- Os artefatos de build gerados em `public/build` no VetorLeads foram removidos após a validação; alterações preexistentes em `correio.md` e `composer.lock` foram preservadas.

## Validação

- VetorOS: suíte PHP completa — 803 testes aprovados, 5.601 asserções; Pint; TypeScript; ESLint; 4 testes JS; build Vite.
- VetorLeads: 2 testes de feature aprovados, 25 asserções; TypeScript; ESLint; build Vite.
- Revisão `git diff --check` sem erros nos arquivos da implementação. O `correio.md` já continha espaços finais e foi preservado.
- Não foi possível abrir o servidor local para uma inspeção visual da página; a imagem do hero foi gerada e incorporada ao layout responsivo.

## Publicação

Nenhum deploy foi executado. Commits e push serão registrados após a revisão final das alterações.
