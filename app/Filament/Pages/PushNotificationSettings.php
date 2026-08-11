<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\Member;
use App\Models\PushSetting;
use App\Notifications\AppNotification;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Cache;

/**
 * System → Push Notification Settings (board 2026-08-11: WhatsApp = OTP only, every
 * acknowledgement goes out as app push + inbox entry). FCM and APNs credentials
 * live under one roof here; DB row wins, .env is the fallback. iOS devices running
 * the Firebase SDK are reached through FCM already — the APNs section is for a
 * future native-APNs path and can stay off until Apple credentials arrive.
 */
class PushNotificationSettings extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use HqOnly;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-bell-alert';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Push Notification Settings';

    protected static ?string $title = 'Push Notification Settings';

    protected static string $view = 'filament.pages.push-notification-settings';

    public ?array $data = [];

    public function mount(): void
    {
        $s = PushSetting::current();
        $this->form->fill([
            'fcm_enabled' => $s->fcm_enabled,
            'fcm_project_id' => $s->fcm_project_id,
            'fcm_client_email' => $s->fcm_client_email,
            'fcm_private_key' => $s->fcm_private_key,
            'apns_enabled' => $s->apns_enabled,
            'apns_key_id' => $s->apns_key_id,
            'apns_team_id' => $s->apns_team_id,
            'apns_bundle_id' => $s->apns_bundle_id,
            'apns_private_key' => $s->apns_private_key,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Firebase Cloud Messaging (Android + iOS via Firebase)')
                ->description('Service-account credentials from the Firebase console (Project settings → Service accounts). All app acknowledgements — QR, contract, invoice, reminders, settlements, payments — deliver through this.')
                ->columns(2)
                ->schema([
                    Toggle::make('fcm_enabled')->label('FCM enabled')
                        ->helperText('Turn off to stop all outgoing push (inbox entries still record).')
                        ->columnSpanFull(),
                    TextInput::make('fcm_project_id')->label('Project ID')->placeholder('lord-jeweller-app'),
                    TextInput::make('fcm_client_email')->label('Service account email')
                        ->placeholder('firebase-adminsdk-…@….iam.gserviceaccount.com'),
                    Textarea::make('fcm_private_key')->label('Private key (PEM)')->rows(5)
                        ->placeholder('-----BEGIN PRIVATE KEY-----…')
                        ->columnSpanFull(),
                ]),
            Section::make('Apple Push Notification service (native APNs — optional)')
                ->description('Only needed for a future native-APNs path; iPhones using the Firebase SDK already receive pushes via FCM above. Add the .p8 key details here when Apple credentials arrive.')
                ->columns(3)
                ->schema([
                    Toggle::make('apns_enabled')->label('APNs enabled')->columnSpanFull(),
                    TextInput::make('apns_key_id')->label('Key ID'),
                    TextInput::make('apns_team_id')->label('Team ID'),
                    TextInput::make('apns_bundle_id')->label('Bundle ID')->placeholder('com.lordjeweller.app'),
                    Textarea::make('apns_private_key')->label('Auth key (.p8 contents)')->rows(4)->columnSpanFull(),
                ]),
            Section::make('Send a test notification')
                ->description('Saves first, then pushes a test to every registered device of the given distributor and writes it to their in-app inbox.')
                ->schema([
                    TextInput::make('test_member_code')->label('Distributor code')->placeholder('LJW01'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        PushSetting::current()->update([
            'fcm_enabled' => (bool) ($data['fcm_enabled'] ?? true),
            'fcm_project_id' => $data['fcm_project_id'] ?? null,
            'fcm_client_email' => $data['fcm_client_email'] ?? null,
            'fcm_private_key' => $data['fcm_private_key'] ?? null,
            'apns_enabled' => (bool) ($data['apns_enabled'] ?? false),
            'apns_key_id' => $data['apns_key_id'] ?? null,
            'apns_team_id' => $data['apns_team_id'] ?? null,
            'apns_bundle_id' => $data['apns_bundle_id'] ?? null,
            'apns_private_key' => $data['apns_private_key'] ?? null,
        ]);

        // Rotated credentials must not reuse a token minted with the old key.
        Cache::forget('fcm.access_token');

        Notification::make()->title('Push notification settings saved')->success()->send();
    }

    public function sendTest(): void
    {
        $this->save();

        $code = trim((string) ($this->form->getState()['test_member_code'] ?? ''));
        if ($code === '') {
            Notification::make()->title('Enter a distributor code')->warning()->send();

            return;
        }

        $member = Member::where('member_code', strtoupper($code))->first();
        if (! $member) {
            Notification::make()->title('No distributor with that code')->warning()->send();

            return;
        }

        $tokens = $member->deviceTokens()->count();
        $member->notify(new AppNotification(
            category: 'system',
            title: 'Test notification',
            body: 'Push gateway test from LORD JEWELLER admin — ' . now()->format('d M Y H:i'),
        ));

        Notification::make()
            ->title('Test dispatched')
            ->body("Inbox entry written for {$member->member_code}; push attempted to {$tokens} registered device(s)." . ($tokens === 0 ? ' (No devices registered yet — the member must log in on the app once.)' : ''))
            ->success()->send();
    }
}
