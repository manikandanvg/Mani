<?php

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // PAN verifier driver, swappable via config('services.pan.driver').
        $this->app->bind(\App\Services\Pan\PanVerifier::class, function () {
            return match (config('services.pan.driver')) {
                'sandbox' => new \App\Services\Pan\SandboxPanVerifier,
                'surepass', 'zoop' => new \App\Services\Pan\SurepassPanVerifier,
                default => new \App\Services\Pan\FakePanVerifier,
            };
        });

        // Aadhaar verifier: OTP e-KYC when the admin enables it (System → Verification
        // Settings), otherwise the instant offline checksum.
        $this->app->bind(\App\Services\Aadhaar\AadhaarVerifier::class, fn () => \App\Models\KycSetting::aadhaarOtpEnabled()
            ? new \App\Services\Aadhaar\SandboxOtpAadhaarVerifier
            : new \App\Services\Aadhaar\ChecksumAadhaarVerifier);

        // Translation driver, swappable via config('services.translation.driver').
        $this->app->bind(\App\Services\Translation\Translator::class, function () {
            return match (config('services.translation.driver')) {
                'mymemory' => new \App\Services\Translation\MyMemoryTranslator,
                'google' => new \App\Services\Translation\GoogleTranslator,
                'libre' => new \App\Services\Translation\LibreTranslator,
                default => new \App\Services\Translation\EchoTranslator,
            };
        });
        $this->app->singleton(\App\Services\Translation\TranslationManager::class);

        // Geocoder driver, swappable via config('services.geocode.driver').
        $this->app->bind(\App\Services\Geocode\Geocoder::class, fn () => match (config('services.geocode.driver')) {
            'google' => new \App\Services\Geocode\GoogleGeocoder,
            default => new \App\Services\Geocode\NominatimGeocoder,
        });

        // Foreign-exchange provider, swappable via config('services.exchange.driver').
        $this->app->bind(\App\Services\Rates\ExchangeRateProvider::class, function () {
            return match (config('services.exchange.driver')) {
                'open_er_api' => new \App\Services\Rates\OpenErApiProvider,
                default => new \App\Services\Rates\ManualExchangeRateProvider,
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Admin-only Filament app: forms only ever fill schema-defined fields, so a
        // global unguard keeps every model mass-assignable without per-model $fillable.
        Model::unguard();

        // Every data table gets a CSV / Excel / PDF / Print export toolbar, reading the
        // table's own columns + currently filtered rows. No table sets header actions of
        // its own, so this is purely additive.
        \Filament\Tables\Table::configureUsing(function (\Filament\Tables\Table $table): void {
            $table->headerActions(\App\Filament\Support\TableExport::actions());
        });

        $this->localizePanelLabels();
        $this->registerMoneyMacros();

        // App push (Phase 4b): the 'fcm' notification channel delivers to a notifiable's
        // registered devices via FCM. No-op until config/services.fcm is configured.
        \Illuminate\Support\Facades\Notification::extend(
            'fcm',
            fn ($app) => $app->make(\App\Notifications\Channels\FcmChannel::class),
        );
    }

    /**
     * `->baseMoney()` for table columns and infolist entries: the value is stored in the
     * base currency (INR); display it converted into the viewer's chosen currency
     * (Settings → My Preferences). Replaces Filament's ->money('inr'), which would NOT
     * convert — it would just stamp a symbol on the raw base amount.
     */
    protected function registerMoneyMacros(): void
    {
        $format = fn ($state) => ($state === null || $state === '')
            ? null
            : \App\Support\Money::display((float) $state);

        \Filament\Tables\Columns\TextColumn::macro('baseMoney', function () use ($format) {
            /** @var \Filament\Tables\Columns\TextColumn $this */
            return $this->formatStateUsing($format)->alignEnd();
        });

        \Filament\Infolists\Components\TextEntry::macro('baseMoney', function () use ($format) {
            /** @var \Filament\Infolists\Components\TextEntry $this */
            return $this->formatStateUsing($format);
        });

        // Column summary rows (Sum/Average) carry a base-currency total too.
        \Filament\Tables\Columns\Summarizers\Summarizer::macro('baseMoney', function () use ($format) {
            /** @var \Filament\Tables\Columns\Summarizers\Summarizer $this */
            return $this->formatStateUsing($format);
        });
    }

    /**
     * Route every form field, table column, infolist entry and section heading through
     * the translator so the whole panel follows the active locale (see lang/ta.json).
     * Done globally here instead of per-component across 45+ resources.
     */
    protected function localizePanelLabels(): void
    {
        \Filament\Forms\Components\Field::configureUsing(
            fn (\Filament\Forms\Components\Field $field) => $field->translateLabel()
        );

        \Filament\Tables\Columns\Column::configureUsing(
            fn (\Filament\Tables\Columns\Column $column) => $column->translateLabel()
        );

        \Filament\Infolists\Components\Entry::configureUsing(
            fn (\Filament\Infolists\Components\Entry $entry) => $entry->translateLabel()
        );

        // Sections carry a heading (not a label), so translate it explicitly.
        foreach ([\Filament\Forms\Components\Section::class, \Filament\Infolists\Components\Section::class] as $section) {
            $section::configureUsing(function ($component): void {
                $heading = $component->getHeading();
                if (is_string($heading) && $heading !== '') {
                    $component->heading(__($heading));
                }
            });
        }
    }
}
