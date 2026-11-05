<?php

namespace App\Jobs;

use App\Services\CommissionApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Approve a stream's un-paid rows in the background (user 2026-11-03: the 1 Nov CBC
 * approval — 11,624 coupons for 8,843 members — was killed by the web-server timeout after
 * 88 rows). The database queue is drained once a minute for 50 s (routes/console.php), so
 * each run works for WORK_SECONDS and re-dispatches itself until every row is paid; the
 * Commission Approval list empties as the rows go through.
 */
class ApproveCommissionsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Work per run — under the worker's 60 s job timeout and its 50 s max-time (tests shrink it). */
    public static float $workSeconds = 40;

    public int $timeout = 58;

    public int $tries = 3;

    /**
     * @param  int[]|null  $beneficiaryIds  member ids (IC/GAP/CBC) or branch ids (margins); null = everyone listed
     * @param  int[]|null  $planIds  CBC only: just the coupons of these schemes (the page's Scheme filter)
     */
    public function __construct(
        public string $type,
        public ?string $from,
        public ?string $to,
        public ?array $beneficiaryIds = null,
        public ?int $by = null,
        public int $round = 1,
        public ?array $planIds = null,
    ) {}

    public function handle(CommissionApprovalService $svc): void
    {
        $res = $svc->approveBatch($this->type, $this->from, $this->to, $this->beneficiaryIds, self::$workSeconds, $this->by, $this->planIds);

        if (! $res['done']) {
            self::dispatch($this->type, $this->from, $this->to, $this->beneficiaryIds, $this->by, $this->round + 1, $this->planIds);
        }
    }
}
