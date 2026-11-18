<?php

namespace App\Services\Support;

use App\Models\SupportThread;

/**
 * The Support Chat ready replies and the auto-reply bot (2026-11-18).
 *
 * Rule-based on purpose: the user's message is matched against fixed patterns ON THIS SERVER —
 * nothing is sent to any outside service. A reply is one of the fixed texts of
 * config/support_replies.php, with no member data in it. The bot's only write is its own chat
 * message (once per chat); it never touches a wallet, a bill, a profile or any other record.
 */
class SupportBot
{
    public const SOURCE = 'bot';

    /** Suffix of a Tamil reply key: "wallet:ta". */
    public const TAMIL = ':ta';

    /** key => title, for the desk's "Ready reply" picker — each reply in English, then in Tamil. */
    public static function options(): array
    {
        $out = [];
        foreach (config('support_replies.templates', []) as $key => $t) {
            $out[$key] = $t['title'];
            if (filled(config("support_replies.tamil.{$key}"))) {
                $out[$key . self::TAMIL] = $t['title'] . ' — தமிழ்';
            }
        }

        return $out;
    }

    /** The fixed text of a reply. "wallet:ta" = the Tamil version (English when there is none). */
    public static function body(?string $key): ?string
    {
        if (! $key) {
            return null;
        }
        if (str_ends_with($key, self::TAMIL)) {
            $key = substr($key, 0, -strlen(self::TAMIL));
            if ($ta = config("support_replies.tamil.{$key}")) {
                return $ta;
            }
        }

        return config("support_replies.templates.{$key}.body") ?: null;
    }

    /** Did the user write in Tamil script? (Tamil typed in English letters cannot be told apart.) */
    public static function isTamil(string $text): bool
    {
        return (bool) preg_match('/[\x{0B80}-\x{0BFF}]/u', $text);
    }

    /** The reply key in the user's language: "wallet" or "wallet:ta". */
    public static function inLanguageOf(string $key, string $userText): string
    {
        return static::isTamil($userText) && filled(config("support_replies.tamil.{$key}")) ? $key . self::TAMIL : $key;
    }

    /** The topic a message is about: the first template whose pattern is found in it. */
    public static function topicOf(string $text, bool $botOnly = false): string
    {
        $text = mb_strtolower($text);
        $all = config('support_replies.templates', []);
        // the priority list first, then any template not named in it
        $order = array_unique(array_merge((array) config('support_replies.priority', []), array_keys($all)));
        foreach ($order as $key) {
            $t = $all[$key] ?? null;
            if (! $t || empty($t['match']) || ($botOnly && empty($t['bot']))) {
                continue;
            }
            if (preg_match('/' . $t['match'] . '/iu', $text)) {
                return $key;
            }
        }

        return (string) config('support_replies.bot.fallback', 'general');
    }

    /** The reply the desk is most likely to need for this chat (from what the user wrote). */
    public static function suggestFor(SupportThread $thread): string
    {
        return static::topicOf(static::userText($thread));
    }

    /** The same suggestion, in the user's language — what the desk's picker offers first. */
    public static function suggestReplyFor(SupportThread $thread): string
    {
        return static::inLanguageOf(static::suggestFor($thread), static::userText($thread));
    }

    public static function enabled(): bool
    {
        return (bool) config('support_replies.bot.auto_reply', false);
    }

    /** Has this chat been answered at all — by the desk or by the bot? */
    public static function answered(SupportThread $thread): bool
    {
        return $thread->messages()->where('sender', 'support')->exists();
    }

    /**
     * Post the acknowledgement for this chat — once, and only while nobody has answered yet.
     * Returns the template key used, or null when nothing was sent.
     */
    public function acknowledge(SupportThread $thread): ?string
    {
        if (static::answered($thread)) {
            return null;
        }
        $text = static::userText($thread);
        // the acknowledgement of the chat's topic, in Tamil when the user wrote in Tamil
        $key = static::inLanguageOf(static::topicOf($text, botOnly: true), $text);
        $body = static::body($key);
        if (! $body) {
            return null;
        }
        app(SupportService::class)->supportReply($thread, $body, null, null, self::SOURCE);

        return $key;
    }

    /** Is this message only a thank-you / "ok" — nothing in it for staff to act on? */
    public static function isThanks(string $text): bool
    {
        $text = trim(mb_strtolower($text));

        return $text !== '' && mb_strlen($text) <= 60
            && (bool) preg_match('/' . config('support_replies.bot.thanks') . '/iu', $text);
    }

