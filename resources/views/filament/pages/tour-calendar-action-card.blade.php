{{-- Phase 20 — Single action card with readiness chips + reason labels.
     R2: all view prep moved to TourCalendarBuilder::enrichActionChipForView.
     This partial is now pure rendering over the $c['view'] subarray. --}}
<div wire:click="openInquiry({{ $c['id'] }})"
    style="background: {{ $c['view']['zone_color'] }}; border-left: 4px solid {{ $c['view']['zone_border'] }}; border-radius: 6px; padding: 10px 12px; margin-bottom: 8px; cursor: pointer;">

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
            @if ($c['view']['tour_type_icon'])
                <span>{{ $c['view']['tour_type_icon'] }}</span>
            @endif
            <span style="{{ $c['view']['source_badge_style'] }}">
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
        <span style="{{ $c['view']['paid']['style'] }}">{{ $c['view']['paid']['label'] }}</span>
        <span style="{{ $c['view']['driver']['style'] }}">{{ $c['view']['driver']['label'] }}</span>
        <span style="{{ $c['view']['pickup']['style'] }}">{{ $c['view']['pickup']['label'] }}</span>
        @if ($c['view']['accommodation']['visible'])
            <span style="{{ $c['view']['accommodation']['style'] }}">{{ $c['view']['accommodation']['label'] }}</span>
        @endif
    </div>

    {{-- Action reasons (only if any) --}}
    @if (! empty($c['action_reasons']))
        <div style="margin-top: 6px; font-size: 11px; color: #dc2626; font-weight: 500;">
            ⚠ {{ implode(' · ', $c['action_reasons']) }}
        </div>
    @endif
</div>
