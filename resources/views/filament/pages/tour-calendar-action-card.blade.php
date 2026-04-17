{{-- Phase 20 — Single action card with readiness chips + reason labels --}}
@php
    $zoneColor = match ($zone) {
        'urgent'  => '#fee2e2',
        'warning' => '#fef3c7',
        'ready'   => '#dcfce7',
        default   => '#f3f4f6',
    };
    $zoneBorder = match ($zone) {
        'urgent'  => '#dc2626',
        'warning' => '#d97706',
        'ready'   => '#16a34a',
        default   => '#d1d5db',
    };
    $rc = $c['readiness_chips'] ?? [];
@endphp

<div wire:click="openInquiry({{ $c['id'] }})"
    style="background: {{ $zoneColor }}; border-left: 4px solid {{ $zoneBorder }}; border-radius: 6px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer;">

    {{-- Top row: name, time/pax/date, badges --}}
    <div style="display: flex; justify-content: space-between; gap: 8px; align-items: baseline; flex-wrap: wrap;">
        <div style="font-weight: 600; color: #111827;">
            {{ $c['customer_name'] }}
            @if ($c['customer_country'])
                <span style="font-weight: 400; color: #6b7280; font-size: 12px;">({{ $c['customer_country'] }})</span>
            @endif
        </div>
        <div style="font-size: 11px; color: #374151; display: flex; gap: 6px; align-items: center;">
            <span>🕐 {{ $c['pickup_time'] ?? '—' }}</span>
            <span>·</span>
            <span>{{ $c['pax_label'] }} pax</span>
            <span>·</span>
            <span style="color: #6b7280;">{{ $c['travel_date_label'] }}</span>
            @if ($c['tour_type'])
                <span>{{ $c['tour_type'] === 'private' ? '👤' : '👥' }}</span>
            @endif
            <span style="font-size: 8px; font-weight: 700; padding: 1px 4px; border-radius: 3px;
                {{ $c['source_badge'] === 'GYG' ? 'background:#fed7aa;color:#9a3412;' : 'background:#dbeafe;color:#1e40af;' }}">
                {{ $c['source_badge'] }}
            </span>
            @if ($c['assigned_initials'])
                <span style="font-size: 8px; font-weight: 700; background: #e0e7ff; color: #3730a3; padding: 1px 4px; border-radius: 3px;">
                    {{ $c['assigned_initials'] }}
                </span>
            @endif
        </div>
    </div>

    {{-- Readiness chips --}}
    <div style="display: flex; gap: 8px; margin-top: 6px; flex-wrap: wrap; font-size: 11px;">
        @php
            $chipStyle = fn ($ok) => 'padding: 2px 6px; border-radius: 3px; font-weight: 500; '
                . ($ok === true ? 'background: #dcfce7; color: #166534;'
                : ($ok === 'dispatched' ? 'background: #dcfce7; color: #166534;'
                : ($ok === 'assigned' ? 'background: #fef3c7; color: #92400e;'
                : 'background: #fee2e2; color: #991b1b;')));
        @endphp
        <span style="{{ $chipStyle($rc['paid'] ?? false) }}">
            {{ ($rc['paid'] ?? false) ? '🟢 Paid' : '🔴 Unpaid' }}
        </span>
        <span style="{{ $chipStyle($rc['driver'] ?? 'missing') }}">
            @if (($rc['driver'] ?? 'missing') === 'dispatched')
                🟢 Driver: {{ $c['driver_name'] ?: '—' }}
            @elseif (($rc['driver'] ?? 'missing') === 'assigned')
                🟡 Driver assigned (not dispatched): {{ $c['driver_name'] ?: '—' }}
            @else
                🔴 No driver
            @endif
        </span>
        <span style="{{ $chipStyle($rc['pickup'] ?? false) }}">
            {{ ($rc['pickup'] ?? false) ? '🟢 Pickup: '.$c['pickup_point'] : '🔴 No pickup location' }}
        </span>

        {{-- Phase 20.8 — Accommodation chip mirrors backend dispatch state --}}
        @php $accState = $rc['accommodation'] ?? 'none'; @endphp
        @if ($accState !== 'none')
            @php
                $accAccommodations = $c['accommodations'] ?? [];
                $accLabel = implode(', ', $accAccommodations) ?: 'stay';
            @endphp
            <span style="{{ $chipStyle($accState) }}">
                @if ($accState === 'dispatched')
                    🟢 Stay: {{ $accLabel }}
                @elseif ($accState === 'assigned')
                    🟡 Stay assigned (not dispatched): {{ $accLabel }}
                @else
                    🔴 No accommodation
                @endif
            </span>
        @endif
    </div>

    {{-- Action reasons (only if any) --}}
    @if (! empty($c['action_reasons']))
        <div style="margin-top: 6px; font-size: 11px; color: #dc2626; font-weight: 500;">
            ⚠ {{ implode(' · ', $c['action_reasons']) }}
        </div>
    @endif
</div>
