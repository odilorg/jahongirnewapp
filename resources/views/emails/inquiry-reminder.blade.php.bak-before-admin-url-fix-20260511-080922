<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Reminder</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, sans-serif; max-width: 600px; margin: 20px auto; padding: 0 20px;">

    <h2 style="color: #7c3aed;">⏰ Reminder</h2>

    <p style="font-size: 16px; color: #111; background: #ede9fe; padding: 14px; border-radius: 6px;">
        {{ $reminder->message }}
    </p>

    @if ($inquiry)
        <h3 style="margin-top: 24px; color: #374151;">Booking context</h3>
        <table style="width: 100%; border-collapse: collapse; font-size: 14px;">
            <tr><td style="padding: 4px 0; color: #6b7280;">Reference</td><td><strong>{{ $inquiry->reference }}</strong></td></tr>
            <tr><td style="padding: 4px 0; color: #6b7280;">Guest</td><td>{{ $inquiry->customer_name }}</td></tr>
            <tr><td style="padding: 4px 0; color: #6b7280;">Phone</td><td>{{ $inquiry->customer_phone }}</td></tr>
            <tr><td style="padding: 4px 0; color: #6b7280;">Tour</td><td>{{ $inquiry->tour_name_snapshot }}</td></tr>
            <tr><td style="padding: 4px 0; color: #6b7280;">Travel date</td><td>{{ $inquiry->travel_date?->format('M j, Y') }}</td></tr>
            <tr><td style="padding: 4px 0; color: #6b7280;">Status</td><td>{{ ucfirst(str_replace('_', ' ', $inquiry->status)) }}</td></tr>
        </table>

        <p style="margin-top: 24px;">
            <a href="{{ url('/admin/bookings/'.$inquiry->id.'/edit') }}"
               style="display: inline-block; background: #3b82f6; color: white; text-decoration: none; padding: 10px 16px; border-radius: 6px;">
                Open booking
            </a>
        </p>
    @endif

    <p style="color: #9ca3af; font-size: 11px; margin-top: 40px; border-top: 1px solid #e5e7eb; padding-top: 16px;">
        Set by {{ $reminder->createdByUser?->name ?? 'system' }} · {{ $reminder->created_at->format('M j, H:i') }}
    </p>
</body>
</html>
