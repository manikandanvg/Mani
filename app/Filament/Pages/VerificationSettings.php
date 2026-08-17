<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\KycSetting;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\HtmlString;

/**
 * System → Verification Settings. Lets the admin strengthen Aadhaar verification:
 * OFF = instant offline checksum, ON = real UIDAI OTP e-KYC via Sandbox.
 */
class VerificationSettings extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use HqOnly;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-shield-check';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Verification Settings';

    protected static ?string $title = 'Verification Settings';

    protected static string $view = 'filament.pages.verification-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $s = KycSetting::current();
        $this->form->fill([
            'aadhaar_otp_enabled' => $s->aadhaar_otp_enabled,
            'rekyc_enabled' => (bool) $s->rekyc_enabled,
            'rekyc_from' => optional($s->rekyc_from)->toDateString(),
            'rekyc_until' => optional($s->rekyc_until)->toDateString(),
            'pan_driver' => $s->pan_driver,
            'sandbox_key' => $s->sandbox_key,
            'sandbox_secret' => $s->sandbox_secret,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Aadhaar verification strength')
                ->description('Choose how Aadhaar is verified on the Sales screen.')
                ->schema([
                    Toggle::make('aadhaar_otp_enabled')
                        ->label('Strong verification — OTP e-KYC')
                        ->helperText(new HtmlString(
                            '<div style="line-height:1.5">'
                            . '<strong>Off</strong> — instant offline checksum (valid number, free, no proof of ownership).<br>'
                            . '<strong>On</strong> — UIDAI OTP e-KYC via Sandbox: an OTP is sent to the Aadhaar-linked mobile and the holder\'s name is confirmed. '
                            . 'Paid per verification &middot; requires Aadhaar OKYC enabled + credits on your Sandbox account.'
                            . '</div>'
                        )),
                ]),

            // Sandbox (sandbox.co.in) account — one key/secret powers PAN digital
            // verification AND Aadhaar OTP e-KYC. DB-first; .env is the fallback.
            Section::make('Verification API (sandbox.co.in)')
                ->description('Live API credentials for PAN / Aadhaar e-KYC. Leave empty to use the server .env values.')
                ->schema([
                    \Filament\Forms\Components\Select::make('pan_driver')
                        ->label('PAN verification mode')
                        ->options([
                            'fake' => 'Test mode — no real API (always verifies)',
                            'sandbox' => 'Live — Sandbox API',
                        ])
                        ->placeholder('Use server default (.env: ' . (config('services.pan.driver') ?: 'fake') . ')')
                        ->helperText('Live mode needs valid credentials below and credits on your Sandbox account.'),
                    \Filament\Forms\Components\TextInput::make('sandbox_key')
                        ->label('API key')
                        ->placeholder(config('services.pan.key') ? 'Using .env key (' . substr((string) config('services.pan.key'), 0, 12) . '…)' : 'key_live_…'),
                    \Filament\Forms\Components\TextInput::make('sandbox_secret')
                        ->label('API secret')
                        ->password()->revealable()
                        ->placeholder(config('services.pan.secret') ? 'Using .env secret' : 'secret_live_…'),
                ])->columns(3),

            // Re-KYC campaign (board 2026-08-12, item 17a): while ON and inside the
            // window, the app blocks distributors until PAN (digital) + Aadhaar
            // (upload → manual approval on Members) are re-verified.
            Section::make('Re-KYC campaign')
                ->description('Force every distributor to re-verify PAN + Aadhaar in the app within a date window.')
                ->schema([
                    Toggle::make('rekyc_enabled')
                        ->label('Re-KYC required')
                        ->helperText('On: the app asks distributors to complete KYC again. PAN is verified digitally; the Aadhaar card photo lands on the member for your manual approval.'),
                    \Filament\Forms\Components\DatePicker::make('rekyc_from')
                        ->label('Window from')->native(false)
                        ->helperText('Verifications older than this date do not count.'),
                    \Filament\Forms\Components\DatePicker::make('rekyc_until')
                        ->label('Window until')->native(false)
                        ->helperText('Leave empty for an open-ended campaign.'),
                ])->columns(3),
        ]);
    }

    public function save(): void
    {
        $state = $this->form->getState();

        KycSetting::current()->update([
            'aadhaar_otp_enabled' => (bool) ($state['aadhaar_otp_enabled'] ?? false),
            'rekyc_enabled' => (bool) ($state['rekyc_enabled'] ?? false),
            'rekyc_from' => $state['rekyc_from'] ?: null,
            'rekyc_until' => $state['rekyc_until'] ?: null,
            'pan_driver' => $state['pan_driver'] ?: null,
            'sandbox_key' => trim((string) ($state['sandbox_key'] ?? '')) ?: null,
            'sandbox_secret' => trim((string) ($state['sandbox_secret'] ?? '')) ?: null,
        ]);

        // New credentials → the cached Sandbox access token must be re-minted.
        \App\Services\Sandbox\SandboxAuth::forget();

        Notification::make()->title('Verification settings saved')->success()->send();
    }
}
