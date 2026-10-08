# VETOR-INTEL-04 — Regras de cálculo dos indicadores operacionais e comerciais

Implementação: `App\Services\Intel\OperationalIndicatorsService`, exposto em
`GET /app/intel/indicators` (rota `app.intel.indicators`, permissão `reports.view`).
A mesma rota abre a tela **Indicadores** no navegador (`app/intel/indicators`), que busca os
dados nela mesma em JSON (`Accept: application/json`) — uma chamada por aplicação de filtros.

## Princípios

1. **Tenant explícito.** Toda consulta filtra `tenant_id` do usuário autenticado; nada depende só do escopo global.
2. **Nada presumido.** Quando a fonte não existe para um registro, ele não entra no cálculo e é **contado** em um campo `*_unknown` / `incomplete`. Nenhum valor desconhecido vira zero.
3. **Fontes confiáveis primeiro.** Datas de eventos (`order_events`), versões de orçamento (`order_budgets`) e histórico de atribuição (`order_technician_assignments`). `orders.updated_at` nunca é usado como data de evento.
4. **Período.** `from`/`to` (datas, inclusivas). Padrão: últimos 30 dias até hoje. Máximo de 366 dias.
5. **Status ativo** = qualquer status fora de `Cancelada` (2), `Entregue` (10) e `Serviço não executado` (8).

## Indicadores

### 1. OS paradas (`stalled_orders`)
- **Universo:** OS ativas.
- **Referência:** último evento de linha do tempo de status (`order_created`, `status_changed`, `order_reopened`).
  Sem evento (OS legada): última entrada em `order_status_history`. Sem nenhum dos dois: `unknown`.
- **Parada:** referência há mais de `stalled_days` (padrão 7) dias.
- **Saída:** total, lista (id, número, status, dias parada, origem da referência, cliente, equipamento, técnico) e `reference_unknown`.
- **Técnico:** só a atribuição registrada em aberto (`order_technician_assignments`); sem trilha, `null` — `orders.user_id` não é usado retroativamente.

### 2. OS atrasadas (`overdue_orders`)
- **Universo:** OS ativas com `delivery_forecast` (prazo vigente) anterior a hoje.
- **Saída:** total e quantas têm prazo original conhecido e já renegociado (`delivery_forecast` ≠ `original_delivery_forecast`).
- **Contra o prazo original (2ª entrega):** `past_original` = OS ativas com `original_delivery_forecast` anterior a hoje (inclui renegociadas para depois — a renegociação não apaga o atraso contra a promessa); `past_original_renegotiated`; `original_unknown` = OS ativas sem prazo original (legado). A tela destaca `past_original`.

### 3. Orçamentos aguardando aprovação (`budgets_awaiting`)
- **Universo:** versão corrente com status `sent` e **não vencida** (`OrderBudget::isExpired`).
- **Saída:** quantidade, valor orçado (`quoted_amount`), idade média em dias desde `sent_at`.
  Versões legadas (`sent_at` nulo) entram na quantidade/valor e são contadas em `age_unknown` (fora da média).

### 4. Orçamentos próximos do vencimento (`budgets_expiring`)
- **Universo:** versão `sent` com `valid_until` entre hoje e hoje + `expiring_days` (padrão 3).
- **Saída:** quantidade, valor e lista (OS, versão, validade).

### 5. Conversão de orçamentos (`budget_conversion`)
- **Unidade:** a OS (ciclo de orçamento), não a versão — renegociações não contam como novos orçamentos.
- **Coorte:** OS cuja **primeira versão enviada** (`MIN(sent_at)`, não legada) está no período.
- **Desfecho:** `approved` se alguma versão foi aprovada; senão `rejected` se a última resposta foi recusa; senão `expired` se a versão corrente venceu; senão `pending`.
- **Taxa:** `approved / (approved + rejected + expired)`; pendentes ficam fora do denominador. Sem decididos: `null`.

### 6. Tempo médio de aprovação (`approval_time`)
- **Universo:** OS da coorte de conversão com versão aprovada.
- **Tempo:** `approved_at` da versão aprovada − `MIN(sent_at)` do ciclo (inclui o tempo de renegociação).
- **Saída:** média e mediana em horas, e quantidade de versões por ciclo (média).

### 7. Produtividade dos técnicos (`technician_productivity`)
- **Eventos:** `status_changed`/`order_reopened` para `Serviço concluído` (7) e entregas (`status_changed` → 10) no período.
- **Atribuição:** técnico com atribuição ativa (`activeAt`) no instante do evento. Sem histórico de atribuição: grupo `unattributed` (não é imputado ao técnico atual).
- **Saída por técnico:** concluídas, entregues, retornos em garantia originados de OS concluídas por ele no período (`warranty_source_order_id`). Sem ranking — dados para comparação contextual.

### 8. Cumprimento de prazo (`deadline_compliance`)
- **Universo:** OS com entrega (`status_changed` → 10) no período.
- **No prazo:** `delivered_at` (data) ≤ `original_delivery_forecast`.
- **Sem prazo original** (OS legada): fora do cálculo, contadas em `original_unknown`.
- **Saída:** no prazo, atrasadas, taxa, atraso médio em dias, quantas tiveram renegociação de prazo (`delivery_forecast_changed`).

### 9. Rentabilidade real (`profitability`)
- **Universo:** OS com entrega no período.
- **Cálculo:** `OrderMarginService::breakdown` por OS.
- **Saída:** soma de receita, custo conhecido (`known_cost_complete` = receita − margem) e margem **apenas das OS completas**; quantidade de OS completas e incompletas e,
  para as incompletas, contagem por componente (`stock_parts`, `manual_parts`, `commission`, `payment_fees`) e status (`unknown`/`pending`).
  Nunca soma margem parcial.

### 10. Qualidade dos dados (`data_quality`)
Resumo das contagens de desconhecidos/pendentes dos indicadores acima, para o usuário saber quanto do número é confiável.

## Dashboard existente (auditoria da 2ª entrega)

| Card | Regra antiga | Comparação com o serviço | Decisão |
|---|---|---|---|
| Prazo vencido (`numorde_overdue`) | OS fora de 2/8/10 com `delivery_forecast` < hoje | Igual a `overdue_orders.total` (prazo vigente) | Mantido (equivalente) |
| Aguardando aprovação (`numorde_awaiting_approval`) | Toda OS no status 3 | Divergia: contava orçamento corrente **vencido** | Substituído pela regra do serviço (versão corrente não vencida); novo card **Orçamento vencido** (`numorde_budget_expired`). Escopo do técnico preservado |
| Vencendo hoje / amanhã | `delivery_forecast` = hoje/amanhã | Sem equivalente no serviço | Mantido |
| Aguardando retirada | Status 9 | Sem equivalente | Mantido |
| Sem técnico | `user_id` nulo em OS ativa | Sem equivalente (estado atual, não histórico) | Mantido |
| Acompanhamentos de orçamento | Corrigido no INTEL-03 (data real de envio) | — | Mantido |

Os cards do dashboard respeitam o escopo do usuário (técnico vê só as próprias OS); a tela
Indicadores é da empresa inteira e exige `reports.view`. Por isso os cards não leem o serviço
diretamente: aplicam a mesma regra sobre o escopo do usuário.