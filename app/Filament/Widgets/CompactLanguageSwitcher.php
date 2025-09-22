<?php

namespace App\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Session;

class CompactLanguageSwitcher extends Widget
{
    protected static string $view = 'filament.widgets.compact-language-switcher';
    
    protected int | string | array $columnSpan = 'full';
    
    protected static ?int $sort = 1; // Show near the top
    
    public $currentLocale = 'en';
    
    public function mount(): void
    {
        $this->currentLocale = Session::get('locale', 'en');
    }
    
    public function getViewData(): array
    {
        return [
            'currentLocale' => $this->currentLocale,
            'languages' => [
                'en' => [
                    'code' => 'en',
                    'name' => 'English',
                    'flag' => '🇺🇸',
                ],
                'ru' => [
                    'code' => 'ru',
                    'name' => 'Русский',
                    'flag' => '🇷🇺',
                ],
                'uz' => [
                    'code' => 'uz',
                    'name' => 'O\'zbekcha',
                    'flag' => '🇺🇿',
                ],
            ],
        ];
    }
}
