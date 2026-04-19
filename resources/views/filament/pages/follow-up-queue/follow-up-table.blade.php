<div>
    <x-filament::section :heading="$label">
        <x-slot name="description">
            @if ($color === 'danger')
                Act now.
            @elseif ($color === 'warning')
                Today's workload.
            @elseif ($color === 'info')
                Coming up — prep ahead.
            @else
                Leads with no next action.
            @endif
        </x-slot>

        {{ $this->table }}
    </x-filament::section>
</div>
