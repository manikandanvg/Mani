# Monthly cut-off (income generation) — legacy procedure → new system

Written 2026-10-31, before the build. Companion to `docs/MIGRATION-OCT-CLOSING.md`.

## 1. What legacy does today (read from the code)

| step | legacy screen | what happens |
|---|---|---|
| 1. Generate | `ascript/index` (`Ascript.php`), cut-off date **hard-coded** in the file (`$bdateact`, edited every month) | **Phase 1 GAP**: every active bond with `lvlcomone > 0`, `lvlcomissue < lvlcomcount`, `bdate ≤ cut-off`. The five level amounts are the **rupee figures stored on the bond** (`lvlcomone…five`, fixed at sale). Level *n* goes to the *n*-th upline only if that upline's rank allows it (`mylevel − 1 ≥ n`; rank 0/1 earn nothing). One row per level per bond into `tbl_memberearning_level` (comid 5 GAP, status ACTIVE, date = 1st of the month after the cut-off), `tearningval` += amount, bond `lvlcomissue + 1`. No earning-potential cap, no task score, no proration (the 28/30-day code always yields ×1). |
| | | **Phase 2 GAP log**: per member, sum of this run's rows → `tbl_gap_log` (pmcount, nettotal, TDS 5 %, service 5 %, grandtotal, PENDING, remarks = month). Rows marked PAID in the register. |
| | | **Phase 3 CBC**: coupons with `cbcdate` = that 1st-of-month and PENDING → PASSED, **40 % of worth credited to `epinwallet` immediately**, bond `cbcissue + 1`. |
| | | A text log `logs/ascript_payout_<stamp>.log` lists every decision (bond, upline checked, depth, amount). |
| 2. Review | `admin/generate/breportmonth` | month report of the generated figures. |
| 3. Approve | `admin/reports` → "Level Commission (digi)" = GAP, "Sales Profit Commission (digi)" = CBC | **GAP**: per selected member, Σ `nettotal − (20 % if nettotal ≥ 14,050 else 10 %)` → one **digi-gold purchase** at the day's gold rate (`tbl_order_digiunique`, mode LEVEL), `tbl_gap_log` → PAID, batch logged in `tbl_digi_waout`. **CBC**: the payable shown on the report → digi-gold purchase (mode SP). IC ("digi") and dealer billing margin go the same way. |

Two legacy quirks worth knowing: (a) `$insetfirstcom` is never reset per bond, so once any level-1 payout has happened, **every later bond in the loop gets `lvlcomissue + 1` even when nobody qualified**; (b) the stored TDS + service (5 + 5) in `tbl_gap_log` is not what approval deducts (10 % or 20 % by the ₹14,050 slab).

## 2. What the new system has today

- `php artisan engine:run [--period=YYYY-MM] [--only=recompute|gap|cbc|settlement]`: recompute BV/ranks → GAP → CBC → settlements.
- GAP rows go to `commission_ledger` (one row per level per bond, pending), CBC to `cbc_entries` (pending) — **nothing touches a wallet** until Commission Approval.
- Commission Approval (HQ) approves per stream: IC/GAP → cash wallet net of TDS + service (per-stream % from Commission Setup, default 5 + 5); CBC → 40 % EPIN + 60 % coupon wallet. Every approval is in the Activity Log.

### Differences that would break "100 % the same as legacy"

| # | new engine today | legacy | effect |
|---|---|---|---|
| 1 | GAP amount = **plan % × bond value** (`plans.level_schedule`) | **bond's stored rupee amounts** | differs for every legacy bond whose v2 plan % ≠ its sale-time figure (e.g. bond 28042 P213: engine 300/120/60/60/60, legacy 400/160/80/80/80) |
| 2 | earning-potential cap (`capByEp`) and Monthly-Tasks factor applied | none | lower payouts for members who already earned more than their active bond value |
| 3 | `recompute` re-derives ranks from unpure BV before GAP | ranks as they stand in `tbl_member.mylevel` | qualification can change |
| 4 | period = "now" (bonds up to today) | explicit cut-off date, bonds **on or before** it | a bond sold on the 1st would be counted one run early |
| 5 | approval deducts flat per-stream % | 10 % / 20 % slab at ₹14,050 of the gross | net differs for members above the slab |
| 6 | payout to the **cash wallet** (board 2026-08-11) | **digi gold** at the day's rate | board decision, not a bug |
| 7 | CBC 40 % EPIN credited at **approval** | at **cut-off** | timing only |

## 3. Decision for tomorrow (1 Nov 2026 — the October cut-off)

