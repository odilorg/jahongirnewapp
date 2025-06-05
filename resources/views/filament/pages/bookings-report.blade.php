{{-- resources/views/filament/pages/bookings-report.blade.php --}}
<x-filament::page>
    <x-slot name="header">
        <h1 class="text-2xl font-semibold tracking-tight">
            Bookings Report
        </h1>
    </x-slot>

    {{-- ───────────────────────── FORM ───────────────────────── --}}
    <x-filament::card class="mb-6">
        {{ $this->form }}

        {{-- “Apply” button triggers Livewire’s submit() --}}
        <x-filament::button
            type="submit"
            form="livewire-form"                {{-- Filament gives the form this ID --}}
            class="mt-4"
            icon="heroicon-o-check"
        >
            Apply
        </x-filament::button>
    </x-filament::card>

    {{-- ───────────────────────── TABLE ──────────────────────── --}}
    {{ $this->table }}
</x-filament::page>
