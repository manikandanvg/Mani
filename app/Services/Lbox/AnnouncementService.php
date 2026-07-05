<?php

namespace App\Services\Lbox;

use App\Models\Device;
use App\Models\VoiceAnnouncement;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Voice queue for L-BOX boxes. Producers (payment webhook, HQ actions) queue lines;
 * each box polls its pending lines, speaks them in order, and ACKs. Nothing here
 * blocks the producer — a dead box just accumulates pending rows.
 */
class AnnouncementService
{
    public function __construct(protected VoiceRenderService $voice)
    {
    }

    /** Queue a line on every ACTIVE box of a branch. Returns how many boxes got it. */
    public function queueForBranch(?int $branchId, string $type, string $message, array $payload = []): int
    {
        if (! $branchId) {
            return 0;
        }

        $devices = Device::where('branch_id', $branchId)->where('status', 'active')->get();
        foreach ($devices as $device) {
            $this->queue($device, $type, $message, $payload);
        }

        return $devices->count();
    }

    public function queue(Device $device, string $type, string $message, array $payload = []): VoiceAnnouncement
    {
        return VoiceAnnouncement::create([
            'device_id' => $device->id,
            'type' => $type,
            'message' => $message,
            'payload' => $payload ?: null,
            'audio_path' => $this->voice->render($message, $device->language ?? 'en'),
            'status' => 'pending',
        ]);
    }

    /**
     * Device poll: oldest pending lines, marked delivered. Lines delivered over a
     * minute ago but never ACKed re-deliver — a box that dies mid-speak (power cut,
     * crash) gets the line again instead of silently losing it.
     */
    public function pull(Device $device, int $limit = 5): Collection
    {
        $pending = $device->announcements()
            ->where(fn ($q) => $q
                ->where('status', 'pending')
                ->orWhere(fn ($q2) => $q2->where('status', 'delivered')
                    ->where('delivered_at', '<', Carbon::now()->subMinute())))
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($pending->isNotEmpty()) {
            VoiceAnnouncement::whereIn('id', $pending->pluck('id'))
                ->update(['status' => 'delivered', 'delivered_at' => Carbon::now()]);
        }

        return $pending;
    }

    /** Device ACK after speaking. */
    public function ack(Device $device, array $ids): int
    {
        return $device->announcements()
            ->whereIn('id', $ids)
            ->where('status', 'delivered')
            ->update(['status' => 'acked', 'acked_at' => Carbon::now()]);
    }
}
