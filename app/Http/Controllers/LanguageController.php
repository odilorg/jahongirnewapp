<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\App;

class LanguageController extends Controller
{
    public function switch(Request $request)
    {
        $locale = $request->input('locale');
        $returnUrl = $request->input('return_url');
        $supportedLocales = ['en', 'ru', 'uz'];
        
        if (in_array($locale, $supportedLocales)) {
            Session::put('locale', $locale);
            App::setLocale($locale);
        }
        
        // If return_url is provided and valid, redirect there
        if ($returnUrl && filter_var($returnUrl, FILTER_VALIDATE_URL, FILTER_FLAG_PATH_REQUIRED)) {
            // Ensure it's a local URL (security check)
            $parsedUrl = parse_url($returnUrl);
            if ($parsedUrl && isset($parsedUrl['host']) && 
                ($parsedUrl['host'] === request()->getHost() || $parsedUrl['host'] === '127.0.0.1')) {
                return redirect($returnUrl);
            }
        }
        
        // Fallback to previous page or dashboard
        return redirect()->back();
    }
}