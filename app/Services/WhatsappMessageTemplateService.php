<?php

namespace App\Services;

use App\Models\App\WhatsappMessage;

class WhatsappMessageTemplateService
{
    public function defaultMessages(): array
    {
        return [
            'generatedbudget' => "{{ saudacao }}, {{ cliente }}!\nEquipamento analisado preliminarmente. Segue orçamento inicial para reparo conforme diagnóstico técnico apresentado na OS {{ ordem }}.\nO serviço será executado somente mediante sua aprovação. Valores e prazo podem sofrer alterações caso sejam identificadas necessidades adicionais durante o reparo.\nVocê pode acompanhar pelo link: {{ link_os }}",
            'servicecompleted' => "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} foi concluída com sucesso.\nVocê pode acompanhar pelo link: {{ link_os }}\nQualquer dúvida, conte com a gente.",
            'feedback' => "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} foi finalizada e sua opinião é muito importante para nós.\nAcesse sua área do cliente pelo link {{ link_os }} e deixe uma nota com um comentário rápido sobre sua experiência.\nSeu feedback nos ajuda a melhorar cada atendimento.",
            'defaultmessage' => "{{ saudacao }}, {{ cliente }}!\nAtualização da sua OS {{ ordem }}.\nAcompanhe pelo link: {{ link_os }}\nQualquer dúvida, estamos à disposição.",
            'budgetfollowup' => "{{ saudacao }}, {{ cliente }}!\nSeu orçamento da OS {{ ordem }} segue aguardando retorno há {{ dias_pendentes }} dias.\nVocê pode aprovar ou acompanhar pelo link: {{ link_os }}\nSe precisar de ajuda, estamos à disposição.",
            'pendingpayment' => "{{ saudacao }}, {{ cliente }}!\nA OS {{ ordem }} segue com saldo pendente de {{ saldo }}.\nVocê pode acompanhar pelo link: {{ link_os }}\nSe já realizou o pagamento, desconsidere esta mensagem.",
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

        $normalized = [];
        foreach ($variables as $key => $value) {
            $normalized[$this->normalizeKey($key)] = (string) $value;
        }

        return trim(preg_replace_callback('/\{\{\s*([^}]+?)\s*\}\}/', function (array $matches) use ($normalized) {
            $key = $this->normalizeKey($matches[1]);

            return $normalized[$key] ?? '';
        }, $template));
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
