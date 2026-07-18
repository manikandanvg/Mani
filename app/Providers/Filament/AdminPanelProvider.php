<?php

namespace App\Providers\Filament;

use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\Widgets;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->login()
            ->brandName('Lord ICL')
            ->colors([
                // Brand palette from the logo: crimson primary + gold accent.
                'primary' => Color::hex('#ab222f'),
                'warning' => Color::hex('#e6ad46'),
                'danger' => Color::hex('#ab222f'),
                'gray' => Color::Stone,
            ])
            // Premium polish: shadows, depth, smooth motion (additive stylesheet).
            ->renderHook(
                'panels::head.end',
                fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                    '<link rel="stylesheet" href="' . asset('css/premium.css') . '">'
                )
            )
            // Enter moves to the next field instead of submitting (prevents accidental saves).
            ->renderHook(
                'panels::body.end',
                fn (): \Illuminate\Support\HtmlString => new \Illuminate\Support\HtmlString(
                    '<script src="' . asset('js/enter-as-tab.js') . '" defer></script>'
                )
            )
            // "View as Dealer" banner — shown while a super-admin is impersonating a distributor.
            ->renderHook(
                'panels::body.start',
                fn (): \Illuminate\Contracts\View\View => view('filament.impersonation-banner')
            )
            // Honour the logged-in user's saved theme (Settings → My Preferences) by
            // seeding Filament's theme store before it initialises. DB is authoritative.
            ->renderHook(
                'panels::head.start',
                function (): \Illuminate\Support\HtmlString {
                    $theme = auth()->user()?->theme;
                    if (! $theme) {
                        return new \Illuminate\Support\HtmlString('');
                    }

                    return new \Illuminate\Support\HtmlString(
                        "<script>try{localStorage.setItem('theme','" . e($theme) . "')}catch(e){}</script>"
                    );
                }
            )
            // One canonical sidebar order for BOTH logins. Daily-ops groups first (these are
            // exactly what a branch distributor sees — BranchScoped/un-restricted screens),
            // then head-office-only admin/config groups. Distributors get the same groups in
            // the same order, just the visible subset.
            ->navigationGroups([
                \Filament\Navigation\NavigationGroup::make('Trade')->label(fn () => __('Trade')),
                \Filament\Navigation\NavigationGroup::make('Sales & Bonds')->label(fn () => __('Sales & Bonds')),
                \Filament\Navigation\NavigationGroup::make('Settings')->label(fn () => __('Settings')),
                \Filament\Navigation\NavigationGroup::make('Commissions')->label(fn () => __('Commissions')),
                \Filament\Navigation\NavigationGroup::make('Payroll')->label(fn () => __('Payroll')),
                \Filament\Navigation\NavigationGroup::make('L-BOX')->label(fn () => __('L-BOX')),
                \Filament\Navigation\NavigationGroup::make('Network')->label(fn () => __('Distributor Network')),
                \Filament\Navigation\NavigationGroup::make('Orders')->label(fn () => __('Orders')),
                \Filament\Navigation\NavigationGroup::make('Website')->label(fn () => __('Website')),
                \Filament\Navigation\NavigationGroup::make('Community')->label(fn () => __('Community')),
                \Filament\Navigation\NavigationGroup::make('Master')->label(fn () => __('Master')),
                \Filament\Navigation\NavigationGroup::make('System')->label(fn () => __('System')),
            ])
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                // Account card (user + logout). The default FilamentInfoWidget — a
                // framework promo card linking to filamentphp.com — is intentionally
                // omitted; it has no place on a production dashboard.
                Widgets\AccountWidget::class,
            ])
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
                \App\Http\Middleware\ApplyUserPreferences::class,
            ]);
    }
}
