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

if (! function_exists('media_url')) {
    /**
     * Public URL for a stored media reference. Handles absolute URLs, bundled
     * public assets ("images/...") and Filament uploads on the public disk.
     */
    function media_url(?string $path): ?string
    {
        if (blank($path)) {
            return null;
        }
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $path = ltrim($path, '/');

        return str_starts_with($path, 'images/') ? asset($path) : asset('storage/' . $path);
    }
}

if (! function_exists('tamil_number_words')) {
    /**
     * Tamil words for a whole number, Indian numbering (லட்சம் / கோடி) with
     * proper joins (சந்தி): 10000 → "பத்தாயிரம்", 25 → "இருபத்தைந்து",
     * 250 → "இருநூற்று ஐம்பது". ICU's Tamil spellout gets these joins wrong
     * ("பத்து ஆயிரம்"), hence this hand-built converter (board phase-1, 2026-08-21).
     */
    function tamil_number_words(int $n): string
    {
        if ($n === 0) {
            return 'பூஜ்ஜியம்';
        }

        $units = ['', 'ஒன்று', 'இரண்டு', 'மூன்று', 'நான்கு', 'ஐந்து', 'ஆறு', 'ஏழு', 'எட்டு', 'ஒன்பது'];
        $teens = ['பத்து', 'பதினொன்று', 'பன்னிரண்டு', 'பதின்மூன்று', 'பதினான்கு', 'பதினைந்து', 'பதினாறு', 'பதினேழு', 'பதினெட்டு', 'பத்தொன்பது'];
        $tens = ['', '', 'இருபது', 'முப்பது', 'நாற்பது', 'ஐம்பது', 'அறுபது', 'எழுபது', 'எண்பது', 'தொண்ணூறு'];
        $tensPrefix = ['', '', 'இருபத்து', 'முப்பத்து', 'நாற்பத்து', 'ஐம்பத்து', 'அறுபத்து', 'எழுபத்து', 'எண்பத்து', 'தொண்ணூற்று'];

        // Fuse a -த்து/-ற்று prefix with a vowel-initial word: இருபத்து + ஐந்து →
        // இருபத்தைந்து. The independent vowel becomes its dependent sign.
        $fuse = function (string $prefix, string $word): string {
            $signs = ['ஒ' => 'ொ', 'இ' => 'ி', 'ஈ' => 'ீ', 'ஐ' => 'ை', 'ஆ' => 'ா', 'ஏ' => 'ே', 'எ' => 'ெ', 'உ' => 'ு', 'ஊ' => 'ூ', 'ஓ' => 'ோ', 'அ' => ''];
            $first = mb_substr($word, 0, 1);
            if (isset($signs[$first])) {
                return mb_substr($prefix, 0, -1) . $signs[$first] . mb_substr($word, 1);
            }

            return $prefix . ' ' . $word;   // consonant-initial (மூன்று, நான்கு)
        };

        $two = function (int $x) use ($units, $teens, $tens, $tensPrefix, $fuse): string {
            if ($x < 10) {
                return $units[$x];
            }
            if ($x < 20) {
                return $teens[$x - 10];
            }
            $t = intdiv($x, 10);
            $u = $x % 10;

            return $u === 0 ? $tens[$t] : $fuse($tensPrefix[$t], $units[$u]);
        };

        $hundredsAlone = ['', 'நூறு', 'இருநூறு', 'முந்நூறு', 'நானூறு', 'ஐந்நூறு', 'அறுநூறு', 'எழுநூறு', 'எண்ணூறு', 'தொள்ளாயிரம்'];
        $hundredsJoin = ['', 'நூற்று', 'இருநூற்று', 'முந்நூற்று', 'நானூற்று', 'ஐந்நூற்று', 'அறுநூற்று', 'எழுநூற்று', 'எண்ணூற்று', 'தொள்ளாயிரத்து'];

        $three = function (int $x) use ($two, $hundredsAlone, $hundredsJoin): string {
            $h = intdiv($x, 100);
            $r = $x % 100;
            if ($h === 0) {
                return $two($r);
            }

            return $r === 0 ? $hundredsAlone[$h] : $hundredsJoin[$h] . ' ' . $two($r);
        };

        // ஆயிரம் group (1–99 thousand) with the everyday merged forms.
        $thousand = function (int $t, bool $joins) use ($two): string {
            $alone = [1 => 'ஆயிரம்', 2 => 'இரண்டாயிரம்', 3 => 'மூன்றாயிரம்', 4 => 'நான்காயிரம்', 5 => 'ஐந்தாயிரம்',
                6 => 'ஆறாயிரம்', 7 => 'ஏழாயிரம்', 8 => 'எட்டாயிரம்', 9 => 'ஒன்பதாயிரம்', 10 => 'பத்தாயிரம்',
                20 => 'இருபதாயிரம்', 30 => 'முப்பதாயிரம்', 40 => 'நாற்பதாயிரம்', 50 => 'ஐம்பதாயிரம்',
                60 => 'அறுபதாயிரம்', 70 => 'எழுபதாயிரம்', 80 => 'எண்பதாயிரம்', 90 => 'தொண்ணூறாயிரம்'];
            if (isset($alone[$t])) {
                $w = $alone[$t];

                // joined form swaps the final -ம் for -த்து (பத்தாயிரம் → பத்தாயிரத்து)
                return $joins ? mb_substr($w, 0, -2) . 'த்து' : $w;
            }

            return $two($t) . ' ' . ($joins ? 'ஆயிரத்து' : 'ஆயிரம்');
        };

        $crore = intdiv($n, 10000000);
        $n %= 10000000;
        $lakh = intdiv($n, 100000);
        $n %= 100000;
        $thou = intdiv($n, 1000);
        $rest = $n % 1000;

        $parts = [];
        if ($crore) {
            $parts[] = ($crore === 1 ? 'ஒரு' : ($crore < 100 ? $two($crore) : $three($crore)))
                . ' ' . (($lakh || $thou || $rest) ? 'கோடியே' : 'கோடி');
        }
        if ($lakh) {
            $parts[] = ($lakh === 1 ? 'ஒரு' : $two($lakh)) . ' ' . (($thou || $rest) ? 'லட்சத்து' : 'லட்சம்');
        }
        if ($thou) {
            $parts[] = $thousand($thou, $rest > 0);
        }
        if ($rest) {
            $parts[] = $three($rest);
        }

        return implode(' ', $parts);
    }
}

