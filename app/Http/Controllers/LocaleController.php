<?php

namespace App\Http\Controllers;

use App\Models\Currency;
use App\Models\Language;
use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function language(Request $request)
    {
        $code = $request->input('code');
        if (Language::where('is_active', true)->where('code', $code)->exists()) {
            session(['locale' => $code]);
        }

        return back();
    }

    public function currency(Request $request)
    {
        $code = $request->input('code');
        if (Currency::where('is_active', true)->where('code', $code)->exists()) {
            session(['currency' => $code]);
        }

        return back();
    }
}
