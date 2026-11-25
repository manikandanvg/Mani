<?php

namespace App\Livewire;

use App\Models\SupportThread;
use App\Services\Support\SupportService;
use Filament\Forms;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Forms\Form;
use Livewire\Component;

/**
 * One support conversation, shown inside the Support Chat popup (board 2026-11-15): the
 * transcript refreshes by itself, the desk replies with a rich-text editor, and the chat can
 * be closed from here — a closed chat leaves the list until the "Closed" filter is chosen.
 *
 * The reply is stored twice: `body_html` (what the desk formatted, shown in the panel) and
 * `body` (the same text, plain — what the mobile app and the push notification show).
 */
class SupportChatPanel extends Component implements HasForms
{
    use InteractsWithForms;

    public int $threadId;

    public ?array $data = [];

    public function mount(int $threadId): void
    {
        abort_unless(static::allowed(), 403);
        $this->threadId = $threadId;
        $this->form->fill();
        app(SupportService::class)->markSupportRead($this->thread());
    }

    /** Head Office and the support desk only — never a dealer login. */
    protected static function allowed(): bool
    {
        $u = auth()->user();

        return $u !== null && ! (method_exists($u, 'isDistributor') && $u->isDistributor());
    }

    public function thread(): SupportThread
    {
        return SupportThread::findOrFail($this->threadId);
    }

    public function form(Form $form): Form
    {
        return $form->schema([
            // Ready replies (2026-11-18): fixed texts with no member data in them. The one that fits
            // what the user wrote is offered first; picking one fills the editor, still editable.
            Forms\Components\Select::make('template')
                ->hiddenLabel()
                ->placeholder(__('Ready reply — suggested: :t', ['t' => \App\Services\Support\SupportBot::options()[\App\Services\Support\SupportBot::suggestReplyFor($this->thread())] ?? '—']))
                ->options(\App\Services\Support\SupportBot::options())
                ->searchable()->live()->dehydrated(false)
                ->afterStateUpdated(fn ($state, Forms\Set $set) => $state ? $set('reply', \App\Services\Support\SupportBot::html($state)) : null),
            Forms\Components\RichEditor::make('reply')
                ->hiddenLabel()
                ->placeholder(__('Type a reply…'))
                ->toolbarButtons(['bold', 'italic', 'underline', 'strike', 'bulletList', 'orderedList', 'link', 'blockquote', 'undo', 'redo'])
                ->maxLength(8000),
        ])->statePath('data');
    }

    public function send(): void
    {
        abort_unless(static::allowed(), 403);
        $html = trim((string) ($this->form->getState()['reply'] ?? ''));
        $plain = static::plain($html);
        if ($plain === '') {
            return;
        }

        $thread = $this->thread();
        app(SupportService::class)->supportReply($thread, $plain, auth()->id(), static::clean($html));
        app(SupportService::class)->markSupportRead($thread);
        $this->form->fill();
        $this->dispatch('chat-updated');
    }

    public function toggleStatus(): void
    {
        abort_unless(static::allowed(), 403);
        $thread = $this->thread();
        $thread->update(['status' => $thread->status === 'open' ? 'closed' : 'open']);
        app(SupportService::class)->consolidate($thread);   // reopened beside another open chat → one chat
        // tell the list behind the popup: it shuts the popup and drops the chat from the list
        $this->dispatch('support-thread-changed');
    }

    /** Keep only harmless formatting tags. */
    public static function clean(string $html): string
    {
        return (string) str($html)->sanitizeHtml();
    }

    /** The reply as plain text, line breaks kept — for the app and the push notification. */
    public static function plain(string $html): string
    {
        $text = preg_replace('/<li[^>]*>/i', '• ', $html) ?? $html;
        $text = preg_replace('/<\/(p|div|li|h[1-6]|blockquote|ul|ol)>|<br\s*\/?>/i', "\n", $text) ?? $text;
        $text = html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t\x{00A0}]+/u", ' ', $text) ?? $text;

        return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
    }

    public function render()
    {
        $thread = $this->thread();

        return view('livewire.support-chat-panel', [
            'thread' => $thread,
            'facts' => \App\Services\Support\SupportBot::factsFor($thread),
            'messages' => $thread->messages()->with('supportUser')->orderBy('created_at')->orderBy('id')->get(),
        ]);
    }
}
