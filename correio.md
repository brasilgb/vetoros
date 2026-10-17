# VETOR-UX-ADMIN-03 — Reorganização das telas Empresas e Empresas Emissoras

**Objetivo:** simplificar as duas principais áreas administrativas do RootAdmin, eliminando excesso de informações e controles visíveis simultaneamente.

## 1. RootAdmin → Empresas

Auditar a listagem, o cadastro e a edição atuais antes de alterar o frontend.

### Listagem
- Mostrar empresa, CNPJ, modalidade de assinatura, situação e ação de gerenciamento.
- Manter busca, filtros, paginação e indicadores essenciais.
- Remover da tabela detalhes que pertencem à edição.
- Usar badges discretos e alinhamento consistente.
- Criar uma apresentação responsiva.

### Cadastro e edição
Organizar em abas:

1. Dados gerais.
2. Assinatura e planos.
3. Financeiro e cobranças.
4. Módulos e funcionalidades.
5. Fiscal.

Utilizar Dialog do shadcn/ui, com rolagem interna e dimensões apropriadas. Para formulários extensos, permitir expansão ou manter uma página de edição organizada com a mesma estrutura de abas, caso seja mais seguro e utilizável.

Preservar todos os campos e comportamentos atuais, inclusive os controles que não aparecem na listagem.

## 2. RootAdmin → Fiscal → Empresas emissoras

Aplicar a simplificação definida na VETOR-UX-FISCAL-02.

Tabela principal:
- Empresa e CNPJ.
- Cadastro Spedy.
- Ambiente.
- Botão Configurações.

Modal:
- Geral.
- Modelos fiscais.
- Certificado.
- Integração Spedy.

Preservar os controles de cadastro e sincronização, aprovação de produção, permissões e bloqueios fiscais.

## 3. Regras de negócio obrigatórias

- Contratos personalizados continuam bloqueando a seleção de planos tradicionais.
- Manter cobranças recorrentes pela Efí Bank e as regras de carência, bloqueio e reativação.
- Preservar todos os controles fiscais da Spedy.
- Não alterar credenciais ou ambientes durante a refatoração.
- Não introduzir novos gateways.
- Não criar migrations sem necessidade comprovada.
- Não remover funcionalidades, rotas ou validações existentes.

## 4. Padrão visual

- Aproveitar Tailwind e shadcn/ui existentes.
- Menos colunas e menos textos explicativos permanentes.
- Abas para separar assuntos.
- Diálogos de confirmação para ações críticas.
- Contraste adequado em checkboxes e switches.
- Layout consistente no tema escuro.
- Estados de loading, sucesso e erro claros.
- Layout responsivo em desktop, tablet e celular.

## 5. Validação

Realizar auditoria visual e funcional das duas telas.

Testar:
- Cadastro e edição de empresas.
- Alteração de planos e contratos.
- Trava do plano quando há contrato personalizado.
- Cobranças e vencimentos.
- Ativação e suspensão de funcionalidades.
- Habilitações fiscais.
- Navegação dos modais e abas.
- Permissões do RootAdmin.
- Preservação das alterações ao trocar de aba.
- Testes PHP, TypeScript e build.

Registrar o resultado em `executed.md`.

**Importante:** preservar dados, regras comerciais e configurações existentes. Esta entrega é prioritariamente uma refatoração de UX/UI, não uma reconstrução dos módulos.

Não executar push, deploy ou migrations de produção sem autorização específica.