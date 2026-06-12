<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Your tour tomorrow</title>
</head>
<body style="margin: 0; padding: 0; background: #f4f4f5; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background: #f4f4f5; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width: 600px; width: 100%; background: #ffffff; border-radius: 10px; overflow: hidden;">
                    <tr>
                        <td style="background: #b45309; padding: 20px 28px;">
                            <span style="color: #ffffff; font-size: 18px; font-weight: 600;">Jahongir Travel</span>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 28px;">
                            {{-- Body is the same content the WhatsApp reminder carries, --}}
                            {{-- pre-converted from WhatsApp markup to safe HTML upstream. --}}
                            <div style="font-size: 15px; line-height: 1.6; color: #1f2937;">
                                {!! $bodyHtml !!}
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 16px 28px 28px;">
                            <p style="color: #9ca3af; font-size: 12px; margin: 0; border-top: 1px solid #e5e7eb; padding-top: 16px;">
                                Booking reference: {{ $reference }}<br>
                                Jahongir Travel · Samarkand, Uzbekistan
                            </p>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
