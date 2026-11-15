<?php

namespace App\Http\Controllers\App;

use App\Events\WhatsappMessageSettingsUpdated;
use App\Http\Controllers\Controller;
use App\Models\App\WhatsappMessage;
use App\Services\WhatsAppService;
use App\Services\WhatsappMessageTemplateService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

class WhatsappMessageController extends Controller
{
    public function __construct(
        private readonly WhatsappMessageTemplateService $whatsappMessageTemplateService,
        private readonly WhatsAppService $whatsAppService,
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(WhatsappMessage $whatsappmessage)
    {
        Gate::authorize('whatsapp-messages.access');

        $whatsappmessage = $this->whatsappMessageTemplateService->current();
        $connection = $this->whatsAppService->connectionFor((int) Auth::user()->tenant_id);

        return Inertia::render('app/whatsapp-message/index', [
            'whatsappmessage' => $whatsappmessage,
            'connection' => [
                'status' => $connection->status,
                'phone_number' => $connection->phone_number,
            ],
        ]);
    }

    public function update(Request $request, WhatsappMessage $whatsappmessage): RedirectResponse
    {
        Gate::authorize('whatsapp-messages.access');

        $data = $request->validate([
            'generatedbudget' => 'nullable|string|max:500',
            'servicecompleted' => 'nullable|string|max:500',
            'feedback' => 'nullable|string|max:500',
            'defaultmessage' => 'nullable|string|max:500',
            'budgetfollowup' => 'nullable|string|max:500',
            'pendingpayment' => 'nullable|string|max:500',
            'technicianschedule' => 'nullable|string|max:500',
            'budgetapproved' => 'nullable|string|max:500',
        ]);

        $whatsappmessage = $this->whatsappMessageTemplateService->update($whatsappmessage, $data);
        event(new WhatsappMessageSettingsUpdated($whatsappmessage->id, (int) Auth::id(), [
            'updated_fields' => array_keys(array_filter($data, fn ($value) => $value !== null)),
        ]));

        return redirect()->route('app.whatsapp-message.index', ['whatsappmessage' => $whatsappmessage->id])->with('success', 'Mensagens do WhatsApp editadas com sucesso');
    }
}
