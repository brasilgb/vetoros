<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Fatura VetorOS paga</title>
</head>

<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    @php
        $logoPath = public_path('images/vetor.png');
        $logoUrl = file_exists($logoPath) ? asset('images/vetor.png') : null;
        $paidAt = $payment->paid_at ?? $payment->updated_at ?? now();
        $paymentStatusLabel = [
            'paid' => 'Pago',
            'approved' => 'Aprovado',
            'pending' => 'Pendente',
            'in_process' => 'Em processamento',
            'rejected' => 'Rejeitado',
            'cancelled' => 'Cancelado',
            'refunded' => 'Estornado',
        ][$payment->status] ?? $payment->status;
    @endphp

    <div style="display:none;max-height:0;overflow:hidden;opacity:0;">
        Recebemos o pagamento PIX da sua assinatura VetorOS.
    </div>

    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                    style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);">
                    <tr>
                        <td align="center" style="background:#020817;padding:30px 20px;">
                            @if (!empty($logoUrl))
                                <img src="{{ $logoUrl }}" alt="VetorOS" style="display:block;margin:0 auto 14px auto;width:84px;max-width:84px;height:auto;">
                            @endif
                            @include('emails.partials.brand-title')
                            <p style="margin:6px 0 0 0;color:#cbd5f5;font-size:13px;">Fatura de assinatura</p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:36px;color:#374151;">
                            <h2 style="margin-top:0;font-size:20px;color:#111827;">Pagamento confirmado</h2>

                            <p style="font-size:15px;line-height:1.6;margin:14px 0;">
                                Olá, {{ $tenant->name }}. Recebemos o pagamento PIX da assinatura do
                                <strong>VetorOS</strong> e o acesso da empresa foi liberado/renovado.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f9fafb;border-radius:8px;padding:18px;margin:24px 0;font-size:14px;">
                                <tr>
                                    <td style="padding:4px 0;"><strong>Empresa:</strong> {{ $tenant->company ?: $tenant->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><strong>Plano:</strong> {{ $plan->name }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><strong>Valor pago:</strong> R$ {{ number_format((float) $payment->amount, 2, ',', '.') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><strong>Pagamento:</strong> {{ $payment->payment_id }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><strong>Status:</strong> {{ $paymentStatusLabel }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><strong>Data:</strong> {{ $paidAt->format('d/m/Y H:i') }}</td>
                                </tr>
                                <tr>
                                    <td style="padding:4px 0;"><strong>Vencimento da assinatura:</strong> {{ $tenant->expires_at?->format('d/m/Y') ?? '-' }}</td>
                                </tr>
                            </table>

                            @if ($fiscalDocument)
                                <table width="100%" cellpadding="0" cellspacing="0"
                                    style="background:#eef6ff;border-radius:8px;padding:18px;margin:24px 0;font-size:14px;">
                                    <tr>
                                        <td style="padding:4px 0;"><strong>NFS-e:</strong> {{ $fiscalDocument->number ?: 'Em processamento' }}</td>
                                    </tr>
                                    <tr>
                                        <td style="padding:4px 0;"><strong>Status da nota:</strong> {{ $fiscalDocument->status }}</td>
                                    </tr>
                                    @if ($fiscalDocument->pdf_url)
                                        <tr>
                                            <td style="padding:12px 0 4px 0;">
                                                <a href="{{ $fiscalDocument->pdf_url }}" target="_blank"
                                                    style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 18px;border-radius:6px;font-size:14px;font-weight:bold;">
                                                    Abrir nota fiscal
                                                </a>
                                            </td>
                                        </tr>
                                    @endif
                                </table>
                            @endif

                            <p style="font-size:14px;line-height:1.6;color:#6b7280;">
                                Guarde este e-mail como comprovante da fatura paga. Em caso de dúvidas, acesse
                                <a href="https://vetoros.com.br" target="_blank" style="color:#2563eb;text-decoration:none;">VetorOS</a>
                                e use um de nossos canais de atendimento.
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