**Run the October cut-off in legacy, exactly as every month** (`$bdateact = "2026-10-31"` → rows dated 2026-11-01, remarks "Nov"), review `breportmonth`, **approve in legacy too** (GAP and CBC as digi gold), and only then take the closing dump. Reasons:

- Zero parity risk for October: the figures the distributors see in the new app are the legacy figures, row by row (the importer carries `tbl_gap_log` per month, every `tbl_cbc` coupon, every digi-gold purchase into the opening balance).
- If the dump were taken *before* approval, the pending GAP would be approved in the new system under the new rules (flat 5 + 5 to the cash wallet) and would not match what legacy would have paid.

The November cut-off (1 Dec 2026) is the first on the new engine. The build below makes it legacy-exact and proves it before it is used.

## 4. Build: "legacy-parity cut-off" in the new system

1. **Cut-off run command** `payout:cutoff --cutoff=2026-11-30` (period = 1 Dec):
   - GAP amounts from the **bond's stored `lvlcom` amounts** (every bond has them: legacy import and the new Sales screen both store them); plan % only as a fallback when the array is empty.
   - Qualification exactly as legacy: upline rank depth ≥ level (ranks are already imported as `mylevel − 1`).
   - Bonds with `bond_date ≤ cut-off` only; one instalment per bond per month (already idempotent).
   - **No EP cap, no task factor, no recompute** inside the cut-off (switches, default off; the board can turn the EP cap on later as a policy).
   - CBC: coupons dated on the period's 1st → passed, 40 % EPIN credit queued for approval as today (board gate kept).
   - Writes a **cut-off record** (`payout_runs`: period, cut-off date, who ran it, rows, bonds, members, gross, TDS, service, net, status generated → approved → paid, log file) and a full text log like `ascript_payout_*.log`, plus Activity Log rows.
   - Dry run first (`--dry-run` prints the totals and writes the log without inserting).
2. **Cut-off viewer** (HQ, Commissions → Payout Runs = `breportmonth`): the run header, per-member summary (= `tbl_gap_log` shape: count, gross, TDS, service, net), per-row detail, log download, export.
3. **Approval** stays on Commission Approval, with: slab deduction (≥ ₹14,050 → 20 %, else 10 %) configurable in Commission Setup next to the flat rates, and a per-run "approve all" that credits wallets and closes the run. Payout destination stays the cash wallet (board); an optional "pay as Digi gold at today's rate" switch can be added if the board wants the legacy habit kept.
4. **Proof of parity before 1 Dec**: a replay test that runs `payout:cutoff --cutoff=2026-10-31` on a copy of the October closing dump imported into a scratch database and diffs the result against legacy's own `tbl_memberearning_level` / `tbl_gap_log` / `tbl_cbc` for the same run — row counts, per-member gross, TDS, service, net, and the CBC passed list must match 100 % (allowing only the `$insetfirstcom` quirk, which we do **not** replicate; it changes `lvlcomissue` counters only, not amounts — board to confirm).

Estimated effort: command + run record + viewer + slab + replay test ≈ 1 working day, after the migration.

## 5. Built (2026-10-31 night) — and the first parity replay

| piece | where |
|---|---|
| Cut-off command | `php artisan payout:cutoff --cutoff=YYYY-MM-DD [--dry-run] [--ep-cap] [--task-factor] [--recompute] [--quiet-log]` → `App\Services\Payout\CutoffService` |
| Run record + log | `payout_runs` (period, cut-off, totals, switches, log path, run/approved by); `storage/app/payouts/cutoff_<month>_<stamp>.log` in the legacy `ascript_payout` format; GAP rows and CBC coupons carry `payout_run_id` |
| Viewer | Commissions → **Payout Runs** (HQ): totals, the per-member GAP log (rows, gross, TDS, service, net — the legacy `tbl_gap_log` shape), GAP rows per level per bond, log tail / download, xlsx of the GAP log |
| Approval | **Approve run** (super admin) = legacy admin/reports: every pending GAP row → cash wallet net of TDS + service, CBC coupons due → 40 % E-pin + 60 % coupon; or row by row on Commission Approval as before. No digi wallet. |
| Slab | Commission Setup → per stream "Slab from (₹ month total)", "TDS % above slab", "Service % above slab" (legacy: 14,050 → 10 + 10). For GAP the slab is judged on the member's **month total**, as legacy did on `tbl_gap_log.nettotal`. |
| Frozen upline | `bonds.upline_member_id` = legacy `tbl_bond.uplineuserid` (imported; the Sales screen stores the upline at sale). Level 1 is paid to it and levels 2–5 walk up from there — a distributor re-mapped after the sale does not move the contract's income (25 live contracts are in that state). |
| Parity check | `php artisan payout:compare --period=YYYY-MM-01 [--export=diff.xlsx]` — per member GAP rows + gross vs `tbl_memberearning_level`, the GAP log totals vs `tbl_gap_log`, CBC due vs `tbl_cbc`, on the `legacy` connection. Exit 1 when anything differs. |