if (! function_exists('amount_words')) {
    /**
     * Amount in words, language + currency aware (board phase-1, 2026-08-21):
     *   amount_words(10000)               → "Ten Thousand Rupees"
     *   amount_words(10000, 'ta')         → "பத்தாயிரம் ரூபாய்"
     *   amount_words(500, 'en', 'AED')    → "Five Hundred Dirhams"
     * Whole units only (paise ignored), matching inr_words().
     */
    function amount_words(float $amount, string $lang = 'en', string $currency = 'INR'): string
    {
        $currency = strtoupper($currency);
        $unitWords = [
            'INR' => ['en' => 'Rupees', 'ta' => 'ரூபாய்'],
            'AED' => ['en' => 'Dirhams', 'ta' => 'திர்ஹம்'],
            'SAR' => ['en' => 'Riyals', 'ta' => 'ரியால்'],
            'USD' => ['en' => 'Dollars', 'ta' => 'டாலர்'],
            'SGD' => ['en' => 'Dollars', 'ta' => 'டாலர்'],
            'GBP' => ['en' => 'Pounds', 'ta' => 'பவுண்ட்'],
        ];
        $unit = $unitWords[$currency][$lang] ?? $unitWords[$currency]['en'] ?? $currency;

        if ($lang === 'ta') {
            return tamil_number_words((int) round($amount)) . ' ' . $unit;
        }

        $en = inr_words($amount);   // ends in " Rupees"

        return $currency === 'INR' ? $en : mb_substr($en, 0, -mb_strlen(' Rupees')) . ' ' . $unit;
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
