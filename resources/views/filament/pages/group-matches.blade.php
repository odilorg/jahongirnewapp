<x-filament-panels::page>

    {{-- Summary --}}
    <div style="display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 20px;">
        <div style="flex: 1; min-width: 160px; background: #ede9fe; border-radius: 8px; padding: 12px;">
            <div style="font-size: 10px; color: #5b21b6; text-transform: uppercase; letter-spacing: 0.5px;">Potential Clusters</div>
            <div style="font-size: 22px; font-weight: 700; color: #7c3aed;">{{ $count }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: #fef3c7; border-radius: 8px; padding: 12px;">
            <div style="font-size: 10px; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px;">Current Revenue</div>
            <div style="font-size: 22px; font-weight: 700; color: #92400e;">${{ number_format($total_current, 0) }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: #dcfce7; border-radius: 8px; padding: 12px;">
            <div style="font-size: 10px; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">If Grouped</div>
            <div style="font-size: 22px; font-weight: 700; color: #16a34a;">${{ number_format($total_potential, 0) }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: {{ $total_potential_uplift > 0 ? '#d1fae5' : '#fee2e2' }}; border-radius: 8px; padding: 12px;">
            <div style="font-size: 10px; color: {{ $total_potential_uplift > 0 ? '#065f46' : '#991b1b' }}; text-transform: uppercase; letter-spacing: 0.5px;">Uplift vs Current</div>
            <div style="font-size: 22px; font-weight: 700; color: {{ $total_potential_uplift > 0 ? '#059669' : '#dc2626' }};">
                {{ $total_potential_uplift >= 0 ? '+' : '' }}${{ number_format($total_potential_uplift, 0) }}
            </div>
        </div>
    </div>

    @if ($count === 0)
        <div style="text-align: center; padding: 36px 20px; color: #6b7280; background: white; border-radius: 8px; border: 1px dashed #d1d5db;">
            <div style="font-size: 36px; margin-bottom: 8px;">🔍</div>
            <div style="font-weight: 600; color: #374151; margin-bottom: 8px;">No matching opportunities right now.</div>
            <div style="font-size: 13px; max-width: 520px; margin: 0 auto 10px;">
                This is normal. Matches appear when multiple travelers request the same tour, same direction, around similar dates.
            </div>
            <div style="font-size: 12px; color: #9ca3af; background: #f9fafb; padding: 8px 14px; border-radius: 4px; display: inline-block; margin-top: 8px;">
                💡 Check this page when the dispatch board's <strong>🎯 Group Matches</strong> counter goes above 0.
            </div>
            <div style="font-size: 11px; color: #9ca3af; margin-top: 14px;">
                Matching window: ±{{ config('matching.window_days', 2) }} days · Max pax: {{ config('matching.max_pax', 8) }} · Min lead time: {{ config('matching.min_days_before_travel', 2) }} days
            </div>
        </div>
    @endif

    @foreach ($clusters as $i => $c)
        @php
            $isUrgent = $c['urgency_score'] >= -3; // within 3 days
            $borderColor = $isUrgent ? '#dc2626' : '#7c3aed';
            $bgColor     = $isUrgent ? '#fef2f2' : '#faf5ff';
        @endphp

        <div style="background: {{ $bgColor }}; border-left: 4px solid {{ $borderColor }}; border-radius: 6px; padding: 14px; margin-bottom: 14px;">

            {{-- Header --}}
            <div style="display: flex; justify-content: space-between; align-items: flex-start; flex-wrap: wrap; gap: 8px; margin-bottom: 10px;">
                <div>
                    <div style="font-size: 11px; color: #6b7280; text-transform: uppercase; letter-spacing: 0.5px;">
                        Match {{ $i + 1 }}
                        @if ($c['confidence'] === 'exact')
                            · 🟢 Same day
                        @else
                            · 🟡 ±{{ config('matching.window_days', 2) }}d flexible
                        @endif
                        @if ($isUrgent) · 🚨 URGENT @endif
                    </div>
                    <div style="font-size: 15px; font-weight: 700; margin-top: 2px;">
                        {{ $c['tour_name'] }}
                        @if ($c['direction']) · {{ $c['direction'] }} @endif
                    </div>
                    <div style="font-size: 12px; color: #6b7280; margin-top: 2px;">
                        🗓 {{ $c['earliest_label'] }} · 👥 {{ $c['total_pax'] }} pax combined
                    </div>
                </div>
            </div>

            {{-- Members --}}
            <div style="margin-bottom: 12px;">
                @foreach ($c['members'] as $m)
                    <div style="background: white; border-radius: 4px; padding: 8px 10px; margin-bottom: 4px; display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px;">
                        <div style="font-size: 13px;">
                            <strong>{{ $m['customer_name'] }}</strong>
                            <span style="font-size: 10px; color: #6b7280; text-transform: uppercase; margin-left: 6px;">{{ str_replace('_', ' ', $m['status']) }}</span>
                            <span style="font-size: 10px; color: #6b7280; margin-left: 4px;">·</span>
                            <span style="font-size: 12px; color: #374151; margin-left: 4px;">{{ $m['pax'] }}pax · {{ $m['travel_label'] }} · ${{ number_format($m['price_quoted'], 0) }}</span>
                        </div>
                        <div style="display: flex; gap: 6px; align-items: center;">
                            @if ($m['wa_phone'])
                                @php
                                    $msg = \App\Services\GroupMatchingEngine::buildWhatsAppMessage(
                                        $m['customer_name'],
                                        $c['group_rate_per_pp'],
                                        $c['earliest_label']
                                    );
                                @endphp
                                <a href="https://wa.me/{{ $m['wa_phone'] }}?text={{ rawurlencode($msg) }}"
                                    target="_blank"
                                    style="font-size: 11px; background: #16a34a; color: white; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-weight: 500;">
                                    💬 Propose group
                                </a>
                            @endif
                            <a href="{{ url('/admin/booking-inquiries/' . $m['id'] . '/edit') }}"
                                target="_blank"
                                style="font-size: 11px; background: #e5e7eb; color: #374151; padding: 4px 8px; border-radius: 4px; text-decoration: none; font-weight: 500;">
                                Open
                            </a>
                        </div>
                    </div>
                @endforeach
            </div>

            {{-- Revenue breakdown --}}
            <div style="background: rgba(0,0,0,0.04); border-radius: 4px; padding: 8px 10px; font-size: 12px;">
                <div style="display: flex; justify-content: space-between;">
                    <span>Current revenue (private quotes)</span>
                    <span style="color: #92400e;">${{ number_format($c['current_revenue'], 0) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>If grouped: ${{ $c['group_rate_per_pp'] }}/pp × {{ $c['total_pax'] }}</span>
                    <span style="color: #16a34a;">${{ number_format($c['estimated_revenue'], 0) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between;">
                    <span>Estimated tour cost</span>
                    <span style="color: #6b7280;">-${{ number_format($c['estimated_cost'], 0) }}</span>
                </div>
                <div style="display: flex; justify-content: space-between; font-weight: 700; padding-top: 4px; border-top: 1px solid rgba(0,0,0,0.1); margin-top: 4px;">
                    <span>Estimated margin</span>
                    <span style="color: {{ $c['estimated_margin'] > 0 ? '#059669' : '#dc2626' }};">
                        ${{ number_format($c['estimated_margin'], 0) }} ({{ $c['estimated_margin_pct'] }}%)
                    </span>
                </div>
            </div>
        </div>
    @endforeach

</x-filament-panels::page>
