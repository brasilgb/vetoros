# ABRASIL-AUTH-UX-01 — Padronização de Login e Cadastro (VetorOS)

## Diagnóstico

- Login e cadastro usavam `layouts/auth/auth-simple-layout.tsx`: cartão centralizado sobre `auth-images.jpg`.
- Cadastro (`pages/auth/register.tsx`) tinha wrapper com `max-h-[72svh]`/`76svh` e rolagem interna, o que podia esconder campos.
- Largura de referência do cadastro: `w-full max-w-4xl` (mantida).

## Alterações

- `resources/js/layouts/auth/auth-simple-layout.tsx`: layout dividido em duas áreas no desktop. Painel esquerdo com a imagem existente `public/images/vetoros-hero-maintenance.png` e a mensagem "Sua assistência técnica mais organizada e produtiva."; formulário à direita. No mobile o painel de imagem fica oculto. `FlashToastMessages` e `Toaster` foram mantidos.
- `resources/js/pages/auth/register.tsx`: removido o wrapper de altura fixa com rolagem interna.
- Preservados: autenticação, sessões, validações, recuperação de senha, isolamento multitenant e fluxos de cadastro (empresa, CNPJ, termos).

## Componentes

- Reutilizado: `BrandLoginLogo`, `FlashToastMessages`, `useAppearance`, `Toaster`, `cn`.
- Não criado: componentes novos.

## Imagens

- `public/images/vetoros-hero-maintenance.png` (asset já existente no projeto). `resources/js/images/auth-images.jpg` deixou de ser usado pelo layout e permanece no repositório.

## Validação

- `npx tsc --noEmit`: 0 erros.
- `npx vite build`: concluído.
- `npx eslint` nos arquivos alterados: sem erros.

## Pendências e limitações

- Evidências visuais (antes/depois) e testes de responsividade nos breakpoints do correio não foram capturados.
- Fluxos de login, cadastro com erros e recuperação não foram executados no navegador.
- Alterações não commitadas pré-existentes em `EfiSettingsController`, `efi.tsx` e testes de EFI não foram tocadas.
