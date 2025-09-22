<x-filament-widgets::widget>
    <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-3">
        <div class="flex items-center justify-between">
            <div class="flex items-center space-x-2">
                <svg class="w-4 h-4 text-gray-500 dark:text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5h12M9 3v2m1.048 9.5A18.022 18.022 0 016.412 9m6.088 9h7M11 21l5-10 5 10M12.751 5C11.783 10.77 8.07 15.61 3 18.129"></path>
                </svg>
                <span class="text-sm font-medium text-gray-700 dark:text-gray-300">
                    {{ __c('language') }}
                </span>
            </div>
            
            <div class="flex items-center space-x-1">
                @foreach($this->getViewData()['languages'] as $lang)
                    <form method="POST" action="{{ route('language.switch') }}" class="inline">
                        @csrf
                        <input type="hidden" name="locale" value="{{ $lang['code'] }}">
                        <input type="hidden" name="return_url" value="{{ request()->fullUrl() }}">
                        <button
                            type="submit"
                            class="flex items-center space-x-1 px-2 py-1 rounded text-xs font-medium transition-colors duration-200 {{ $this->currentLocale === $lang['code'] ? 'bg-primary-100 text-primary-700 dark:bg-primary-900 dark:text-primary-300' : 'text-gray-600 hover:text-gray-900 dark:text-gray-400 dark:hover:text-gray-100 hover:bg-gray-100 dark:hover:bg-gray-700' }}"
                            title="{{ $lang['name'] }}"
                        >
                            <span class="text-sm">{{ $lang['flag'] }}</span>
                            <span>{{ $lang['code'] }}</span>
                        </button>
                    </form>
                @endforeach
            </div>
        </div>
    </div>
</x-filament-widgets::widget>
