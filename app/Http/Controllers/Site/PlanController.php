<?php

namespace App\Http\Controllers\Site;

use App\Http\Controllers\Controller;
use App\Services\Commercial\PublicPlanCatalog;
use Inertia\Inertia;
use Inertia\Response;

class PlanController extends Controller
{
    public function index(PublicPlanCatalog $catalog): Response
    {
        return Inertia::render('site/plans/index', [
            'publicPlans' => $catalog->plans(),
        ]);
    }
}
