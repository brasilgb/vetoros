<?php

namespace App\Http\Controllers\Integration;

use App\Http\Controllers\Controller;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Throwable;

/**
 * Consulta somente leitura usada pelo CRM ABrasil para saber se um prospect
 * já criou conta no VetorOS. O cadastro é o Tenant criado em
 * RegisteredUserController::store (ou pelo admin). Nunca devolve dados pessoais.
 */
class RegistrationCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lookup_field' => ['required', 'string', Rule::in(['whatsapp', 'email'])],
            'lookup_value' => ['required', 'string', 'max:255'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'Invalid request.', 'errors' => $validator->errors()], 422);
        }

        $field = $request->string('lookup_field')->toString();
        $value = $request->string('lookup_value')->toString();

        if ($field === 'email') {
            $value = mb_strtolower(trim($value));
            $valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
        } else {
            $value = self::whatsappKey($value);
            $valid = $value !== null;
        }

        if (! $valid) {
            return response()->json([
                'message' => 'Invalid request.',
                'errors' => ['lookup_value' => ['O valor informado é inválido para o campo de busca.']],
            ], 422);
        }

        try {
            $registeredAt = $field === 'email'
                ? $this->firstRegistrationByEmail($value)
                : $this->firstRegistrationByWhatsapp($value);
        } catch (Throwable $exception) {
            // Só a classe: a mensagem de QueryException inclui os bindings (lookup_value).
            Log::error('Falha na consulta de cadastro para o CRM.', ['exception' => $exception::class]);

            return response()->json(['message' => 'Service unavailable.'], 503);
        }

        if ($registeredAt === null) {
            return response()->json(['registered' => false]);
        }

        return response()->json([
            'registered' => true,
            'registered_at' => $registeredAt->utc()->toIso8601ZuluString(),
        ]);
    }

    private function firstRegistrationByEmail(string $email): ?Carbon
    {
        $createdAt = Tenant::query()
            ->whereRaw('LOWER(TRIM(email)) = ?', [$email])
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->value('created_at');

        return $createdAt ? Carbon::parse($createdAt) : null;
    }

    private function firstRegistrationByWhatsapp(string $key): ?Carbon
    {
        // O WhatsApp é gravado com máscara, ex. "(51) 99999-9999"; os 4 últimos
        // dígitos ficam sempre contíguos, então servem de pré-filtro no SQL e a
        // comparação exata é feita aqui, já normalizada.
        $tenant = Tenant::query()
            ->select(['whatsapp', 'created_at'])
            ->where('whatsapp', 'like', '%'.substr($key, -4))
            ->whereNotNull('created_at')
            ->orderBy('created_at')
            ->orderBy('id')
            ->cursor()
            ->first(fn (Tenant $tenant) => self::whatsappKey((string) $tenant->whatsapp) === $key);

        return $tenant?->created_at;
    }

    /**
     * Chave de comparação de telefone brasileiro: 55 + DDD + número, sem o
     * nono dígito de celular, para casar "(51) 9999-9999" com "5551999999999".
     * Não reaproveita WhatsAppPhone::normalize porque aquele trata DDD 55
     * sem código de país como se já tivesse o 55.
     */
    public static function whatsappKey(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value);
        $length = strlen($digits);

        if ($length === 10 || $length === 11) {
            $digits = '55'.$digits;
        } elseif (! (($length === 12 || $length === 13) && str_starts_with($digits, '55'))) {
            return null;
        }

        if (strlen($digits) === 13 && $digits[4] === '9') {
            $digits = substr($digits, 0, 4).substr($digits, 5);
        }

        return strlen($digits) === 12 ? $digits : null;
    }
}
