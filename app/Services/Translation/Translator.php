<?php

namespace App\Services\Translation;

/**
 * Provider-agnostic machine translation. Swap drivers via config('services.translation.driver')
 * (echo | mymemory | google | libre) without touching callers. Implementations must
 * NEVER throw on transport errors — return the source text so rendering is unaffected.
 */
interface Translator
{
    public function translate(string $text, string $to, string $from = 'en'): string;
}
