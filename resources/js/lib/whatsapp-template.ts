/**
 * VETOROS-PERFORMANCE-OS-04: a consulta pública de OS foi removida e {{ link_os }} não tem mais destino.
 * Templates já salvos pelos tenants continuam funcionando: a linha inteira que cita o link sai da
 * mensagem; as demais variáveis e linhas ficam intactas. Mesma regra de
 * WhatsappMessageTemplateService::withoutRemovedPlaceholders (envio pelo servidor).
 */
export const withoutRemovedPlaceholders = (template: string) => template.replace(/^[^\n]*\{\{\s*link[\s_-]*os\s*\}\}[^\n]*(?:\r?\n|$)/gimu, '');
