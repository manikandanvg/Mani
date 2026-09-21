<?php

namespace App\Services\Lbox;

use App\Models\Device;
use App\Models\LiveRate;
use Illuminate\Support\Carbon;

/**
 * "Hello L-BOX" Tier-1 brain: keyword intents answered from REAL business data
 * (live rates, branch, clock) in the device's language — free, instant, and
 * incapable of hallucinating a wrong gold rate at a jewellery counter. Anything
 * unmatched gets a polite fallback (Tier-2 LLM hook can slot in there later).
 * Every answer is TTS-rendered through the same voice pipeline the box streams.
 */
class AssistantService
{
    public function __construct(protected VoiceRenderService $voice)
    {
    }

    /** @return array{intent: string, answer: string, action: ?string, audio_path: ?string} */
    public function ask(Device $device, string $text): array
    {
        $lang = $device->language ?? 'en';
        $q = mb_strtolower(trim($text));

        [$intent, $answer, $action] = array_pad($this->answer($device, $q, $lang), 3, null);

        return [
            'intent' => $intent,
            'answer' => $answer,
            'action' => $action,   // device-side command (volume_up / volume_down), null for pure Q&A
            'audio_path' => $this->voice->render($answer, $lang),
        ];
    }

    /** @return array{0: string, 1: string, 2?: string} [intent, answer, action?] */
    protected function answer(Device $device, string $q, string $lang): array
    {
        $rate = LiveRate::latestFor($device->branch?->country ?: 'IN');
        $ta = $lang === 'ta';

        $hasGold = $this->hits($q, ['gold', 'தங்கம்', 'தங்க']);
        $hasSilver = $this->hits($q, ['silver', 'வெள்ளி']);
        $hasDiamond = $this->hits($q, ['diamond', 'வைரம்', 'வைர']);
        $asksRate = $hasGold || $hasSilver || $hasDiamond || $this->hits($q, ['rate', 'price', 'விலை', 'ரேட்']);

        if ($asksRate && ! $rate) {
            return ['rate_unavailable', $ta
                ? 'மன்னிக்கவும், இன்றைய விலை இப்போது கிடைக்கவில்லை. கவுண்டரில் கேளுங்கள்.'
                : 'Sorry, today\'s rate is not available right now. Please ask at the counter.'];
        }

        if ($hasGold) {
            return ['gold_rate', $ta
                ? sprintf('இன்றைய தங்கம் விலை ஒரு கிராமுக்கு ரூபாய் %s.', \App\Support\Money::group((float) $rate->gold))
                : sprintf('Today\'s gold rate is rupees %s per gram.', \App\Support\Money::group((float) $rate->gold))];
        }
        if ($hasSilver) {
            return ['silver_rate', $ta
                ? sprintf('இன்றைய வெள்ளி விலை ஒரு கிராமுக்கு ரூபாய் %s.', \App\Support\Money::group((float) $rate->silver))
                : sprintf('Today\'s silver rate is rupees %s per gram.', \App\Support\Money::group((float) $rate->silver))];
        }
        if ($hasDiamond) {
            return ['diamond_rate', $ta
                ? sprintf('இன்றைய வைர விலை ரூபாய் %s.', \App\Support\Money::group((float) $rate->diamond))
                : sprintf('Today\'s diamond rate is rupees %s.', \App\Support\Money::group((float) $rate->diamond))];
        }
        if ($asksRate) {
            return ['rates', $ta
                ? sprintf('இன்றைய விலை: தங்கம் கிராமுக்கு ரூபாய் %s, வெள்ளி கிராமுக்கு ரூபாய் %s.',
                    \App\Support\Money::group((float) $rate->gold), \App\Support\Money::group((float) $rate->silver))
                : sprintf('Today\'s rates: gold rupees %s per gram, silver rupees %s per gram.',
                    \App\Support\Money::group((float) $rate->gold), \App\Support\Money::group((float) $rate->silver))];
        }

        // Volume by voice — the box applies the action locally and confirms.
        // Check "down" first: phrases like "volume down" also contain "volume".
        if ($this->hits($q, ['volume down', 'quieter', 'softer', 'lower the volume', 'reduce the volume',
            'decrease volume', 'sound down', 'சத்தம் குறை', 'சத்தத்தை குறை', 'சத்தம் கம்மி', 'மெதுவா'])) {
            return ['volume_down', $ta ? 'சரி, சத்தத்தை குறைக்கிறேன்.' : 'Okay, turning the volume down.', 'volume_down'];
        }
        if ($this->hits($q, ['volume up', 'louder', 'increase volume', 'increase the volume', 'raise the volume',
            'sound up', 'சத்தம் கூட்டு', 'சத்தத்தை கூட்டு', 'சத்தம் அதிகம்', 'சத்தமா'])) {
            return ['volume_up', $ta ? 'சரி, சத்தத்தை கூட்டுகிறேன்.' : 'Okay, turning the volume up.', 'volume_up'];
        }

        // "Can you hear me / are you there / react" - a liveness check from the counter.
        if ($this->hits($q, ['can you hear', 'are you there', 'react', 'are you listening', 'respond', 'awake',
            'கேட்குதா', 'கேக்குதா', 'இருக்கியா', 'இருக்கீங்களா'])) {
            return ['ping', $ta ? 'ஆம், நான் இங்கே இருக்கிறேன், கேட்டுக்கொண்டிருக்கிறேன்.' : 'Yes, I am here and listening.'];
        }

        // Branch sales - counted and totalled from the sales invoices of THIS branch.
        // Checked before the clock intent: "sales today" must not read as "today".
        if ($this->hits($q, ['sales', 'sale ', 'sold', 'billing', 'invoice', 'bills', 'விற்பனை', 'பில்'])) {
            if (! $device->branch_id) {
                return ['sales_unavailable', $ta ? 'இந்த பெட்டி எந்த கிளையுடனும் இணைக்கப்படவில்லை.' : 'This box is not linked to a branch yet.'];
            }
            $month = $this->hits($q, ['month', 'மாதம்']);
            $rows = \App\Models\SalesInvoice::query()->where('branch_id', $device->branch_id)
                ->when($month, fn ($w) => $w->whereBetween('date', [Carbon::now()->startOfMonth(), Carbon::now()->endOfDay()]))   // datetime bounds: safe whether 'date' is stored as a date or a datetime
                ->when(! $month, fn ($w) => $w->whereDate('date', Carbon::today()));
            $count = (int) $rows->count();
            $total = \App\Support\Money::group((float) $rows->sum('net_total'));
            if ($month) {
                return ['sales_month', $ta
                    ? sprintf('இந்த மாதம் இந்த கிளையில் %d பில்கள், மொத்தம் ரூபாய் %s.', $count, $total)
                    : sprintf('This month this branch billed %d %s for rupees %s.', $count, $count === 1 ? 'invoice' : 'invoices', $total)];
            }

            return ['sales_today', $ta
                ? sprintf('இன்று இந்த கிளையில் %d பில்கள், மொத்தம் ரூபாய் %s.', $count, $total)
                : sprintf('Today this branch billed %d %s for rupees %s.', $count, $count === 1 ? 'invoice' : 'invoices', $total)];
        }

        // Card reads / visitors - staff RFID taps this box logged today.
        if ($this->hits($q, ['card', 'tap', 'rfid', 'visitor', 'staff', 'employee', 'people', 'attendance',
            'கார்டு', 'பார்வையாளர்', 'வருகை', 'ஊழியர்'])) {
            $taps = \App\Models\EmployeeVisit::query()->where('device_id', $device->id)->whereDate('visited_at', Carbon::today());
            $count = (int) $taps->count();
            $people = (int) (clone $taps)->distinct()->count('employee_profile_id');

            return ['card_reads', $ta
                ? sprintf('இன்று இந்த பெட்டியில் %d கார்டு தட்டல்கள், %d ஊழியர்கள்.', $count, $people)
                : sprintf('Today this box read %d %s from %d %s.', $count, $count === 1 ? 'card' : 'cards',
                    $people, $people === 1 ? 'staff member' : 'staff members')];
        }

        if ($this->hits($q, ['time', 'date', 'what day', 'which day', 'நேரம்', 'மணி', 'தேதி', 'நாள்'])) {
            $now = Carbon::now();

            return ['datetime', $ta
                ? sprintf('இன்று %s, நேரம் %s.', $now->translatedFormat('j F Y'), $now->format('g:i'))
                : sprintf('Today is %s and the time is %s.', $now->format('l, jS F Y'), $now->format('g:i A'))];
        }

        if ($this->hits($q, ['branch', 'shop', 'store', 'address', 'where are you', 'where am i', 'location', 'கிளை', 'கடை', 'முகவரி', 'எங்கே'])) {
            $name = $device->branch?->name ?? 'LORD Jeweller';

            return ['branch', $ta
                ? sprintf('இது லார்ட் ஜுவல்லர், %s கிளை. உங்களை வரவேற்கிறோம்!', $name)
                : sprintf('This is LORD Jeweller, %s branch. Welcome!', $name)];
        }

        if ($this->hits($q, ['hello', 'hi ', 'vanakkam', 'வணக்கம்', 'ஹலோ'])) {
            return ['greeting', $ta
                ? 'வணக்கம்! தங்கம், வெள்ளி விலை, நேரம், கிளை விவரங்கள் கேளுங்கள்.'
                : 'Hello! Ask me about gold and silver rates, today\'s sales, card reads, the time, or this branch.'];
        }

        if ($this->hits($q, ['thank', 'நன்றி'])) {
            return ['thanks', $ta ? 'நன்றி! மீண்டும் வருக.' : 'You are welcome. Visit again!'];
        }

        // Tier-2 hook: an LLM (Ollama/Claude) could take over here later.
        return ['fallback', $ta
            ? 'மன்னிக்கவும், அது எனக்குத் தெரியவில்லை. தங்கம், வெள்ளி விலை, நேரம், கிளை விவரங்கள் கேட்கலாம்.'
            : 'Sorry, I do not know that yet. You can ask about gold and silver rates, today\'s sales, card reads, the time, or this branch.'];
    }

    protected function hits(string $q, array $keywords): bool
    {
        foreach ($keywords as $k) {
            if (mb_stripos($q, $k) !== false) {
                return true;
            }
        }

        return false;
    }
}
