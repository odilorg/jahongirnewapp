<x-filament-panels::page>
    <div class="max-w-4xl mx-auto">
        <div class="bg-white dark:bg-gray-800 rounded-lg border border-gray-200 dark:border-gray-700 p-6">
            <div class="mb-6">
                <h2 class="text-lg font-semibold text-gray-900 dark:text-gray-100 mb-2">
                    {{ __c('language') }} Settings
                </h2>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    Choose your preferred language for the application interface.
                </p>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                @foreach($this->getLanguages() as $lang)
                    <div class="relative">
                        <button
                            wire:click="switchLanguage('{{ $lang['code'] }}')"
                            class="w-full p-4 rounded-lg border-2 transition-all duration-200 {{ $currentLocale === $lang['code'] ? 'border-primary-500 bg-primary-50 dark:bg-primary-900/20' : 'border-gray-200 dark:border-gray-700 hover:border-gray-300 dark:hover:border-gray-600' }}"
                        >
                            <div class="flex items-center space-x-3">
                                <span class="text-2xl">{{ $lang['flag'] }}</span>
                                <div class="text-left">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">
                                        {{ $lang['name'] }}
                                    </div>
                                    <div class="text-sm text-gray-500 dark:text-gray-400">
                                        {{ strtoupper($lang['code']) }}
                                    </div>
                                </div>
                                @if($currentLocale === $lang['code'])
                                    <div class="ml-auto">
                                        <svg class="w-5 h-5 text-primary-600" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 010 1.414l-8 8a1 1 0 01-1.414 0l-4-4a1 1 0 011.414-1.414L8 12.586l7.293-7.293a1 1 0 011.414 0z" clip-rule="evenodd"></path>
                                        </svg>
                                    </div>
                                @endif
                            </div>
                        </button>
                    </div>
                @endforeach
            </div>
            
            <div class="mt-6 p-4 bg-gray-50 dark:bg-gray-700 rounded-lg">
                <h3 class="font-medium text-gray-900 dark:text-gray-100 mb-2">
                    Current Language
                </h3>
                <p class="text-sm text-gray-600 dark:text-gray-400">
                    You are currently using: <strong>{{ $this->getLanguageName($currentLocale) }}</strong>
                </p>
            </div>
        </div>
    </div>
</x-filament-panels::page>