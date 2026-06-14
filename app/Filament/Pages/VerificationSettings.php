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
        $this->form->fill(['aadhaar_otp_enabled' => KycSetting::current()->aadhaar_otp_enabled]);
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
        ]);
    }

    public function save(): void
    {
        KycSetting::current()->update([
            'aadhaar_otp_enabled' => (bool) ($this->form->getState()['aadhaar_otp_enabled'] ?? false),
        ]);

        Notification::make()->title('Verification settings saved')->success()->send();
    }
}
