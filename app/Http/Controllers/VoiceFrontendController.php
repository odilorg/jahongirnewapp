<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class VoiceFrontendController extends Controller
{
    public function show()
    {
        return view('voice.agent');
    }
}
