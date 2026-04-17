{{-- Phase 20 — Dispatch Board Action View --}}

@php
    $renderCard = function (array $c) {
        $rc = $c['readiness_chips'] ?? [];
        return compact('c', 'rc');
    };
@endphp

{{-- Unclaimed leads banner (top) --}}
@if (! empty($action['unclaimed']))
    <div style="background: #fef3c7; border: 1px solid #f59e0b; border-radius: 8px; padding: 10px 14px; margin-bottom: 16px;">
        <div style="font-size: 12px; font-weight: 600; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px;">
            📬 {{ $action['unclaimed_count'] }} Unclaimed Lead{{ $action['unclaimed_count'] === 1 ? '' : 's' }}
        </div>
        @foreach ($action['unclaimed'] as $lead)
            <div style="display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 13px; color: #78350f;">
                <span wire:click="openInquiry({{ $lead['id'] }})" style="cursor: pointer; text-decoration: underline; font-weight: 500;">
                    {{ $lead['customer_name'] }}
                </span>
                <span style="font-size: 10px; background: rgba(0,0,0,0.08); padding: 2px 6px; border-radius: 3px; text-transform: uppercase;">{{ $lead['source'] }}</span>
                <span style="color: #92400e; font-size: 11px;">{{ $lead['age'] }}</span>
            </div>
        @endforeach
    </div>
@endif

{{-- Zone 1: 🚨 Needs Action Today --}}
@if (! empty($action['needs_action_today']))
    <div style="margin-bottom: 18px;">
        <div style="font-size: 13px; font-weight: 700; color: #dc2626; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
            🚨 Needs Action Today ({{ count($action['needs_action_today']) }})
        </div>
        @foreach ($action['needs_action_today'] as $c)
            @include('filament.pages.tour-calendar-action-card', ['c' => $c, 'zone' => 'urgent'])
        @endforeach
    </div>
@endif

{{-- Zone 2: ⚠️ Tomorrow's Prep --}}
@if (! empty($action['tomorrow_prep']))
    <div style="margin-bottom: 18px;">
        <div style="font-size: 13px; font-weight: 700; color: #d97706; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
            ⚠️ Tomorrow Prep ({{ count($action['tomorrow_prep']) }})
        </div>
        @foreach ($action['tomorrow_prep'] as $c)
            @include('filament.pages.tour-calendar-action-card', ['c' => $c, 'zone' => 'warning'])
        @endforeach
    </div>
@endif

{{-- Zone 3: ✅ Ready --}}
@if (! empty($action['ready']))
    <div style="margin-bottom: 18px;">
        <div style="font-size: 13px; font-weight: 700; color: #16a34a; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 0.5px;">
            ✅ Ready ({{ count($action['ready']) }})
        </div>
        @foreach ($action['ready'] as $c)
            @include('filament.pages.tour-calendar-action-card', ['c' => $c, 'zone' => 'ready'])
        @endforeach
    </div>
@endif

{{-- Empty state --}}
@if (empty($action['needs_action_today']) && empty($action['tomorrow_prep']) && empty($action['ready']))
    <div style="text-align: center; padding: 40px; color: #9ca3af;">
        <div style="font-size: 36px; margin-bottom: 8px;">🌴</div>
        <div>No active tours in the next 7 days.</div>
    </div>
@endif
