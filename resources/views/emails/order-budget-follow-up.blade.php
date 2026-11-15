<!DOCTYPE html>
<html lang="pt-BR">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lembrete de orçamento</title>
</head>

<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
    <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="background:#f3f4f6;padding:40px 0;">
        <tr>
            <td align="center">
                <table width="600" cellpadding="0" cellspacing="0" role="presentation"
                    style="max-width:600px;background:#ffffff;border-radius:10px;overflow:hidden;box-shadow:0 10px 25px rgba(0,0,0,0.06);">
                    <tr>
                        <td align="center" style="background:#0f172a;padding:30px 20px;">
                            @if (!empty($companyLogoUrl))
                                <img src="{{ $companyLogoUrl }}" alt="Logo {{ $companyName }}"
                                    style="display:block;margin:0 auto 14px auto;width:84px;max-width:84px;height:auto;">
                            @endif
                            <p style="margin:0;color:#ffffff;font-size:24px;font-weight:bold;letter-spacing:0.5px;">
                                {{ $companyName }}
                            </p>
                            <p style="margin:6px 0 0 0;color:#cbd5f5;font-size:13px;">
                                Lembrete de orçamento pendente
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <td style="padding:36px;color:#374151;">
                            <h2 style="margin-top:0;font-size:20px;color:#111827;">
                                Olá, {{ $order->customer?->name ?? 'cliente' }}.
                            </h2>

                            <p style="font-size:15px;line-height:1.6;margin:14px 0;">
                                Seu orçamento da ordem de serviço segue aguardando retorno há {{ $daysPending }} dia(s).
                                Responda este e-mail ou entre em contato com nossa equipe para informar sua decisão.
                            </p>

                            <table width="100%" cellpadding="0" cellspacing="0"
                                style="background:#f9fafb;border-radius:8px;padding:18px;margin:24px 0;font-size:14px;">
                                <tr>
                                    <td style="padding:4px 0;"><strong>Ordem:</strong> #{{ $order->order_number }}</td>
                                </tr>
                                @if (!empty($order->budget_value))
                                    <tr>
                                        <td style="padding:4px 0;"><strong>Valor do orçamento:</strong> R$ {{ number_format((float) $order->budget_value, 2, ',', '.') }}</td>
                                    </tr>
                                @endif
                            </table>

                            <p style="font-size:15px;line-height:1.6;">
                                Em caso de dúvida, responda este e-mail ou fale com nossa equipe para seguirmos com o atendimento.
                            </p>
                        </td>
                    </tr>

<tr><td style="background:#f9fafb;text-align:center;padding:24px;font-size:12px;color:#6b7280;">
                            <p style="margin:0;">© {{ date('Y') }} {{ $companyName }}</p>
                            <p style="margin:6px 0;">Todos os direitos reservados</p>
                            @if (!empty($companySite))
                                <p style="margin:6px 0;">
                                    <a href="{{ $companySite }}" style="color:#2563eb;text-decoration:none;">
                                        {{ $companySite }}
                                    </a>
                                </p>
                            @endif
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>

</html>
