<?php

use App\Services\Translation\TranslationManager;

if (! function_exists('app_ca')) {
    /**
     * CA bundle for outbound HTTPS. Returns the app-bundled cacert.pem if present
     * (works around Windows PHP missing curl.cainfo), else true (use system default).
     */
    function app_ca(): string|bool
    {
        $path = storage_path('app/certs/cacert.pem');

        return is_file($path) ? $path : true;
    }
}

if (! function_exists('inr_words')) {
    /**
     * Indian-numbering amount in words (lakh / crore), e.g. 100000 → "One Lakh Rupees".
     * Rupees only (paise ignored), matching the legacy contract wording.
     */
    function inr_words(float $amount): string
    {
        $n = (int) round($amount);
        if ($n === 0) {
            return 'Zero Rupees';
        }

        $ones = ['', 'One', 'Two', 'Three', 'Four', 'Five', 'Six', 'Seven', 'Eight', 'Nine', 'Ten',
            'Eleven', 'Twelve', 'Thirteen', 'Fourteen', 'Fifteen', 'Sixteen', 'Seventeen', 'Eighteen', 'Nineteen'];
        $tens = ['', '', 'Twenty', 'Thirty', 'Forty', 'Fifty', 'Sixty', 'Seventy', 'Eighty', 'Ninety'];

        $two = function (int $x) use ($ones, $tens): string {
            if ($x < 20) {
                return $ones[$x];
            }

            return trim($tens[intdiv($x, 10)] . ' ' . $ones[$x % 10]);
        };

        $three = function (int $x) use ($ones, $two): string {
            $h = intdiv($x, 100);
            $r = $x % 100;

            return trim(($h ? $ones[$h] . ' Hundred' . ($r ? ' ' : '') : '') . ($r ? $two($r) : ''));
        };

        $parts = [];
        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thousand = intdiv($n, 1000);
        $n %= 1000;

        if ($crore) {
            $parts[] = $three($crore) . ' Crore';
        }
        if ($lakh) {
            $parts[] = $two($lakh) . ' Lakh';
        }
        if ($thousand) {
            $parts[] = $two($thousand) . ' Thousand';
        }
        if ($n) {
            $parts[] = $three($n);
        }

        return trim(implode(' ', $parts)) . ' Rupees';
    }
}

if (! function_exists('tr')) {
    /**
     * Translate a UI string to the current (or given) locale via the cached translation
     * API. Source-locale text is returned unchanged. Usage in Blade: {{ tr('Home') }}.
     */
    function tr(?string $text, ?string $locale = null): string
    {
        return app(TranslationManager::class)->to($text, $locale);
    }
}
