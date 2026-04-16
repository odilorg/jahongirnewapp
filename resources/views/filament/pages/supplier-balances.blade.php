<x-filament-panels::page>

    {{-- Summary bar --}}
    <div style="display: flex; gap: 16px; margin-bottom: 20px; flex-wrap: wrap;">
        <div style="flex: 1; min-width: 150px; background: #fef3c7; border-radius: 8px; padding: 12px;">
            <div style="font-size: 11px; color: #92400e; text-transform: uppercase; letter-spacing: 0.5px;">Total Owed</div>
            <div style="font-size: 20px; font-weight: 700; color: #92400e;">${{ number_format($totalOwed, 2) }}</div>
        </div>
        <div style="flex: 1; min-width: 150px; background: #dcfce7; border-radius: 8px; padding: 12px;">
            <div style="font-size: 11px; color: #166534; text-transform: uppercase; letter-spacing: 0.5px;">Total Paid</div>
            <div style="font-size: 20px; font-weight: 700; color: #166534;">${{ number_format($totalPaid, 2) }}</div>
        </div>
        <div style="flex: 1; min-width: 150px; background: {{ $totalBalance > 0 ? '#fee2e2' : '#dcfce7' }}; border-radius: 8px; padding: 12px;">
            <div style="font-size: 11px; color: {{ $totalBalance > 0 ? '#991b1b' : '#166534' }}; text-transform: uppercase; letter-spacing: 0.5px;">Outstanding</div>
            <div style="font-size: 20px; font-weight: 700; color: {{ $totalBalance > 0 ? '#dc2626' : '#16a34a' }};">${{ number_format($totalBalance, 2) }}</div>
        </div>
    </div>

    {{-- Balances table --}}
    <div class="overflow-x-auto rounded-lg ring-1 ring-gray-200 dark:ring-gray-700 bg-white dark:bg-gray-900">
        <table style="width: 100%; border-collapse: collapse; font-size: 13px;">
            <thead>
                <tr style="background: rgba(0,0,0,0.03);">
                    <th style="text-align: left; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Supplier</th>
                    <th style="text-align: left; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Type</th>
                    <th style="text-align: right; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Owed</th>
                    <th style="text-align: right; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Paid</th>
                    <th style="text-align: right; padding: 10px 14px; font-weight: 600; font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px; color: #6b7280;">Outstanding</th>
                    <th style="padding: 10px 14px;"></th>
                </tr>
            </thead>
            <tbody>
                @forelse ($suppliers as $s)
                    <tr style="border-top: 1px solid rgba(0,0,0,0.06);">
                        <td style="padding: 10px 14px; font-weight: 500;">{{ $s['name'] }}</td>
                        <td style="padding: 10px 14px;">
                            <span style="font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 4px;
                                {{ $s['type'] === 'Driver' ? 'background:#dbeafe;color:#1e40af;' : ($s['type'] === 'Guide' ? 'background:#e0e7ff;color:#3730a3;' : 'background:#dcfce7;color:#166534;') }}">
                                {{ $s['type'] }}
                            </span>
                        </td>
                        <td style="padding: 10px 14px; text-align: right; color: #92400e;">${{ number_format($s['owed'], 2) }}</td>
                        <td style="padding: 10px 14px; text-align: right; color: #16a34a;">${{ number_format($s['paid'], 2) }}</td>
                        <td style="padding: 10px 14px; text-align: right; font-weight: 600; color: {{ $s['balance'] > 0 ? '#dc2626' : '#16a34a' }};">
                            ${{ number_format($s['balance'], 2) }}
                        </td>
                        <td style="padding: 10px 14px;">
                            <a href="{{ $s['edit_url'] }}" target="_blank" style="font-size: 11px; color: #3b82f6; text-decoration: none;">
                                Pay →
                            </a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" style="padding: 24px; text-align: center; color: #9ca3af;">
                            No suppliers with outstanding balances.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

</x-filament-panels::page>
