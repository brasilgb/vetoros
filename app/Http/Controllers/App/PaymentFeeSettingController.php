<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\App\PaymentFeeSetting;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Configuração das taxas por meio de pagamento do tenant. Valem só para pagamentos
 * registrados depois da gravação; os anteriores mantêm a taxa congelada.
 */
class PaymentFeeSettingController extends Controller
{
    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('other-settings.access');

        $tenantId = (int) Auth::user()?->tenant_id;
        abort_unless($tenantId > 0, 403);

        $validated = $request->validate([
            'fees' => ['present', 'array'],
            'fees.*.payment_method' => ['required', 'string', Rule::in(PaymentFeeSetting::METHODS), 'distinct'],
            // Vazio = sem configuração (taxa desconhecida); zero = taxa zero conhecida.
            'fees.*.fee_percentage' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'fees.*.fee_fixed_amount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
        ]);

        DB::transaction(function () use ($validated, $tenantId): void {
            foreach ($validated['fees'] as $fee) {
                $percentage = $fee['fee_percentage'] ?? null;
                $fixed = $fee['fee_fixed_amount'] ?? null;
                $query = PaymentFeeSetting::withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('payment_method', $fee['payment_method']);

                if ($percentage === null && $fixed === null) {
                    $query->delete();

                    continue;
                }

                PaymentFeeSetting::withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenantId, 'payment_method' => $fee['payment_method']],
                    [
                        'fee_percentage' => round((float) ($percentage ?? 0), 3),
                        'fee_fixed_amount' => round((float) ($fixed ?? 0), 2),
                        'updated_by' => Auth::id(),
                    ],
                );
            }
        });

        return back()->with('success', 'Taxas dos meios de pagamento salvas. Valem para os próximos pagamentos.');
    }
}
