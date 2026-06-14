<?php

namespace App\Filament\Pages;

use App\Models\Currency;
use App\Models\Language;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Select;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * Settings → My Preferences. A per-user profile screen (NOT HqOnly, so every panel
 * login — head office and distributors alike — can set their own language, display
 * currency and theme). Saved on the users row; applied on each request by
 * App\Http\Middleware\ApplyUserPreferences and the panel head theme hook.
 */
class Preferences extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-user-circle';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'My Preferences';

    protected static ?string $title = 'My Preferences';

    protected static ?int $navigationSort = -1;

    protected static string $view = 'filament.pages.preferences';

    public ?array $data = [];

    public function mount(): void
    {
        $user = auth()->user();

        $this->form->fill([
            'locale' => $user->locale ?? 'en',
            // India-based logins are sealed to INR; only a foreign branch may pick another currency.
            'currency_code' => $this->isForeignBranch() ? ($user->currency_code ?? 'INR') : 'INR',
            'theme' => $user->theme ?? 'system',
        ]);
    }

    /**
     * Is the logged-in user's branch outside India? Currency is changeable only then;
     * a missing branch or a missing/IN country counts as India (sealed to INR).
     */
    protected function isForeignBranch(): bool
    {
        $country = auth()->user()?->branch?->country;

        return filled($country) && strtoupper((string) $country) !== 'IN';
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Profile preferences')
                ->description('These apply to your login everywhere — the admin panel and the storefront.')
                ->columns(3)
                ->schema([
                    Select::make('locale')
                        ->label('Language')
                        ->options(fn () => Language::where('is_active', true)->orderBy('sort')->pluck('name', 'code'))
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('Translatable content (products, plans, posts) is shown in this language.'),

                    Select::make('currency_code')
                        ->label('Currency')
                        ->options(fn () => Currency::where('is_active', true)->orderBy('code')->get()
                            ->mapWithKeys(fn ($c) => [$c->code => trim(($c->symbol ? $c->symbol . '  ' : '') . $c->code . ' — ' . $c->name)]))
                        ->required()
                        ->searchable()
                        ->native(false)
                        // Sealed to INR for India-based logins; only a foreign branch may change it.
                        ->disabled(fn () => ! $this->isForeignBranch())
                        ->dehydrated()
                        ->helperText(fn () => $this->isForeignBranch()
                            ? 'Amounts are converted to this currency for display.'
                            : 'Locked to INR — your branch is in India. Currency can be changed only for branches outside India.'),

                    Select::make('theme')
                        ->label('Theme')
                        ->options([
                            'light' => 'Light',
                            'dark' => 'Dark',
                            'system' => 'System (match device)',
                        ])
                        ->required()
                        ->native(false)
                        ->helperText('Appearance of the admin panel.'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();
        $user = auth()->user();

        // Enforce the seal server-side: India-based logins are always INR.
        $currency = $this->isForeignBranch() ? ($data['currency_code'] ?: 'INR') : 'INR';

        $user->update([
            'locale' => $data['locale'],
            'currency_code' => $currency,
            'theme' => $data['theme'],
        ]);

        // Reflect immediately for the rest of this request and the session.
        session(['locale' => $data['locale'], 'currency' => $currency]);
        app()->setLocale($data['locale']);

        Notification::make()->title('Preferences saved')->success()->send();

        // Full reload so the new locale + theme are applied to the whole shell.
        $this->redirect(static::getUrl());
    }
}
