<x-filament-panels::page>
    <div class="space-y-6">
        @livewire(\App\Filament\Pages\FollowUpQueue\OverdueFollowUpsTable::class)
        @livewire(\App\Filament\Pages\FollowUpQueue\LeadsWithoutFollowUpTable::class)
        @livewire(\App\Filament\Pages\FollowUpQueue\DueTodayFollowUpsTable::class)
        @livewire(\App\Filament\Pages\FollowUpQueue\UpcomingFollowUpsTable::class)
    </div>
</x-filament-panels::page>
