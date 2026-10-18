<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Admin\Setting;
use App\Services\Commercial\PublicPlanCatalog;
use App\Support\CommercialContact;
use App\Support\SiteMeta;
use App\Support\WebChatAvailability;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(PublicPlanCatalog $catalog): Response
    {
        return Inertia::render('site/plans/index', [
            'publicPlans' => $catalog->plans(),
            'contactChannel' => CommercialContact::current(),
            'webChatSettings' => $this->webChatSettings(),
        ])->withViewData(['meta' => SiteMeta::plans()]);
    }

    private function webChatSettings(): array
    {
        $settings = Setting::query()->first();

        return [
            'web_chat_enabled' => $settings?->web_chat_enabled ?? true,
            'web_chat_welcome_message' => $settings?->web_chat_welcome_message,
            'web_chat_business_hours' => $settings?->web_chat_business_hours,
            'web_chat_offline_message' => $settings?->web_chat_offline_message,
            'chat_open' => WebChatAvailability::isOpen($settings?->web_chat_business_hours),
        ];
    }
}
