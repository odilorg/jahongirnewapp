<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Daily recap</title>
</head>
<body style="font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; max-width: 640px; margin: 16px auto; padding: 0 20px; color: #111827; line-height: 1.5;">

    <div style="background: #1e40af; color: white; padding: 16px; border-radius: 8px; margin-bottom: 16px;">
        <div style="font-size: 12px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.8;">Tomorrow</div>
        <div style="font-size: 22px; font-weight: 700; margin-top: 2px;">{{ $d['date_label'] }}</div>
        <div style="margin-top: 8px;">
            @if ($d['total_bookings'] === 0)
                <span>No tours tomorrow</span>
            @else
                <span>{{ $d['total_bookings'] }} tour{{ $d['total_bookings'] === 1 ? '' : 's' }}</span>
                <span style="opacity: 0.6;">·</span>
                <span>${{ number_format($d['total_revenue'], 0) }}</span>
            @endif
        </div>
    </div>

    {{-- Needs Action --}}
    @if (! empty($d['needs_action']))
        <h3 style="color: #dc2626; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin: 20px 0 10px;">
            🚨 Needs Action ({{ count($d['needs_action']) }})
        </h3>
        @foreach ($d['needs_action'] as $b)
            <div style="background: #fee2e2; border-left: 4px solid #dc2626; padding: 12px 14px; border-radius: 6px; margin-bottom: 10px;">
                <div style="font-weight: 600; font-size: 15px;">{{ $b['customer'] }}</div>
                <div style="font-size: 13px; color: #6b7280; margin-top: 2px;">
                    🕐 {{ $b['pickup_time'] ? substr($b['pickup_time'], 0, 5) : '—' }}
                    · {{ $b['pax'] }} pax
                    · {{ strtoupper($b['source']) }}
                    @if ($b['tour_type']) · {{ ucfirst($b['tour_type']) }} @endif
                </div>
                <div style="font-size: 13px; color: #991b1b; margin-top: 6px; font-weight: 500;">
                    ⚠ {{ implode(', ', $b['reasons']) }}
                </div>
                @if ($baseUrl)
                    <div style="margin-top: 8px;">
                        <a href="{{ $baseUrl }}/admin/bookings/{{ $b['id'] }}/edit"
                           style="background: #dc2626; color: white; text-decoration: none; padding: 6px 12px; border-radius: 4px; font-size: 12px; font-weight: 500;">
                            Open booking →
                        </a>
                    </div>
                @endif
            </div>
        @endforeach
    @endif

    {{-- Ready --}}
    @if (! empty($d['ready']))
        <h3 style="color: #16a34a; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin: 20px 0 10px;">
            ✅ Ready ({{ count($d['ready']) }})
        </h3>
        @foreach ($d['ready'] as $b)
            <div style="background: #dcfce7; border-left: 4px solid #16a34a; padding: 10px 14px; border-radius: 6px; margin-bottom: 6px; font-size: 13px;">
                <strong>{{ $b['customer'] }}</strong>
                · {{ $b['pickup_time'] ? substr($b['pickup_time'], 0, 5) : '—' }}
                · {{ $b['pax'] }} pax
                @if ($b['driver_name']) · 🚗 {{ $b['driver_name'] }} @endif
                @if ($b['guide_name']) · 🧭 {{ $b['guide_name'] }} @endif
                @if (! empty($b['accommodations'])) · 🏕 {{ implode(', ', $b['accommodations']) }} @endif
            </div>
        @endforeach
    @endif

    {{-- Reminders --}}
    @if (! empty($d['reminders']))
        <h3 style="color: #7c3aed; font-size: 14px; text-transform: uppercase; letter-spacing: 1px; margin: 20px 0 10px;">
            ⏰ Reminders tomorrow ({{ count($d['reminders']) }})
        </h3>
        @foreach ($d['reminders'] as $r)
            <div style="background: #ede9fe; border-left: 4px solid #7c3aed; padding: 8px 14px; border-radius: 6px; margin-bottom: 4px; font-size: 13px;">
                <strong>{{ $r['time'] }}</strong> — {{ $r['message'] }}
                @if ($r['reference']) <span style="color: #6b7280; font-size: 11px;">({{ $r['reference'] }})</span> @endif
            </div>
        @endforeach
    @endif

    {{-- Week ahead --}}
    <div style="margin-top: 24px; padding: 12px 14px; background: #f3f4f6; border-radius: 6px; font-size: 13px; color: #374151;">
        📊 <strong>Week ahead:</strong> {{ $d['week_bookings'] }} tours · ${{ number_format($d['week_revenue'], 0) }}
    </div>

    <p style="color: #9ca3af; font-size: 11px; margin-top: 24px; text-align: center;">
        Sent by Jahongir Travel ops · {{ now()->format('M j, H:i') }}
    </p>
</body>
</html>
