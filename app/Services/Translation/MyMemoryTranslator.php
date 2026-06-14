<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * MyMemory — keyless, free (rate-limited) translation API. Great for demo/testing of
 * Indian languages (ta, te, ml, hi, kn...). Add an email in config to raise the daily
 * limit. Returns source text on any error.
 */
class MyMemoryTranslator implements Translator
{
    public function translate(string $text, string $to, string $from = 'en'): string
    {
        try {
            $params = ['q' => $text, 'langpair' => "{$from}|{$to}"];
            if ($email = config('services.translation.email')) {
                $params['de'] = $email;
            }
            $res = Http::withOptions(['verify' => app_ca()])->timeout(8)
                ->get('https://api.mymemory.translated.net/get', $params)->throw()->json();
            $out = $res['responseData']['translatedText'] ?? null;

            // MyMemory sometimes returns quota/warning text in the field; guard it
            if ($out && ! str_contains(strtoupper($out), 'MYMEMORY WARNING') && stripos($out, 'QUERY LENGTH LIMIT') === false) {
                return html_entity_decode($out, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        } catch (\Throwable $e) {
            Log::warning('MyMemory translate failed: ' . $e->getMessage());
        }

        return $text;
    }
}
