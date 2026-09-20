<?php

namespace App\Support\BusinessModel;

use App\Models\Plan;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where the Business Model Report reads its contracts (bonds) from. Two sources:
 * the legacy Lord DB (`lordicl`.tbl_bond — the live book the board knows) and the
 * new system's own tables. Scheme facts always come from the new plan master; only
 * the contract-level figures switch.
 *
 * Every contract is a plain object with:
 *   id, contract_no, bond_id, member_id, member, branch, amount, monthly,
 *   start_date (Carbon), end_date (?Carbon), status ('active'|'closed'|'matured'),
 *   settled_on (?Carbon), is_rd (bool)
 */
interface ReportSource
{
    public const LEGACY = 'legacy';

    public const NEXT = 'next';

    public function key(): string;

    public function label(): string;

    /** Earliest contract start on record (default From date). */
    public function firstDate(): ?Carbon;

    /** Contracts of a plan started inside the window, oldest first. */
    public function contracts(Plan $plan, Carbon $from, Carbon $to): Collection;

    /** RD installments per bond id (paid_on Carbon, value float), dated on/before $asOf. */
    public function rdEntries(array $bondIds, Carbon $asOf): Collection;

    /** Gold QR allocations per bond id (created_at Carbon, gram_worth, cash_worth, status 'pending'|'redeemed'). */
    public function qrs(array $bondIds, Carbon $asOf): Collection;

    /** Cash settlements per contract id (amount, paid_on Carbon). */
    public function settlements(array $contractIds, Carbon $asOf): Collection;

    /** Overview counters for the window — see BusinessModelReport::overviewSection for the keys. */
    public function overview(Carbon $from, Carbon $to): array;

    /** Active dealers / branches on a scheme level. */
    public function dealersOnScheme(Plan $plan, ?string $level): int;

    public function dealersLabel(): string;

    /**
     * True when the source keeps no settlement register because settlements were handed
     * over by hand (Lord DB): a settlement whose due date has passed is then reported as
     * handed over at the scheme-rule value, not as missing.
     */
    public function settlesOffline(): bool;

    /**
     * Dealer LOGINS at a level (board 2026-09-17 "Dealers Report": the branch users, not
     * the bonds). Level keys: zonal, district, taluk, wholesaler, reseller. Each row:
     * name, uid, branch, start_date (Carbon), amount = full value of ALL the dealer's
     * dealership bonds (invested when none), allocation = gold allocated on those bonds
     * (null when nothing is recorded), bonds = how many.
     */
    public function dealerLogins(string $level): Collection;

    /** Null when the source has the plan; otherwise a note explaining why its rows are empty. */
    public function coverageNote(Plan $plan): ?string;
}
