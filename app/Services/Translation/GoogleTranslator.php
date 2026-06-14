<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** Google Cloud Translation v2 (API-key). Set services.translation.key. */
class GoogleTranslator implements Translator
{
    public function translate(string $text, string $to, string $from = 'en'): string
    {
        try {
            $res = Http::withOptions(['verify' => app_ca()])->timeout(8)->asForm()->post('https://translation.googleapis.com/language/translate/v2', [
                'key' => config('services.translation.key'),
                'q' => $text,
                'source' => $from,
                'target' => $to,
                'format' => 'html',
            ])->throw()->json();

            return $res['data']['translations'][0]['translatedText'] ?? $text;
        } catch (\Throwable $e) {
            Log::warning('Google translate failed: ' . $e->getMessage());

            return $text;
        }
    }
}
