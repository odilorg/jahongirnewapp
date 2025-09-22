<?php

if (!function_exists('__c')) {
    /**
     * Get a translation for cash management terms
     */
    function __c(string $key, array $replace = [], ?string $locale = null): string
    {
        return __("cash.{$key}", $replace, $locale);
    }
}

if (!function_exists('getCurrentLocale')) {
    /**
     * Get the current application locale
     */
    function getCurrentLocale(): string
    {
        return app()->getLocale();
    }
}

if (!function_exists('getSupportedLocales')) {
    /**
     * Get list of supported locales
     */
    function getSupportedLocales(): array
    {
        return ['en', 'ru', 'uz'];
    }
}

if (!function_exists('getLocaleName')) {
    /**
     * Get the display name for a locale
     */
    function getLocaleName(string $locale): string
    {
        return match ($locale) {
            'en' => 'English',
            'ru' => 'Русский',
            'uz' => 'O\'zbekcha',
            default => $locale,
        };
    }
}

if (!function_exists('getLocaleFlag')) {
    /**
     * Get the flag emoji for a locale
     */
    function getLocaleFlag(string $locale): string
    {
        return match ($locale) {
            'en' => '🇺🇸',
            'ru' => '🇷🇺',
            'uz' => '🇺🇿',
            default => '🌐',
        };
    }
}
