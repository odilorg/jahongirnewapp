<x-filament-panels::page>
    <div class="max-w-2xl mx-auto">
        <x-filament::section>
            <x-slot name="heading">
                Start New Shift
            </x-slot>

            <x-slot name="description">
                Begin a new cashier shift on a selected drawer.
            </x-slot>

            <form wire:submit="startShift">
                {{ $this->form }}

                <div class="flex justify-end gap-2 mt-6">
                    <x-filament::button
                        type="button"
                        color="gray"
                        href="{{ route('filament.admin.resources.cashier-shifts.index') }}"
                    >
                        Cancel
                    </x-filament::button>
                    
                    <x-filament::button
                        type="submit"
                        color="primary"
                        icon="heroicon-o-play"
                    >
                        Start Shift
                    </x-filament::button>
                </div>
            </form>
        </x-filament::section>
    </div>
</x-filament-panels::page>