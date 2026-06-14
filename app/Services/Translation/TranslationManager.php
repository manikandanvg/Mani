<?php

namespace App\Services\Translation;

use Illuminate\Support\Facades\DB;

/**
 * Cached translation facade. Each (locale, source string) is translated once via the
 * driver, persisted in `translations`, and memoized per request. Source-locale text is
 * returned as-is. No-op results (driver returned the source) are NOT cached, so enabling
 * a real API later still translates them.
 */
class TranslationManager
{
    /** @var array<string,string> request-level memo */
    protected array $memo = [];

    public function __construct(protected Translator $driver) {}

    public function source(): string
    {
        return config('services.translation.source', 'en');
    }

    public function to(?string $text, ?string $locale = null): string
    {
        $text = (string) $text;
        $trimmed = trim($text);
        $locale = $locale ?: app()->getLocale();

        if ($trimmed === '' || $locale === $this->source()) {
            return $text;
        }

        $key = $locale . '|' . $trimmed;
        if (isset($this->memo[$key])) {
            return $this->memo[$key];
        }

        $hash = sha1($trimmed);
        $cached = DB::table('translations')->where('locale', $locale)->where('source_hash', $hash)->value('text');
        if ($cached !== null) {
            return $this->memo[$key] = $cached;
        }

        $translated = $this->driver->translate($trimmed, $locale, $this->source());

        // only persist genuine translations (skip no-ops so a future real API still runs)
        if ($translated !== '' && $translated !== $trimmed) {
            DB::table('translations')->updateOrInsert(
                ['locale' => $locale, 'source_hash' => $hash],
                ['source_text' => mb_substr($trimmed, 0, 2000), 'text' => $translated, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        return $this->memo[$key] = $translated;
    }
}
