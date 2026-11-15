<?php

namespace App\Services;

use App\Models\App\WhatsappMessage;

class WhatsappMessageTemplateService
{
    public function defaultMessages(): array
    {
        return [
            'generatedbudget' => "{{ saudacao }}, {{ cliente }}!\nEquipamento analisado preliminarmente. Segue orçamento inicial para reparo conforme diagnóstico técnico apresentado na OS {{ ordem }}.\nO serviço será executado somente mediante sua aprovação. Valores e prazo podem sofrer alterações caso sejam identificadas necessidades adicionais durante o reparo.",
            'servicecompleted' => "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} foi concluída com sucesso.\nQualquer dúvida, conte com a gente.",
            'feedback' => "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} foi finalizada e sua opinião é muito importante para nós.\nSeu feedback nos ajuda a melhorar cada atendimento.",
            'defaultmessage' => "{{ saudacao }}, {{ cliente }}!\nAtualização da sua OS {{ ordem }}.\nQualquer dúvida, estamos à disposição.",
            'budgetfollowup' => "{{ saudacao }}, {{ cliente }}!\nSeu orçamento da OS {{ ordem }} segue aguardando retorno há {{ dias_pendentes }} dias.\nSe precisar de ajuda, estamos à disposição.",
            'pendingpayment' => "{{ saudacao }}, {{ cliente }}!\nA OS {{ ordem }} segue com saldo pendente de {{ saldo }}.\nSe já realizou o pagamento, desconsidere esta mensagem.",
            'budgetapproved' => 'Bom dia, {{ cliente }}! Agradecemos seu retorno e a aprovação do orçamento. Assim que seu equipamento estiver pronto, avisaremos por aqui. Obrigado pela confiança!',
            'technicianschedule' => "{{ saudacao }}, {{ tecnico }}!\nVisita agendada para {{ data_visita }}.\nServiço: {{ servico }}.\nMateriais: {{ materiais }}.\nCliente: {{ cliente }}.\nEndereço: {{ endereco }}.",
        ];
    }

    public function current(): WhatsappMessage
    {
        return WhatsappMessage::query()->latest('id')->firstOr(function () {
            return WhatsappMessage::create($this->defaultMessages());
        });
    }

    public function update(WhatsappMessage $whatsappMessage, array $data): WhatsappMessage
    {
        $whatsappMessage->update($data);

        return $whatsappMessage->refresh();
    }

    /**
     * Substitui os placeholders `{{ chave }}` de um template pelos valores informados.
     *
     * Espelha (em PHP, do lado servidor) a mesma normalização de chave usada
     * historicamente no frontend (resources/js/components/WhatsAppButtonProps.tsx
     * e resources/js/pages/app/whatsapp-message/index.tsx): minúsculas, sem
     * acentos, espaços/traços viram "_". Isso garante que o envio real via WAHA
     * (que precisa montar o texto no backend) produza exatamente a mesma
     * mensagem que a prévia já mostrava ao usuário.
     *
     * @param  array<string, string>  $variables
     */
    public function render(?string $template, array $variables): string
    {
        if (! $template) {
            return '';
        }

        $template = self::withoutRemovedPlaceholders($template);

        $normalized = [];
        foreach ($variables as $key => $value) {
            $normalized[$this->normalizeKey($key)] = (string) $value;
        }

        return trim(preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function (array $matches) use ($normalized) {
            $key = $this->normalizeKey($matches[1]);

            return $normalized[$key] ?? '';
        }, $template));
    }

    /**
     * VETOROS-PERFORMANCE-OS-04: a consulta pública de OS foi removida e {{ link_os }} não tem mais
     * destino. Templates já salvos pelos tenants continuam funcionando: a linha inteira que cita o
     * link sai da mensagem ("Acompanhe pelo link: ..." não faz sentido sem ele); as demais
     * variáveis e linhas ficam intactas. Mesma regra em WhatsAppButtonProps.tsx.
     */
    public static function withoutRemovedPlaceholders(string $template): string
    {
        return preg_replace('/^[^\n]*\{\{\s*link[\s_-]*os\s*\}\}[^\n]*(?:\r?\n|$)/miu', '', $template) ?? $template;
    }

    private function normalizeKey(string $key): string
    {
        $key = mb_strtolower(trim($key));
        $key = str_replace(
            ['á', 'à', 'â', 'ã', 'ä', 'é', 'è', 'ê', 'ë', 'í', 'ì', 'î', 'ï', 'ó', 'ò', 'ô', 'õ', 'ö', 'ú', 'ù', 'û', 'ü', 'ç'],
            ['a', 'a', 'a', 'a', 'a', 'e', 'e', 'e', 'e', 'i', 'i', 'i', 'i', 'o', 'o', 'o', 'o', 'o', 'u', 'u', 'u', 'u', 'c'],
            $key
        );

        return preg_replace('/[\s-]+/', '_', $key);
    }
}
