<?php

namespace App\Services;

use App\Models\App\WhatsappMessage;

class WhatsappMessageTemplateService
{
    public function defaultMessages(): array
    {
        return [
            'generatedbudget' => "{{ saudacao }}, {{ cliente }}!\nEquipamento analisado preliminarmente. Segue orçamento inicial para reparo conforme diagnóstico técnico apresentado na OS {{ ordem }}.\nO serviço será executado somente mediante sua aprovação. Responda a esta mensagem para informar sua decisão.",
            'servicecompleted' => "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} foi concluída com sucesso.\nEntre em contato com nossa equipe para combinar a retirada.",
            'feedback' => "{{ saudacao }}, {{ cliente }}!\nSua OS {{ ordem }} foi finalizada e sua opinião é muito importante para nós.\nResponda com uma nota de 1 a 5 e, se desejar, um comentário sobre o atendimento.",
            'defaultmessage' => "{{ saudacao }}, {{ cliente }}!\nAtualização da sua OS {{ ordem }}.\nQualquer dúvida, estamos à disposição.",
            'budgetfollowup' => "{{ saudacao }}, {{ cliente }}!\nSeu orçamento da OS {{ ordem }} segue aguardando retorno há {{ dias_pendentes }} dias.\nResponda a esta mensagem para informar sua decisão ou pedir ajuda.",
            'pendingpayment' => "{{ saudacao }}, {{ cliente }}!\nA OS {{ ordem }} segue com saldo pendente de {{ saldo }}.\nEntre em contato para combinar o pagamento. Se já pagou, desconsidere esta mensagem.",
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

        // Modelos salvos antes da retirada do portal ainda podem conter o link antigo.
        $template = implode("\n", array_filter(
            explode("\n", $template),
            fn (string $line): bool => preg_match('/\{\{\s*link[\s_-]*os\s*\}\}/i', $line) !== 1
        ));

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
