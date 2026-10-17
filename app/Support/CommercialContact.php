<?php

namespace App\Support;

use App\Models\Admin\Setting;

class CommercialContact
{
    public static function current(): array
    {
        $settings = Setting::query()->first();

        return [
            'number' => $settings?->commercial_whatsapp ?: config('site.commercial_whatsapp'),
            'name' => $settings?->commercial_whatsapp_name ?: 'Atendimento VetorOS',
            'message' => $settings?->commercial_whatsapp_message ?: 'Quero mais informações sobre VetorOS',
            'publicEnabled' => (bool) ($settings?->commercial_whatsapp_public ?? true),
            'internalEnabled' => (bool) ($settings?->commercial_whatsapp_internal ?? true),
            'provider' => 'whatsapp',
        ];
    }
}
