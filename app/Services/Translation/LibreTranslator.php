<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/** LibreTranslate (self-hostable / open-source). Set services.translation.endpoint (+ optional key). */
class LibreTranslator implements Translator
{
    public function translate(string $text, string $to, string $from = 'en'): string
    {
        try {
            $payload = ['q' => $text, 'source' => $from, 'target' => $to, 'format' => 'html'];
            if ($key = config('services.translation.key')) {
                $payload['api_key'] = $key;
            }
            $endpoint = rtrim((string) config('services.translation.endpoint'), '/') . '/translate';
            $res = Http::withOptions(['verify' => app_ca()])->timeout(8)->post($endpoint, $payload)->throw()->json();

            return $res['translatedText'] ?? $text;
        } catch (\Throwable $e) {
            Log::warning('Libre translate failed: ' . $e->getMessage());

            return $text;
        }
    }
}
