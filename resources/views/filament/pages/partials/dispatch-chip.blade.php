{{--
    Dispatch state chip for a supplier block (driver / guide / accommodation stay).

    Required props:
      - $dispatchedAt      : ?Carbon  (e.g. $inquiry->driver_dispatched_at, $stay->dispatched_at)
      - $inquiryUpdatedAt  : ?Carbon  (the record whose changes imply "re-dispatch needed"
                                       — for driver/guide use $inquiry->updated_at, for
                                       stays use $stay->updated_at for lower noise)

    Rendering:
      - null dispatchedAt                       → amber "⏳ Not dispatched yet"
      - dispatchedAt present, updated_at newer  → red "⚠ Re-dispatch needed — changes since …"
      - dispatchedAt present, no changes after  → green "✅ Dispatched …"
--}}
@php
    $stale = $dispatchedAt && $inquiryUpdatedAt && $inquiryUpdatedAt->gt($dispatchedAt);
@endphp

@if (! $dispatchedAt)
    <span style="display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 3px; background: #fef3c7; color: #92400e; margin-top: 3px;">
        ⏳ Not dispatched yet
    </span>
@elseif ($stale)
    <span title="Last dispatch: {{ $dispatchedAt->format('Y-m-d H:i') }} · Last edit: {{ $inquiryUpdatedAt->format('Y-m-d H:i') }}"
        style="display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 3px; background: #fee2e2; color: #991b1b; margin-top: 3px;">
        ⚠ Re-dispatch — changes since {{ $dispatchedAt->format('M j H:i') }}
    </span>
@else
    <span title="Dispatched {{ $dispatchedAt->format('Y-m-d H:i') }}"
        style="display: inline-block; font-size: 10px; padding: 1px 6px; border-radius: 3px; background: #dcfce7; color: #166534; margin-top: 3px;">
        ✅ Dispatched {{ $dispatchedAt->format('M j H:i') }}
    </span>
@endif
