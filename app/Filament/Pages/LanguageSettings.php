<?php

namespace App\Filament\Pages;

use Filament\Pages\Page;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\App;
use Filament\Notifications\Notification;

class LanguageSettings extends Page
{
    protected static ?string $navigationIcon = 'heroicon-o-language';
    
    protected static string $view = 'filament.pages.language-settings';
    
    protected static ?string $title = 'Language Settings';
    
    protected static ?string $navigationLabel = 'Language';
    
    protected static ?string $navigationGroup = 'Settings';
    
    protected static ?int $navigationSort = 999;
    
    public $currentLocale = 'en';
    
    public function mount(): void
    {
        $this->currentLocale = Session::get('locale', 'en');
    }
    
    public function switchLanguage(string $locale): void
    {
        $supportedLocales = ['en', 'ru', 'uz'];
        
        if (in_array($locale, $supportedLocales)) {
            Session::put('locale', $locale);
            App::setLocale($locale);
            $this->currentLocale = $locale;
            
            Notification::make()
                ->title('Language Changed')
                ->body('Language has been changed to ' . $this->getLanguageName($locale))
                ->success()
                ->send();
        }
    }
    
    public function getLanguageName(string $locale): string
    {
        return match ($locale) {
            'en' => 'English',
            'ru' => 'Русский',
            'uz' => 'O\'zbekcha',
            default => $locale,
        };
    }
    
    public function getLanguages(): array
    {
        return [
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
        ];
    }
}