    /**
     * Answer one message of the user — the first of a chat, or a follow-up after a reply:
     *
     *   first message                      → the acknowledgement of its topic
     *   "ok / thank you"                    → "you are welcome"
     *   a NEW topic in the same chat        → the acknowledgement of that topic (once per topic)
     *   details after we asked for them     → "details received" (once until staff reply)
     *   anything else, or the limit reached → silence: staff answer
     *
     * Always a fixed text; never more than `max_replies` in one chat. Returns the key sent, or null.
     */
    public function respond(SupportThread $thread, \App\Models\SupportMessage $message): ?string
    {
        $bot = $thread->messages()->where('sender', 'support')->where('source', self::SOURCE)->orderBy('id')->get(['id', 'body']);
        if ($bot->count() >= (int) config('support_replies.bot.max_replies', 3)) {
            return null;
        }
        if (! static::answered($thread)) {
            return $this->acknowledge($thread);
        }

        $text = (string) $message->body;
        $said = fn (string $key) => $bot->contains(fn ($m) => in_array($m->body, [static::body($key), static::body($key . self::TAMIL)], true));
        $lastSupport = $thread->messages()->where('sender', 'support')->latest('id')->first();
        $lastWasBot = $lastSupport?->source === self::SOURCE;

        $key = match (true) {
            static::isThanks($text) => $lastWasBot && $lastSupport->body !== static::body('thanks') && $lastSupport->body !== static::body('thanks' . self::TAMIL) ? 'thanks' : null,
            // another subject raised in the same chat
            ($topic = static::topicOf($text, botOnly: true)) !== config('support_replies.bot.fallback') && ! $said($topic) => $topic,
            // an answer to what was asked: say it arrived — once, until a staff member has replied
            $lastWasBot && ! in_array($lastSupport->body, [static::body('received'), static::body('received' . self::TAMIL)], true) => 'received',
            default => null,
        };
        if (! $key) {
            return null;
        }
        // in the language of THIS message (falls back to the language of the chat)
        $key = static::inLanguageOf($key, static::isTamil($text) ? $text : static::userText($thread));
        app(SupportService::class)->supportReply($thread, (string) static::body($key), null, null, self::SOURCE);

        return $key;
    }

    /** A fixed reply as simple HTML for the desk's rich-text editor. */
    public static function html(?string $key): string
    {
        return collect(preg_split("/\n{2,}/", (string) static::body($key)))
            ->filter()->map(fn ($p) => '<p>' . nl2br(e($p)) . '</p>')->implode('');
    }

    /**
     * Read-only facts about the member of this chat, for the DESK ONLY (shown beside the chat,
     * never sent anywhere): the figures a support person otherwise looks up on five screens.
     *
     * @return array<string, string>  label => value; empty for a non-member chat
     */
    public static function factsFor(SupportThread $thread): array
    {
        $member = $thread->owner;
        if (! $member instanceof \App\Models\Member) {
            return [];
        }
        $money = fn ($v) => '₹' . \App\Support\Money::group((float) $v);
        $w = \Illuminate\Support\Facades\DB::table('member_wallets')->where('member_id', $member->id)->first();
        $wd = \Illuminate\Support\Facades\DB::table('wallet_withdrawals')->where('member_id', $member->id)
            ->selectRaw('status, count(*) n, sum(amount) total, max(created_at) latest')->groupBy('status')->get()->keyBy('status');
        $open = $wd->only(['pending', 'requested', 'admin_paid']);
        $pending = \Illuminate\Support\Facades\DB::table('commission_ledger')->where('member_id', $member->id)->where('status', 'pending')
            ->selectRaw('count(*) n, coalesce(sum(amount), 0) total')->first();
        $coupons = \Illuminate\Support\Facades\DB::table('cbc_entries')->where('member_id', $member->id)->where('status', 'pending')
            ->where('cbc_date', '<=', now()->toDateString())->selectRaw('count(*) n, coalesce(sum(worth), 0) total')->first();
        $bonds = \App\Models\Bond::where('member_id', $member->id)->where('status', 'active');

        return [
            'Distributor' => $member->member_code . ' · ' . ucfirst((string) $member->status),
            'Cash wallet' => $money($w->cash_balance ?? 0),
            'Digi Gold / Silver' => rtrim(rtrim(number_format((float) ($w->digi_gold_grams ?? 0), 4), '0'), '.') . ' g / '
                . rtrim(rtrim(number_format((float) ($w->digi_silver_grams ?? 0), 4), '0'), '.') . ' g',
            'Earned / withdrawn so far' => $money($w->earning_total ?? 0) . ' / ' . $money($w->withdrawn_total ?? 0),
            'Withdrawals in progress' => $open->isEmpty() ? 'none'
                : $open->map(fn ($r, $s) => $r->n . ' ' . str_replace('_', ' ', $s) . ' (' . $money($r->total) . ')')->implode(', '),
            'Last withdrawal paid' => ($d = $wd->get('disbursed')) ? \Illuminate\Support\Carbon::parse($d->latest)->format('d M Y') . ' · ' . $d->n . ' in all' : 'none',
            'Incentives awaiting approval' => (int) $pending->n === 0 ? 'none' : $pending->n . ' (' . $money($pending->total) . ')',
            'CBC coupons due, not yet paid' => (int) $coupons->n === 0 ? 'none' : $coupons->n . ' (' . $money($coupons->total) . ')',
            'Active contracts' => (string) (clone $bonds)->count(),
            'EP available today' => $money(app(\App\Services\CommissionService::class)->earningPotential($member)),
            'Business value / group' => $money($member->bv) . ' / ' . $money($member->gbv),
        ];
    }

    /**
     * A message that goes to MANY chats must not carry one person's detail. Returns what looks
     * personal in the text (a distributor ID, a phone number, an amount) or null when it is clean.
     * A year (2026) is allowed, so a date can be mentioned.
     */
    public static function personalDetailIn(string $text): ?string
    {
        if (preg_match('/\bLJ[A-Z]{0,2}\d{2,}\b/i', $text, $m)) {
            return $m[0];
        }
        preg_match_all('/\d{4,}/', (string) preg_replace('/(?<=\d),(?=\d)/', '', $text), $runs);
        foreach ($runs[0] as $run) {
            if (! preg_match('/^20\d\d$/', $run)) {
                return $run;
            }
        }

        return null;
    }

    /** Everything the user wrote in the chat (subject included). */
    protected static function userText(SupportThread $thread): string
    {
        return trim($thread->subject . "\n" . $thread->messages()->where('sender', 'user')->pluck('body')->implode("\n"));
    }
}
