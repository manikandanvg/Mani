<?php

namespace App\Services\Translation;

/** No-op driver (default when no API is configured): returns the source text. */
class EchoTranslator implements Translator
{
    public function translate(string $text, string $to, string $from = 'en'): string
    {
        return $text;
    }
}
