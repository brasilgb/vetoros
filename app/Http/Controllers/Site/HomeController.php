<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\Admin\Setting;
use App\Models\TenantFeedback;
use App\Support\CommercialContact;
use App\Support\SiteMeta;
use App\Support\WebChatAvailability;
use Illuminate\Database\QueryException;
use Inertia\Inertia;

class HomeController extends Controller
{
    public function index()
    {
        try {
            $testimonials = TenantFeedback::query()
                ->with('tenant:id,company')
                ->where('testimonial_status', 'published')
                ->whereNotNull('testimonial_excerpt')
                ->latest('testimonial_published_at')
                ->take(3)
                ->get()
                ->map(fn (TenantFeedback $feedback) => [
                    'id' => $feedback->id,
                    'excerpt' => $feedback->testimonial_excerpt,
                    'public_name' => $feedback->testimonial_public_name ?: ($feedback->tenant?->company ?? 'Cliente VetorOS'),
                    'public_role' => $feedback->testimonial_public_role,
                    'company' => $feedback->tenant?->company,
                    'rating' => (int) ($feedback->feedback_rating ?? 5),
                ])
                ->values();
        } catch (QueryException) {
            $testimonials = collect();
        }

        return Inertia::render('site/home/index', [
            'testimonials' => $testimonials,
            'contactChannel' => CommercialContact::current(),
            'webChatSettings' => $this->webChatSettings(),
        ])->withViewData(['meta' => SiteMeta::home()]);
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