**Replay of the legacy October run** (dev copy of the 24-Oct snapshot, imported Oct GAP lines removed, instalment counters rolled back one step, `payout:cutoff --cutoff=2026-09-30`): legacy 24,829 rows / 4,470 members / ₹5,13,48,590 gross; new 24,836 rows / 4,476 members / ₹5,13,47,428.60 **before** the frozen-upline rule — the 85 differing members were all explained by re-mapped uplines (frozen on the bond in legacy) plus rank changes between 1 Oct (legacy run) and 24 Oct (snapshot). The definitive check is the user's trial: legacy Ascript on the October closing DB vs `payout:cutoff --cutoff=2026-10-31` on the same DB imported, then `payout:compare --period=2026-11-01`.

Rules kept exactly as legacy: bond's stored level amounts; bonds on/before the cut-off; rank depth ≥ level; all-zero level amounts = no level income; one instalment per bond per pay month; every processed bond consumes an instalment (legacy's effective behaviour); 5 + 5 % at the log stage; nothing credited before approval. Not replicated: the EPIN 40 % credit at cut-off time (it is credited at approval, board gate) and legacy paying as digi gold (board: cash wallet).

## 6. Parity proven on the October closing data (1 Nov 2026)

Legacy Ascript (`$bdateact = "2026-10-31"`, run from the CLI on the local closing dump `lordicl`, log
`application/logs/ascript_payout_20261101_113027.log`, 1,230 s) versus `payout:cutoff --cutoff=2026-10-31` on the imported
copy `lordicl_live` (run #1, 38 s), compared with `payout:compare --period=2026-11-01`:

| | legacy | new |
|---|---|---|
| GAP register rows / members / gross | 27,649 / 4,921 / ₹5,67,82,340 | **identical** — 100 % per member |
| GAP log TDS / service / net | ₹28,39,117 / ₹28,39,117 / ₹5,11,04,106 | **identical** |
| CBC coupons due | 11,624 / ₹22,09,71,036.74 | 11,624 / ₹22,09,62,036.77 |
| contracts whose instalment counter moved | 12,642 | 12,643 |

The only differences are three known data rows, each for the board:

1. **Coupon 55314** (₹15,000, LJW24710584324): its contract 9973 was deleted from `tbl_bond`; legacy still passes it and credits
   ₹6,000 EPIN. The new system has no contract to attach it to, so it is absent.
2. **Bond 133** (LJW24801, P201 ₹50,000, 20 of 24 coupons): legacy never created its coupon schedule, so legacy pays nothing more;
   the new engine issued coupon 21 (₹6,000). Pay the remaining 4 or freeze — board.
3. **Bond 29909** (sold 31 Oct, no qualifying upline this month): legacy left `lvlcomissue` at 0 because of the `$insetfirstcom`
   quirk (it was the first bond in the loop); the new engine counted the instalment like it does for every other bond with no
   qualifier. No money moved either way this month; legacy will try instalment 1 again on 1 Dec, the new engine moves to
   instalment 2. The legacy behaviour for the other 12,642 contracts is what the engine reproduces.

`cbcissue` differs on 11,695 contracts by design: legacy counts **passed** coupons, the new `cbc_issued` counts **scheduled**
rows (so the engine never adds coupons beyond the legacy schedule).

## 7. Board ruling 1 Nov 2026 (evening): how a CBC coupon is paid — and no EPIN wallet

The cash wallet is the only payout destination. A CBC coupon is split **at approval** (Commission Approval row by row, or
Approve run for the month — same service, same rule), on the coupon's whole worth, percentages from Commission Setup:

| coupon ₹12,000 | default | goes to |
|---|---|---|
| sales profit (salary) | 50 % = ₹6,000 | member's **cash wallet** |
| TDS + service charge | 5 + 5 % = ₹1,200 | withheld |
| remainder | ₹4,800 | **coupon wallet** — a Head Office reference figure only: shown on Track Distributor to the super admin, never to dealers or members (app returns 0) |

The split is stored on the coupon (`cbc_entries.cash_amount / tds / service_charge / coupon_amount`) and the Payout Runs
page shows the month's three figures next to the coupons due. There is **no EPIN wallet** in the new system: the word
does not appear on any screen or in the app payload (keys stay at 0 for older builds); the legacy EPIN balances
(₹1,04,01,069 across 615 members) are imported but invisible — board to say whether they convert to cash or are dropped.
