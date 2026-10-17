# VETOR-UX-MONEY-02 — Consolidação e publicação. Resultado: **CONCLUÍDO; COMMIT `559d0111` PUBLICADO NA `main`; CI VERDE; SEM DEPLOY E SEM MIGRATION**

Data: 2026-10-17. Executor: estação de desenvolvimento local (WSL).

## Comparação do correio

O `correio.md` mudou desde a execução anterior. A instrução atual é a **VETOR-UX-MONEY-02**: ajustes finais da VETOR-UX-MONEY-01 (aprovada), testes e publicação. O relatório da MONEY-01 foi removido deste arquivo; o conteúdo dele está no histórico do git e resumido no item 7.

## 1. Campos monetários restantes no modo `cents`

Os sete campos que ainda estavam no modo `decimal` passaram ao modo `cents`, que é o padrão do `MoneyInput`:

- "Valor" das ações de cobrança do contrato personalizado (RootAdmin);
- preço dos períodos de plano (criar e editar);
- taxa fixa por meio de pagamento;
- "Valor recebido" das cobranças de contrato de manutenção;
- valor do serviço e valor unitário da nota avulsa.

Não sobrou nenhum campo monetário no modo `decimal`. O modo continua disponível no componente, sem uso.

Continuam fora da máscara, como pede o correio: percentual da taxa, alíquota de ISS, quantidade da nota avulsa, metas (%), comissão (%), dias e prazos.

## 2. Colagem, formato enviado e regras

- **Colagem:** "1.250,75", "1,250.75" e "R$ 1.250,75" resultam em 1.250,75 em todos os campos testados (item 5), com colagem real (Ctrl+C/Ctrl+V).
- **Formato enviado:** o Laravel continua recebendo o decimal normalizado ("1234.56").
- **Nada alterado em:** migrations, valores existentes, regras financeiras, emissão fiscal e cobrança recorrente.

## 3. Licença em Sistema e módulos

Novo componente `resources/js/components/license-section.tsx`.

- **Sempre visível:** plano, prazo ("N dias restantes", "vence hoje" ou "vencida há N dias"), selo da situação e "Licenciado para (titular)".
- **Modal "Detalhes da licença":** titular, CNPJ, endereço, plano, contratação, vencimento, carência (quando houver) e o motivo da situação. Só aparecem os dados preenchidos.
- **Pagamento Efí:**
  - **Visível no bloco** quando há o que pagar: vencendo, vencida, em carência ou cobrança do contrato em aberto.
  - **Assinatura em dia:** botão "Renovar antecipadamente", que abre um modal com a renovação. O texto "a nova vigência começa ao fim da atual" confere com o `PlanRenewalService`, que soma a partir do vencimento atual.
- **Contrato sem cobrança em aberto:** o aviso "Nenhuma cobrança em aberto…" fica no modal de detalhes.

## 4. Fluxos Efí e tela de desbloqueio

- `EfiCheckout`, `SaasCheckoutController` e as rotas `subscription.efi.*`/`check_status` não foram alterados.
- **Tela de desbloqueio:** o usuário bloqueado cai em `/subscription/blocked` ("Acesso suspenso", com situação, carência e renovação), e Sistema e módulos redireciona para ela. Conferido no Firefox e no Chromium (item 5).

## 5. Testes

- **PHP:** `php artisan test --parallel` → **784 passaram** (5449 asserções).
- **JavaScript:** `npm run test:js` → **13 passaram**.
- **TypeScript:** `tsc --noEmit` sem erros.
- **Build:** `npm run build` ok.
- **Prettier e ESLint:** ok; nenhum problema novo em relação ao commit anterior.
- **Testes visuais**, nos dois navegadores, com Playwright contra servidor isolado na porta 8011 e SQLite descartável (nenhum banco real):

| Cenário | Chromium | Firefox |
|---|---|---|
| Nota avulsa: dígitos 12538, "125,38", vírgula do teclado numérico | 125,38 | 125,38 |
| Taxa fixa: idem | 125,38 | 125,38 |
| Preço do período (RootAdmin): idem | 125,38 | 125,38 |
| Colagem "1.250,75" / "1,250.75" / "R$ 1.250,75" nos três campos | 1.250,75 | 1.250,75 |
| Licença: contrato ativo, sem cobrança | só detalhes | só detalhes |
| Licença: plano ativo | botão e modal "Renovar antecipadamente" | idem |
| Licença: plano em carência | pagamento no bloco | idem |
| Detalhes da licença sem "null" | ok | ok |
| Bloqueado: login → `/subscription/blocked`, Sistema e módulos → bloqueio | ok | ok |

- **Observação fora do escopo:** no painel inicial, `components/Charts/chart-area-dashboard.tsx` gera erro de página (`lines is undefined`) quando a API do gráfico responde sem `lines`, como acontece no banco de teste vazio. Não foi alterado.

## 6. `git status`, commit e push

- **Revisão antes do commit:**
  - 69 arquivos alterados e 5 novos, todos da MONEY-01/02, mais `correio.md` e `executed.md`.
  - Nenhuma alteração de outras frentes e nenhum arquivo ignorado fora do padrão (`.env`, `.env.testing` e dumps fora do git).
  - Branch atual: `main`, sincronizada com `origin/main`.
- **Verificação de segredos** nos arquivos em stage: `secret-guard` e `gitleaks` 8.30.1 (a mesma versão do CI), **nada encontrado**.
- **Commit do código:** `559d01110ed36eecd1845e935181203514011993`, "Padroniza os campos monetários em BRL e leva o pagamento da assinatura para a licença (VETOR-UX-MONEY-01/02)", com 74 arquivos.
- **Push:** `origin/main` `2e518224..559d0111`.
- **CI do GitHub** (`https://github.com/brasilgb/vetoros/actions/runs/39065116334`): **`test` success** (inclui `npm run test:js` no Node 24 e o build) e **`secrets` success**.
- **Commit deste relatório:** contém só `correio.md` e `executed.md`.
- **Não executado:** deploy, migrations em produção, nem atualização do ponteiro do submódulo no `infra-abrasil`.

## 7. Resumo da VETOR-UX-MONEY-01 (incluída no mesmo commit)

- **Base comum:**
  - `lib/money.ts`: normalização, centavos inteiros e `formatBRL`.
  - Componente `MoneyInput`.
- **Mudanças de comportamento:**
  - Removidos `maskMoney`, `maskMoneyDot` e `parseMoneyInput`, cujo defeito exibia R$ 2,50 para totais inteiros calculados.
  - Fim de cerca de 25 formatadores locais.
  - Prévias e totais calculados em centavos, sem float.
  - A tecla decimal do teclado numérico vale como vírgula (no Firefox ela chegava como `Delete`).
- **Assinatura e menu:**
  - Item "Assinatura" removido do menu Configurações.
  - Tela "Minha assinatura" substituída pelo painel no bloco da licença; `/subscription` redireciona para `#licenca`.
  - Texto da licença sem "null" e com o aviso de licença vencida.

## 8. Pendências

- **Deploy na VPS:** quando autorizado. Não há migration nesta entrega.
- **Ponteiro no `infra-abrasil`:** atualizar `gateway/vetoros` para o commit publicado, se o deploy seguir pelo `infra-abrasil`.
- **Erro do gráfico do painel** (item 5): avaliar numa frente própria.
