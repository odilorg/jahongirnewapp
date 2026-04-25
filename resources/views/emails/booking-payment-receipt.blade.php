<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Booking Confirmed – Jahongir Travel</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Arial,sans-serif;">

    <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
        <tr><td align="center">
            <table width="100%" cellpadding="0" cellspacing="0" style="max-width:600px;">

                {{-- Header --}}
                <tr>
                    <td style="background:#059669;border-radius:8px 8px 0 0;padding:28px 32px;text-align:center;">
                        <div style="font-size:36px;line-height:1;">✅</div>
                        <div style="color:#ffffff;font-size:22px;font-weight:700;margin-top:8px;">Payment received</div>
                        <div style="color:#a7f3d0;font-size:14px;margin-top:4px;">Jahongir Travel</div>
                    </td>
                </tr>

                {{-- Body --}}
                <tr>
                    <td style="background:#ffffff;padding:32px;">

                        <p style="margin:0 0 20px;font-size:16px;color:#111827;">
                            Hi <strong>{{ $inquiry->customer_name }}</strong>,
                        </p>
                        <p style="margin:0 0 24px;font-size:15px;color:#374151;">
                            Your booking is confirmed. Here is your receipt:
                        </p>

                        {{-- Receipt table --}}
                        <table width="100%" cellpadding="0" cellspacing="0"
                               style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;font-size:14px;margin-bottom:24px;">
                            <tr style="background:#f9fafb;">
                                <td style="padding:10px 14px;color:#6b7280;width:42%;">Reference</td>
                                <td style="padding:10px 14px;font-weight:700;color:#111827;">{{ $inquiry->reference }}</td>
                            </tr>
                            <tr>
                                <td style="padding:10px 14px;color:#6b7280;border-top:1px solid #e5e7eb;">Tour</td>
                                <td style="padding:10px 14px;color:#111827;border-top:1px solid #e5e7eb;">{{ $inquiry->tour_name_snapshot }}</td>
                            </tr>
                            <tr style="background:#f9fafb;">
                                <td style="padding:10px 14px;color:#6b7280;border-top:1px solid #e5e7eb;">Date</td>
                                <td style="padding:10px 14px;color:#111827;border-top:1px solid #e5e7eb;">{{ $inquiry->travel_date?->format('M j, Y') ?? '—' }}</td>
                            </tr>
                            <tr>
                                <td style="padding:10px 14px;color:#6b7280;border-top:1px solid #e5e7eb;">People</td>
                                <td style="padding:10px 14px;color:#111827;border-top:1px solid #e5e7eb;">{{ $ctx->paxLine($inquiry) }}</td>
                            </tr>
                        </table>

                        {{-- Payment section --}}
                        <div style="border:1px solid #e5e7eb;border-radius:6px;overflow:hidden;margin-bottom:24px;font-size:14px;">
                            <div style="background:#f9fafb;padding:8px 14px;font-weight:600;color:#374151;font-size:13px;text-transform:uppercase;letter-spacing:.04em;">
                                Payment
                            </div>
                            <div style="padding:12px 14px;border-top:1px solid #e5e7eb;">
                                <span style="color:#6b7280;">Paid online</span>
                                <span style="float:right;font-weight:700;color:#059669;">
                                    ${{ number_format($ctx->onlinePaidUsd, 2) }} USD
                                    @if ($ctx->uzsAmountRaw !== '')
                                        <span style="font-weight:400;color:#9ca3af;font-size:13px;">
                                            (~{{ number_format((float) $ctx->uzsAmountRaw) }} UZS)
                                        </span>
                                    @endif
                                </span>
                                <div style="clear:both;"></div>
                            </div>
                            @if ($ctx->isPartial)
                            <div style="padding:12px 14px;border-top:1px solid #e5e7eb;background:#fffbeb;">
                                <span style="color:#92400e;">⚠️ Remaining (cash at pickup)</span>
                                <span style="float:right;font-weight:700;color:#d97706;">
                                    ${{ number_format($ctx->remainingCashUsd, 2) }} USD
                                </span>
                                <div style="clear:both;"></div>
                            </div>
                            @else
                            <div style="padding:10px 14px;border-top:1px solid #e5e7eb;background:#f0fdf4;">
                                <span style="color:#166534;font-weight:600;">✓ Paid in full</span>
                            </div>
                            @endif
                        </div>

                        {{-- What's next --}}
                        <div style="background:#eff6ff;border-left:3px solid #3b82f6;border-radius:0 6px 6px 0;padding:14px 16px;margin-bottom:24px;font-size:14px;color:#1e3a5f;">
                            <strong style="display:block;margin-bottom:8px;">What's next</strong>
                            <ul style="margin:0;padding-left:18px;line-height:1.7;">
                                <li>Your driver/guide will contact you <strong>24 hours before the tour</strong></li>
                                <li>Pickup will be from your hotel or agreed location</li>
                                <li>Questions? Reply to this email or message us on WhatsApp</li>
                            </ul>
                        </div>

                        <p style="font-size:15px;color:#374151;margin:0 0 4px;">See you soon!</p>
                        <p style="font-size:15px;font-weight:600;color:#111827;margin:0;">Jahongir Travel</p>

                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td style="background:#f9fafb;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:16px 32px;text-align:center;">
                        <p style="margin:0;font-size:11px;color:#9ca3af;">
                            Jahongir Travel · Uzbekistan ·
                            <a href="https://jahongir-travel.uz" style="color:#9ca3af;">jahongir-travel.uz</a>
                        </p>
                        <p style="margin:4px 0 0;font-size:11px;color:#9ca3af;">
                            This is a transactional receipt for booking {{ $inquiry->reference }}.
                        </p>
                    </td>
                </tr>

            </table>
        </td></tr>
    </table>

</body>
</html>
