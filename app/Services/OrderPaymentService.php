<?php

namespace App\Services;

use App\Models\App\CashSession;
use App\Models\App\Order;
use App\Models\App\OrderPayment;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class OrderPaymentService
{
    public const MOBILE_PAYMENT_PENDING = 'pending';
    public const MOBILE_PAYMENT_CONFIRMED = 'confirmed';

    public function __construct(
        private readonly FinancialReceivableService $financialReceivableService,
        private readonly PaymentFeeService $paymentFeeService,
    ) {}

    public function reportMobilePayment(Order $order, array $data, int $userId): void
    {
        $order->loadMissing('orderPayments');

        $amount = round((float) $data['amount'], 2);
        $remaining = $this->remainingAmount($order);

        if ($amount > $remaining) {
            throw ValidationException::withMessages([
                'amount' => 'O valor informado é maior que o saldo restante da ordem.',
            ]);
        }

        $order->update([
            'technician_local_payment_received' => true,
            'technician_local_payment_status' => self::MOBILE_PAYMENT_PENDING,
            'technician_local_payment_amount' => $amount,
            'technician_local_payment_method' => $data['payment_method'],
            'technician_local_payment_notes' => $data['notes'] ?? null,
            'technician_local_payment_received_at' => $data['paid_at'] ?? now(),
            'technician_local_payment_user_id' => $userId,
        ]);
    }

    public function confirmMobilePayment(Order $order): OrderPayment
    {
        if (! $order->technician_local_payment_received || $order->technician_local_payment_status !== self::MOBILE_PAYMENT_PENDING) {
            throw ValidationException::withMessages([
                'amount' => 'Não há pagamento enviado pelo app aguardando conferência.',
            ]);
        }

        $payment = $this->register($order, [
            'amount' => (float) $order->technician_local_payment_amount,
            'payment_method' => $order->technician_local_payment_method,
            'paid_at' => $order->technician_local_payment_received_at ?? now(),
            'notes' => $order->technician_local_payment_notes,
        ]);

        $order->update([
            'technician_local_payment_status' => self::MOBILE_PAYMENT_CONFIRMED,
        ]);

        return $payment;
    }

    public function register(Order $order, array $data): OrderPayment
    {
        $order->loadMissing('orderPayments');

        $amount = round((float) $data['amount'], 2);
        $remaining = $this->remainingAmount($order);

        if ($amount > $remaining) {
            throw ValidationException::withMessages([
                'amount' => 'O valor informado é maior que o saldo restante da ordem.',
            ]);
        }

        $openCashSessionId = CashSession::query()
            ->where('status', 'open')
            ->latest('opened_at')
            ->value('id');

        if (! $openCashSessionId) {
            throw ValidationException::withMessages([
                'amount' => 'Abra o caixa diário antes de registrar pagamento da ordem.',
            ]);
        }

        // Taxa congelada no registro (manual > configuração > desconhecida): nunca
        // recalculada depois, mesmo que a configuração do meio de pagamento mude.
        $feeInformed = array_key_exists('fee_amount', $data) && $data['fee_amount'] !== null && $data['fee_amount'] !== '';
        $fee = $this->paymentFeeService->resolve(
            OrderEventRecorder::tenantOf($order),
            (string) $data['payment_method'],
            $amount,
            $feeInformed ? (float) $data['fee_amount'] : null,
        );

        $payment = OrderPayment::create([
            'order_id' => $order->id,
            'cash_session_id' => $openCashSessionId,
            'amount' => $amount,
            ...$fee,
            'payment_method' => $data['payment_method'],
            'paid_at' => $data['paid_at'] ?? now(),
            'notes' => $data['notes'] ?? null,
        ]);

        $this->financialReceivableService->syncOrder($order->fresh(['orderPayments']));

        return $payment;
    }

    public function remove(OrderPayment $payment): array
    {
        if ($payment->cashSession?->status === 'closed') {
            throw new RuntimeException('Não é possível remover pagamento vinculado a um caixa já fechado.');
        }

        $paymentData = [
            'payment_id' => $payment->id,
            'cash_session_id' => $payment->cash_session_id,
            'amount' => (float) $payment->amount,
            'payment_method' => $payment->payment_method,
            'paid_at' => $payment->paid_at?->toDateTimeString(),
        ];

        $payment->delete();

        $this->financialReceivableService->syncOrder($payment->order()->with('orderPayments')->firstOrFail());

        return $paymentData;
    }

    private function remainingAmount(Order $order): float
    {
        $totalOrder = round((float) ($order->service_cost ?? 0), 2);
        $totalPaid = round((float) $order->orderPayments->sum('amount'), 2);

        return round(max(0, $totalOrder - $totalPaid), 2);
    }
}
