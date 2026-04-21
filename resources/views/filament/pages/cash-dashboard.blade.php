<x-filament-panels::page>
    {{-- Stats widgets rendered by getHeaderWidgets() --}}

    <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mt-6">
        {{-- Pending Expense Approvals --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <x-heroicon-o-clock class="w-5 h-5 inline-block mr-1 text-amber-500" />
                Pending Expense Approvals
            </h3>
            @php
                $pendingExpenses = \App\Models\CashExpense::where('requires_approval', true)
                    ->whereNull('approved_at')
                    ->whereNull('rejected_at')
                    ->with(['creator', 'category'])
                    ->latest()
                    ->take(10)
                    ->get();
            @endphp

            @if($pendingExpenses->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No pending approvals</p>
            @else
                <div class="space-y-3">
                    @foreach($pendingExpenses as $expense)
                        <div class="flex items-center justify-between p-3 rounded-lg bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-700">
                            <div>
                                <p class="font-medium text-sm text-gray-900 dark:text-white">
                                    {{ $expense->currency ?? 'UZS' }} {{ number_format($expense->amount, 2) }}
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    {{ $expense->description }} &bull; {{ $expense->creator?->name ?? 'System' }}
                                </p>
                            </div>
                            <a href="{{ route('filament.admin.resources.cash-expenses.index', ['tableFilters[pending][isActive]' => true]) }}"
                               class="text-xs text-amber-600 hover:text-amber-800 dark:text-amber-400 font-medium">
                                Review &rarr;
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Reconciliation Alerts --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <x-heroicon-o-exclamation-triangle class="w-5 h-5 inline-block mr-1 text-red-500" />
                Reconciliation Alerts
            </h3>
            @php
                $unresolvedRecon = \App\Models\BookingPaymentReconciliation::whereNull('resolved_at')
                    ->where('status', '!=', 'matched')
                    ->latest()
                    ->take(10)
                    ->get();
            @endphp

            @if($unresolvedRecon->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">All payments reconciled</p>
            @else
                <div class="space-y-3">
                    @foreach($unresolvedRecon as $recon)
                        <div class="flex items-center justify-between p-3 rounded-lg {{ $recon->status === 'no_payment' ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700' : 'bg-amber-50 dark:bg-amber-900/20 border-amber-200 dark:border-amber-700' }} border">
                            <div>
                                <p class="font-medium text-sm text-gray-900 dark:text-white">
                                    Booking #{{ $recon->beds24_booking_id }}
                                    <span class="ml-2 inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                        {{ $recon->status === 'no_payment' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-amber-100 text-amber-800 dark:bg-amber-900 dark:text-amber-200' }}">
                                        {{ str_replace('_', ' ', ucfirst($recon->status)) }}
                                    </span>
                                </p>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Expected: ${{ number_format($recon->expected_amount, 2) }} &bull;
                                    Reported: ${{ number_format($recon->reported_amount, 2) }} &bull;
                                    Gap: ${{ number_format($recon->discrepancy_amount, 2) }}
                                </p>
                            </div>
                            <a href="{{ route('filament.admin.resources.booking-payment-reconciliations.index') }}"
                               class="text-xs text-red-600 hover:text-red-800 dark:text-red-400 font-medium">
                                Resolve &rarr;
                            </a>
                        </div>
                    @endforeach
                </div>
            @endif
        </div>

        {{-- Recent Transactions --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <x-heroicon-o-banknotes class="w-5 h-5 inline-block mr-1 text-blue-500" />
                Recent Transactions
            </h3>
            @php
                $recentTx = \App\Models\CashTransaction::with('creator')
                    ->latest('occurred_at')
                    ->take(10)
                    ->get();
            @endphp

            @if($recentTx->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No transactions yet</p>
            @else
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-left text-xs text-gray-500 dark:text-gray-400 border-b dark:border-gray-700">
                                <th class="pb-2">Date</th>
                                <th class="pb-2">Type</th>
                                <th class="pb-2">Amount</th>
                                <th class="pb-2">Category</th>
                                <th class="pb-2">By</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y dark:divide-gray-700">
                            @foreach($recentTx as $tx)
                                <tr>
                                    <td class="py-2 text-gray-600 dark:text-gray-300">{{ $tx->occurred_at?->format('d M H:i') ?? $tx->created_at->format('d M H:i') }}</td>
                                    <td class="py-2">
                                        <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium
                                            {{ $tx->type->value === 'in' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : ($tx->type->value === 'out' ? 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200') }}">
                                            {{ $tx->type->label() }}
                                        </span>
                                    </td>
                                    <td class="py-2 font-medium text-gray-900 dark:text-white">{{ $tx->currency->formatAmount($tx->amount) }}</td>
                                    <td class="py-2 text-gray-600 dark:text-gray-300">{{ $tx->category?->label() ?? '-' }}</td>
                                    <td class="py-2 text-gray-600 dark:text-gray-300">{{ $tx->creator?->name ?? 'System' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <div class="mt-3 text-right">
                    <a href="{{ route('filament.admin.resources.cash-transactions.index') }}"
                       class="text-xs text-primary-600 hover:text-primary-800 dark:text-primary-400 font-medium">
                        View all transactions &rarr;
                    </a>
                </div>
            @endif
        </div>

        {{-- Shift History --}}
        <div class="fi-section rounded-xl bg-white shadow-sm ring-1 ring-gray-950/5 dark:bg-gray-900 dark:ring-white/10 p-6">
            <h3 class="text-lg font-semibold text-gray-900 dark:text-white mb-4">
                <x-heroicon-o-arrow-path class="w-5 h-5 inline-block mr-1 text-purple-500" />
                Recent Shift Handovers
            </h3>
            @php
                $recentShifts = \App\Models\ShiftHandover::with('outgoingShift.user')
                    ->latest()
                    ->take(5)
                    ->get();
            @endphp

            @if($recentShifts->isEmpty())
                <p class="text-sm text-gray-500 dark:text-gray-400">No shift handovers yet</p>
            @else
                <div class="space-y-3">
                    @foreach($recentShifts as $handover)
                        @php
                            $hasDisc = $handover->hasDiscrepancy();
                            $uzsD = round($handover->counted_uzs - $handover->expected_uzs);
                            $usdD = round($handover->counted_usd - $handover->expected_usd, 2);
                        @endphp
                        <div class="p-3 rounded-lg border {{ $hasDisc ? 'bg-red-50 dark:bg-red-900/20 border-red-200 dark:border-red-700' : 'bg-green-50 dark:bg-green-900/20 border-green-200 dark:border-green-700' }}">
                            <div class="flex justify-between items-start">
                                <div>
                                    <p class="font-medium text-sm text-gray-900 dark:text-white">
                                        {{ $handover->outgoingShift?->user?->name ?? 'Unknown' }}
                                        &bull; {{ $handover->created_at->format('d M Y H:i') }}
                                    </p>
                                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">
                                        UZS: {{ number_format($handover->expected_uzs) }} → {{ number_format($handover->counted_uzs) }}
                                        @if(abs($uzsD) > 100)
                                            <span class="text-red-600 dark:text-red-400 font-medium">({{ $uzsD > 0 ? '+' : '' }}{{ number_format($uzsD) }})</span>
                                        @endif
                                        &nbsp;|&nbsp;
                                        USD: ${{ number_format($handover->expected_usd, 2) }} → ${{ number_format($handover->counted_usd, 2) }}
                                        @if(abs($usdD) > 0.5)
                                            <span class="text-red-600 dark:text-red-400 font-medium">({{ $usdD > 0 ? '+' : '' }}{{ number_format($usdD, 2) }})</span>
                                        @endif
                                    </p>
                                </div>
                                @if($hasDisc)
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200">
                                        Discrepancy
                                    </span>
                                @else
                                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200">
                                        Clean
                                    </span>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="mt-3 text-right">
                    <a href="{{ route('filament.admin.resources.shift-handovers.index') }}"
                       class="text-xs text-primary-600 hover:text-primary-800 dark:text-primary-400 font-medium">
                        View all handovers &rarr;
                    </a>
                </div>
            @endif
        </div>
    </div>

    {{-- Chart widget rendered by getFooterWidgets() --}}
</x-filament-panels::page>
