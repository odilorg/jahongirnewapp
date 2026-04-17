<x-filament-panels::page>

    {{-- Summary bar --}}
    <div style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 160px; background: #fef3c7; border-radius: 8px; padding: 12px;">
            <div style="font-size: 11px; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px;">Total Quoted</div>
            <div style="font-size: 20px; font-weight: 700; color: #92400e;">${{ number_format($totalQuoted, 2) }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: #dcfce7; border-radius: 8px; padding: 12px;">
            <div style="font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">Total Received</div>
            <div style="font-size: 20px; font-weight: 700; color: #166534;">${{ number_format($totalReceived, 2) }}</div>
        </div>
        <div style="flex: 1; min-width: 160px; background: {{ $totalOutstanding > 0 ? '#fee2e2' : '#dcfce7' }}; border-radius: 8px; padding: 12px;">
            <div style="font-size: 11px; color: {{ $totalOutstanding > 0 ? '#991b1b' : '#166534' }}; text-transform: uppercase; letter-spacing: 0.5px;">Outstanding ({{ $unpaidCount }} bookings)</div>
            <div style="font-size: 20px; font-weight: 700; color: {{ $totalOutstanding > 0 ? '#dc2626' : '#16a34a' }};">${{ number_format($totalOutstanding, 2) }}</div>
        </div>
    </div>

    {{-- Filter toggle --}}
    <div style="margin-bottom: 12px;">
        <button type="button" wire:click="toggleShowSettled"
            style="font-size: 12px; padding: 6px 12px; border-radius: 6px; {{ $showSettled ? 'background:#3b82f6;color:white;' : 'background:#e5e7eb;color:#374151;' }}">
            {{ $showSettled ? '✓ Showing all' : 'Show settled bookings' }}
        </button>
    </div>

    {{-- Balances table --}}
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 bg-white dark:bg-gray-900">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: rgba(0,0,0,0.03);">
                    <th style="text-align: left; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Booking</th>
                    <th style="text-align: left; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Guest</th>
                    <th style="text-align: left; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Travel</th>
                    <th style="text-align: right; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Quoted</th>
                    <th style="text-align: right; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Received</th>
                    <th style="text-align: right; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Outstanding</th>
                    <th style="text-align: center; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Age</th>
                    <th style="padding: 10px 14px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($bookings as $b)
                    <tr style="border-top: 1px solid rgba(0,0,0,0.06);">
                        <td style="padding: 10px 14px; font-weight: 500;">
                            <a href="{{ $b['edit_url'] }}" target="_blank" style="color: #3b82f6; text-decoration: none;">
                                {{ $b['reference'] }}
                            </a>
                            <div style="font-size: 10px; color: #9ca3af; text-transform: uppercase; margin-top: 1px;">{{ $b['source'] }}</div>
                        </td>
                        <td style="padding: 10px 14px;">
                            {{ $b['customer'] }}
                            @if ($b['phone'])
                                <div style="font-size: 10px; color: #9ca3af;">{{ \App\Support\PhoneFormatter::format($b['phone']) }}</div>
                            @endif
                        </td>
                        <td style="padding: 10px 14px; color: #6b7280;">
                            {{ $b['travel_date']?->format('M j, Y') ?? '—' }}
                        </td>
                        <td style="padding: 10px 14px; text-align: right; color: #92400e;">${{ number_format($b['quoted'], 2) }}</td>
                        <td style="padding: 10px 14px; text-align: right; color: #16a34a;">${{ number_format($b['received'], 2) }}</td>
                        <td style="padding: 10px 14px; text-align: right; font-weight: 600; color: {{ $b['outstanding'] > 0.01 ? '#dc2626' : '#16a34a' }};">
                            ${{ number_format($b['outstanding'], 2) }}
                            @if ($b['outstanding'] <= 0.01) ✅ @endif
                        </td>
                        <td style="padding: 10px 14px; text-align: center;">
                            @if ($b['age_label'] !== '—')
                                <span style="font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 4px; background: rgba(0,0,0,0.03); color: {{ $b['age_color'] }};">
                                    {{ $b['age_label'] }}
                                </span>
                            @else
                                <span style="color: #9ca3af;">—</span>
                            @endif
                        </td>
                        <td style="padding: 10px 14px; white-space: nowrap;">
                            @if ($b['outstanding'] > 0.01)
                                <a href="{{ $b['edit_url'] }}" target="_blank" style="font-size: 11px; color: #16a34a; text-decoration: none; font-weight: 500;">
                                    💰 Pay
                                </a>
                                @if ($b['wa_phone'] && $b['payment_link'])
                                    <a href="https://wa.me/{{ $b['wa_phone'] }}?text={{ rawurlencode("Hi {$b['customer']}, just a friendly reminder — you have \${$b['outstanding']} outstanding for your booking {$b['reference']}. Payment link: {$b['payment_link']}") }}"
                                        target="_blank"
                                        style="font-size: 11px; color: #25d366; text-decoration: none; font-weight: 500; margin-left: 8px;">
                                        📱 Remind
                                    </a>
                                @elseif ($b['wa_phone'])
                                    <a href="https://wa.me/{{ $b['wa_phone'] }}?text={{ rawurlencode("Hi {$b['customer']}, just a friendly reminder — you have \${$b['outstanding']} outstanding for your booking {$b['reference']}.") }}"
                                        target="_blank"
                                        style="font-size: 11px; color: #25d366; text-decoration: none; font-weight: 500; margin-left: 8px;">
                                        📱 Remind
                                    </a>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" style="padding: 24px; text-align: center; color: #9ca3af;">
                            No outstanding balances — all bookings settled 🎉
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-filament-panels::page>
