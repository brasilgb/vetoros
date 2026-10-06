<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\App\Company;
use App\Models\App\FiscalDocument;
use App\Models\App\FiscalSetting;
use App\Models\Tenant;
use App\Services\Fiscal\NativeFiscalService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recebe eventos invoice.* da Spedy (um webhook por conta, para todos os
 * tenants). Assinatura no padrão Standard Webhooks; sem segredo configurado,
 * recusa tudo. O payload só atualiza notas que o próprio VetorOS criou.
 */
class SpedyWebhookController extends Controller
{
    private const TOLERANCE_SECONDS = 300;

    public function __invoke(Request $request, NativeFiscalService $service): JsonResponse
    {
        $secret = (string) config('services.spedy.webhook_secret');

        if ($secret === '' || ! $this->validSignature($request, $secret)) {
            return response()->json(['message' => 'Assinatura inválida.'], 401);
        }

        $eventId = (string) $request->header('webhook-id');
        $event = (string) $request->input('event');
        $data = (array) $request->input('data', []);

        if (! str_starts_with($event, 'invoice.') || blank($data['id'] ?? null)) {
            return response()->json(['ignored' => true]);
        }

        $document = FiscalDocument::query()->withoutGlobalScopes()
            ->where('provider', FiscalSetting::PROVIDER_SPEDY)
            ->where(function ($query) use ($data) {
                $query->where('provider_reference', $data['id']);

                if (filled($data['integrationId'] ?? null)) {
                    $query->orWhere('integration_id', $data['integrationId']);
                }
            })
            ->first();

        try {
            DB::table('fiscal_webhook_events')->insert([
                'provider' => FiscalSetting::PROVIDER_SPEDY,
                'event_id' => mb_substr($eventId, 0, 64),
                'event' => mb_substr($event, 0, 60),
                'fiscal_document_id' => $document?->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return response()->json(['duplicate' => true]);
        }

        if (! $document) {
            Log::info('Spedy webhook: nota desconhecida', ['event_id' => $eventId, 'event' => $event]);

            return response()->json(['ignored' => true]);
        }

        if (! $this->belongsToDocumentTenant($document, $data)) {
            Log::warning('Spedy webhook: CNPJ da empresa diverge do tenant da nota', ['event_id' => $eventId, 'fiscal_document_id' => $document->id]);

            return response()->json(['ignored' => true]);
        }

        $service->applyInvoice($document, $data);

        DB::table('fiscal_webhook_events')
            ->where('provider', FiscalSetting::PROVIDER_SPEDY)
            ->where('event_id', mb_substr($eventId, 0, 64))
            ->update(['processed_at' => now(), 'updated_at' => now()]);

        return response()->json(['processed' => true]);
    }

    /**
     * Defesa adicional: quando o evento traz o CNPJ do emissor, ele precisa ser
     * o da empresa do tenant dono da nota.
     */
    private function belongsToDocumentTenant(FiscalDocument $document, array $data): bool
    {
        $eventCnpj = preg_replace('/\D+/', '', (string) data_get($data, 'company.federalTaxNumber'));

        if ($eventCnpj === '') {
            return true;
        }

        $company = Company::query()->withoutGlobalScopes()->where('tenant_id', $document->tenant_id)->value('cnpj')
            ?: Tenant::query()->whereKey($document->tenant_id)->value('cnpj');

        return preg_replace('/\D+/', '', (string) $company) === $eventCnpj;
    }

    private function validSignature(Request $request, string $secret): bool
    {
        $id = (string) $request->header('webhook-id');
        $timestamp = (string) $request->header('webhook-timestamp');
        $signatures = (string) $request->header('webhook-signature');

        if ($id === '' || ! ctype_digit($timestamp) || $signatures === '') {
            return false;
        }

        if (abs(time() - (int) $timestamp) > self::TOLERANCE_SECONDS) {
            return false;
        }

        $key = base64_decode(str_starts_with($secret, 'whsec_') ? substr($secret, 6) : $secret, true);
        if ($key === false || $key === '') {
            return false;
        }

        $expected = base64_encode(hash_hmac('sha256', "{$id}.{$timestamp}.{$request->getContent()}", $key, true));

        foreach (explode(' ', $signatures) as $part) {
            [$version, $signature] = array_pad(explode(',', $part, 2), 2, '');

            if ($version === 'v1' && hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }
}
