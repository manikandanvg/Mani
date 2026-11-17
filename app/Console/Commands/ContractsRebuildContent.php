<?php

namespace App\Console\Commands;

use App\Models\MemberContract;
use App\Services\Contract\ContractContentBuilder;
use Illuminate\Console\Command;

/**
 * A contract keeps the body it was billed with (member_contracts.content). When a scheme's
 * contract wording is corrected — 2026-11-17: the Sub Dealer contract gained its gold / contract
 * split and the Promotional Incentive (CBC) benefit — the contracts already issued still print
 * the old body. This rebuilds the stored body of one scheme's contracts from their bonds.
 * Contracts with no stored body (old-system contracts) are built on the fly and need nothing.
 *
 *   php artisan contracts:rebuild-content --plan=P213            dry run — how many would change
 *   php artisan contracts:rebuild-content --plan=P213 --apply    rewrite them
 */
class ContractsRebuildContent extends Command
{
    protected $signature = 'contracts:rebuild-content {--plan=P213 : scheme code} {--apply : write the new body}';

    protected $description = 'Rebuild the stored contract body of one scheme\'s contracts after its wording was corrected';

    public function handle(ContractContentBuilder $builder): int
    {
        $code = (string) $this->option('plan');
        $apply = (bool) $this->option('apply');
        $seen = $changed = 0;

        MemberContract::query()
            ->whereNotNull('content')->where('content', '!=', '')
            ->whereHas('bond.plan', fn ($q) => $q->where('code', $code))
            ->with('bond.plan')
            ->chunkById(200, function ($contracts) use ($builder, $apply, &$seen, &$changed) {
                foreach ($contracts as $contract) {
                    $seen++;
                    $body = $builder->build($contract->bond);
                    if ($body === $contract->content) {
                        continue;
                    }
                    $changed++;
                    if ($apply) {
                        $contract->update(['content' => $body]);
                    }
                }
            });

        $this->info(sprintf('%s: %d contract(s) with a stored body, %d %s.', $code, $seen, $changed,
            $apply ? 'rewritten' : 'would be rewritten — run with --apply'));

        return self::SUCCESS;
    }
}
