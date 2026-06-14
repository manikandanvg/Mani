<?php

namespace App\Filament\Pages;

use App\Filament\Concerns\HqOnly;
use App\Models\WhatsappSetting;
use App\Services\Whatsapp\WhatsappSender;
use Filament\Forms\Components\Section;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Filament\Notifications\Notification;
use Filament\Pages\Page;

/**
 * System → WhatsApp Settings — DB-backed gateway credentials (instance id / access
 * token / URL) editable from the admin so they can be rotated weekly without a deploy.
 */
class WhatsappSettings extends Page implements HasForms
{
    use \App\Filament\Concerns\TranslatesNavigation;

    use HqOnly;
    use InteractsWithForms;

    protected static ?string $navigationIcon = 'heroicon-o-chat-bubble-left-right';

    protected static ?string $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'WhatsApp Settings';

    protected static ?string $title = 'WhatsApp Settings';

    protected static string $view = 'filament.pages.whatsapp-settings';

    public ?array $data = [];

    public ?string $testNumber = null;

    public function mount(): void
    {
        $s = WhatsappSetting::current();
        $this->form->fill([
            'url' => $s->url,
            'instance_id' => $s->instance_id,
            'access_token' => $s->access_token,
            'country_code' => $s->country_code,
            'is_active' => $s->is_active,
        ]);
    }

    public function form(Form $form): Form
    {
        return $form->statePath('data')->schema([
            Section::make('Gateway credentials')
                ->description('From your WhatsApp provider (legacy tbl_whatsappapi). Rotate the instance id / access token here whenever they change.')
                ->columns(2)
                ->schema([
                    TextInput::make('url')->label('API URL')->required()
                        ->placeholder('https://whatsapp.lordicl.com/')->columnSpanFull(),
                    TextInput::make('instance_id')->label('Instance ID')->required(),
                    TextInput::make('access_token')->label('Access Token')->required(),
                    TextInput::make('country_code')->label('Default country code')->default('91')->maxLength(5),
                    Toggle::make('is_active')->label('Gateway enabled')
                        ->helperText('Turn off to stop all outgoing WhatsApp messages.'),
                ]),
            Section::make('Send a test message')
                ->description('Verify the credentials by sending a text message to any number.')
                ->schema([
                    TextInput::make('test_number')->label('Test phone number')
                        ->tel()->placeholder('9894360870'),
                ]),
        ]);
    }

    public function save(): void
    {
        $data = $this->form->getState();

        WhatsappSetting::current()->update([
            'url' => $data['url'] ?? null,
            'instance_id' => $data['instance_id'] ?? null,
            'access_token' => $data['access_token'] ?? null,
            'country_code' => $data['country_code'] ?: '91',
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Notification::make()->title('WhatsApp settings saved')->success()->send();
    }

    public function sendTest(): void
    {
        // persist first so the test uses exactly what's on screen
        $this->save();

        $number = $this->form->getState()['test_number'] ?? null;
        if (blank($number)) {
            Notification::make()->title('Enter a test phone number')->warning()->send();

            return;
        }

        $res = app(WhatsappSender::class)->sendText(
            $number,
            'Lord Jewellery WhatsApp gateway test — your settings are working ✅'
        );

        Notification::make()
            ->title($res['ok'] ? 'Test message sent' : 'Test failed')
            ->body($res['message'] ?? '')
            ->status($res['ok'] ? 'success' : 'warning')
            ->send();
    }
}
