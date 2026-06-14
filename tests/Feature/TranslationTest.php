<?php

namespace Tests\Feature;

use App\Services\Translation\TranslationManager;
use App\Services\Translation\Translator;
use App\Support\Translatable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TranslationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // deterministic fake driver: prefixes with the target locale, no network
        $this->app->bind(Translator::class, fn () => new class implements Translator {
            public function translate(string $text, string $to, string $from = 'en'): string
            {
                return "[$to] $text";
            }
        });
        $this->app->forgetInstance(TranslationManager::class);
    }

    public function test_source_locale_returns_unchanged(): void
    {
        app()->setLocale('en');
        $this->assertEquals('Home', tr('Home'));
    }

    public function test_translates_and_caches(): void
    {
        app()->setLocale('te');

        $this->assertEquals('[te] Home', tr('Home'));
        $this->assertDatabaseHas('translations', ['locale' => 'te', 'text' => '[te] Home']);

        // second call hits cache (row count stays 1 for this string)
        $this->assertEquals('[te] Home', tr('Home'));
        $this->assertEquals(1, \DB::table('translations')->where('locale', 'te')->count());
    }

    public function test_translatable_pick_auto_translates_missing_locale(): void
    {
        app()->setLocale('ml');
        // only English authored; ml should be machine-translated
        $this->assertEquals('[ml] Gold Chain', Translatable::pick(['en' => 'Gold Chain']));

        // authored locale value wins over machine translation
        app()->setLocale('te');
        $this->assertEquals('Bangaru', Translatable::pick(['en' => 'Gold', 'te' => 'Bangaru']));
    }
}
