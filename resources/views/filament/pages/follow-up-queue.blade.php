<x-filament-panels::page>
    <div class="space-y-6">
        @livewire('follow-up-queue.overdue')
        @livewire('follow-up-queue.no-followup')
        @livewire('follow-up-queue.due-today')
        @livewire('follow-up-queue.upcoming')
    </div>
</x-filament-panels::page>
