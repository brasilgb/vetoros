<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Models\TenantFeedback;
use App\Support\SiteMeta;
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
        ])->withViewData(['meta' => SiteMeta::home()]);
    }
}
