# Migration plan — legacy "October closing" database → lordicl-next (live)

Companion to `docs/ROLLOUT.md` (the generic runbook). This page is the ordered plan for the
cut-over from the October-2026 closing dump, with the reconciliation that proves earnings,
stock and contracts came across in full.

## 0. Ground rules

- The old live `lordicl_next` database (and the legacy `lordicl` on the server) are **kept as they are** until the
  new build is verified. Nothing is dropped; the new build goes into a **new database** and `.env` is switched at the end.
- The October closing dump is the **final** legacy state. Anything typed into the legacy app after the dump is
  not migrated → legacy goes read-only / maintenance page the moment the dump is taken.
- Every step below is run on the PC first (plan A in ROLLOUT.md), then the finished database is shipped
  to the server as one dump. The server never runs the heavy import.

## 1. Receive the dump (T+0)

```bash
# keep the current local snapshot
C:\wamp64\bin\mysql\mysql8.2.0\bin\mysqldump -uroot --single-transaction lordicl > Desktop\db-backups\lordicl-pre-oct-closing.sql
# load the October closing dump as the legacy source
mysql -uroot -e "DROP DATABASE IF EXISTS lordicl; CREATE DATABASE lordicl CHARACTER SET utf8mb4"
mysql -uroot lordicl < <october-closing>.sql
```

Sanity before anything else (all against `lordicl`):

| check | expect |
|---|---|
| `SELECT MAX(bdate) FROM tbl_bond` | the closing date (≈ 2026-10-31) |
| `SELECT MAX(estdate) FROM tbl_salesinvoice` | the closing date |
| `SELECT date, status, COUNT(*), SUM(nettotal) FROM tbl_gap_log GROUP BY 1,2` | Oct run present (PAID or PENDING) |
| `SELECT brid, storename FROM tbl_branches WHERE brid = 1` | LORD JEWELLER, Sivakasi |
| `SELECT COUNT(*) FROM ci_users` | dealer logins |

## 2. Dry run + baseline (T+10 min)

```bash
php artisan legacy:rollout --dry-run          # row counts per legacy table → target table
```

Record the **baseline totals** from legacy (the import must reproduce them):

```sql
SELECT status, COUNT(*), SUM(comamount)            FROM tbl_iccom        GROUP BY status;          -- IC
SELECT status, COUNT(*), SUM(nettotal), SUM(grandtotal), SUM(tds), SUM(servicetax) FROM tbl_gap_log GROUP BY status; -- GAP
SELECT status, COUNT(*), SUM(comvalue)             FROM tbl_reseller_com GROUP BY status;          -- billing margin
SELECT cbcavailabilty, COUNT(*), SUM(cbworth)      FROM tbl_cbc          GROUP BY 1;               -- CBC (PASSED = paid, PENDING = scheduled)
SELECT SUM(walletamount), SUM(couponwallet), SUM(epinwallet), SUM(tearningval), SUM(twithdrawlval) FROM tbl_member;  -- wallets
SELECT bondstatus, COUNT(*), SUM(bvalue), SUM(epinvalue) FROM tbl_bond   GROUP BY bondstatus;      -- contracts
SELECT COUNT(*), SUM(bvalue)                       FROM tbl_rdentry;                               -- Gold Saving instalments
SELECT catename, COUNT(DISTINCT branchid), SUM(weight) FROM tbl_stock WHERE weight > 0 GROUP BY catename; -- stock (GOLD/SILVER grams, CASH ₹)
SELECT COUNT(*), SUM(gold_wt) FROM tbl_order_digiunique; SELECT status, COUNT(*), SUM(wdgoldgm) FROM tbl_digiwithdrawal GROUP BY status; -- digi
SELECT route, deliverystatus, COUNT(*) FROM tbl_digi_queue GROUP BY 1,2;                          -- pending gold QRs
```

## 3. Build the new database locally (T+15 min, ≈ 15–20 min run)

```bash
mysql -uroot -e "CREATE DATABASE lordicl_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
set DB_DATABASE=lordicl_live
php artisan config:clear
php artisan migrate --force
php artisan db:seed --class=RolloutSeeder --force
php -d memory_limit=1024M artisan legacy:rollout --fresh --seed-plans
php artisan db:seed --class=CashStockSeeder --force
php artisan pincodes:import storage/app/pincodes/all_india.csv
php artisan branches:pincodes --fill
php artisan branches:static-qr
php artisan push:import-fcm <firebase service-account json>
php artisan branches:auto-map            # report only → review → --apply
php artisan members:auto-map             # report only → review → --apply
php artisan branches:audit-sources
```

(`branches:geocode` is slow — run it on the server afterwards, it only adds map pins.)

## 4. Reconcile — earnings

| stream | legacy source | target | proof |
|---|---|---|---|
| Sales Incentive (IC) | `tbl_iccom` (every row) | `commission_ledger` type IC, one row each, status PAID→paid, PASSED→approved, else pending | count + SUM(amount) by status equal; spot-check the top 10 earners |
| Turnover salary (GAP) | `tbl_gap_log` (one row per member per monthly run; `tbl_memberearning_level` is the same money at row level — **never add both**) | `commission_ledger` type GAP, one line per month with TDS + service charge, `GAP <month> ×n` | SUM(nettotal)=SUM(amount), SUM(grandtotal)=SUM(net_amount) by status; Oct PENDING rows appear in Commission Approval |
| Promotional Incentive (CBC) | `tbl_cbc` — the **whole pre-scheduled run**, post-dated to 2028 | `cbc_entries` one row each (PASSED→paid, PENDING→pending, original date + code); `bonds.cbc_issued` = scheduled rows so the engine never adds extra coupons | count by status equal; `MAX(cbc_date)` equal; `SELECT COUNT(*) FROM bonds WHERE status='active' AND cbc_value>0 AND cbc_issued<cbc_count` ≈ 0 |
| Dealer billing margin | `tbl_reseller_com` (every row) | `reseller_commissions`, one row each | count + SUM(com_value) by status equal |
| Dealer accrued margins / digi cash | `ci_users` (billing, gold GM, silver GM, transfer, digicash) | `branches.*_margin`, `digi_cash_balance` | per-dealer sample vs ci_users |
| Wallets | `tbl_member` walletamount / couponwallet / epinwallet / tearningval / twithdrawlval | `member_wallets` (balances copied, **not** recomputed from ledgers → no double credit) | SUM of each column equal |
| Digi gold | `tbl_order_digiunique` − `tbl_digiwithdrawal` | one OPENING line + `member_wallets.digi_gold_grams`; pending withdrawals / queue rows kept | SUM(grams) equal; negative members listed in the import warnings |

Decision to take with the board before approval runs on the new system:

- Legacy **PASSED** IC rows (417 rows / ≈₹9.7 L in the Sept snapshot) land as `approved` and still show in Commission Approval
  because they are not `paid`. Confirm whether legacy had already credited them to `walletamount`; if yes, mark them paid at
  import (one SQL line) so they are not paid twice.
- The Oct GAP run is PENDING in legacy → approve it on **Commission Approval** in the new system (credits wallets there).

## 5. Reconcile — stock

| item | legacy | target | proof |
|---|---|---|---|
| Gold / silver stock per branch | `tbl_stock` GOLD/SILVER rows, `SUM(weight)` per branch + product + purity | `stock.quantity` (pieces of the catalog item; `catalog_products.default_weight` from the name gives grams) | per-branch `SUM(weight)` vs `SUM(quantity × unit weight)`; HQ #1 included |
| Branch cash | `tbl_stock` CASH (weight = ₹) | `stock` row of the CASH product (CashStockSeeder, **sets**, never adds) | per-branch ₹ equal |
| Opening count sheet | board's Excel (physical count) | `stock:import-opening` **after** the roll-out — sets quantity + min_qty, writes an `opening` movement | run with `--dry-run` first; import only the branches whose count differs from the legacy book figure |
| Movement history | none in legacy | starts empty (opening balances only) | — |

## 6. Reconcile — contracts

| item | legacy | target | proof |
|---|---|---|---|
| Contracts (bonds) | `tbl_bond` every row, same id | `bonds` (plan matched by name to the v2 sheet, value, CBC/IC counters, return date, MOU, **level-1 upline frozen at sale** (`upline_member_id` ← `uplineuserid`), bondstatus 1→active else closed) | count + SUM(value) by status equal; `MAX(bond_date)` = closing date; skipped rows = members/plans not found (listed by the import) |
| Gold Saving instalments | `tbl_rdentry` | `rd_entries` per bond | count + SUM equal |
| Contract documents / settlements | not in legacy | `member_contracts` **is empty for legacy bonds** (new system creates it at billing only). Sales & Contracts shows the bonds; the Settlements page (expired contract → Generate settlement → wallet) only sees contracts created in the new system | decision: build a one-off `contracts:backfill` that creates a contract row per legacy active bond (start = bond date, end = + plan term) if the board wants legacy maturities settled through the new screen |
| Pending gold QRs | `tbl_digi_queue` INVOICE PENDING | `redeemable_qrs` pending | count equal |
| Orphan contracts | 14 `tbl_bond` rows whose distributor was deleted from `tbl_member` (8 still active, e.g. bond 1465 G12 ₹1,00,000) | imported under an **inactive placeholder member** (code kept, name from the bond, upline from the bond) so the contract and the uplines' GAP survive; listed in the import warnings | the only skipped bonds are the 16 blank rows with no plan and no value |

## 7. Ship to the server (T+1 h)

```bash
mysqldump -uroot --single-transaction lordicl_live > lordicl_live-oct-closing.sql
scp lordicl_live-oct-closing.sql root@srv950347:/www/backup/
# on the server
cd /www/wwwroot/next.lordicl.com && git pull && composer install --no-dev --optimize-autoloader
mysql -e "CREATE DATABASE lordicl_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"   # aaPanel → Databases, grant the app user
mysql lordicl_live < /www/backup/lordicl_live-oct-closing.sql
# .env: DB_DATABASE=lordicl_live   (old DB untouched)   LEGACY_DB_DATABASE=lordicl (closing copy, read-only — the P&L / Employee reports read it)
php artisan migrate --force && php artisan storage:link
php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan optimize
php artisan branches:geocode
php artisan golive:check
```

## 8. Verify on live (before telling the dealers)

- Login as super admin, a dealer (legacy password), a distributor (app): My Earnings shows the full IC / GAP / CBC / margin history; Commission Approval shows the Oct GAP + pending IC; Sales & Contracts default Active list matches legacy counts; Stock page per branch; Master → Branches → Static QR prints.
- Activity Log shows the import run (`legacy:rollout`) and nothing else from the build.
- App: price list, store locator, Wallet, Gold Saving, Digi Market purse.
- Then: legacy app → maintenance page; change `admin@lordicl.com` password; announce.

## Known limits after cut-over

- Profit & Loss and Employee reports read the **legacy** database. From November the GAP / IC / CBC generated by the new engine live in `commission_ledger` / `cbc_entries`, not in `tbl_gap_log`, so those reports stop at the closing month until they are re-pointed at the new tables.
- Months before Sept 2026 have no GAP rows in either legacy table (the legacy script keeps only the runs it generated); nothing can be migrated for them.

## 9. Trial build from the October closing dump (1 Nov 2026, 01:10–02:00)

Dump: `lordicldb.sql` (phpMyAdmin 5.2.2, MariaDB 10.11, 265 MB, generated 1 Nov 02:41 server time, **before** the legacy
October Ascript run — no `tbl_gap_log` / `tbl_memberearning_level` rows dated 2026-11-01). Loaded with the mysql client
(phpMyAdmin times out at 360 s) in 9 min. Local databases now:

| database | what |
|---|---|
| `lordicl` | the closing dump — the legacy app (`localhost/lordicl`) points here; the October Ascript trial is run on it |
| `lordicl_octclosing` | untouched clone of the dump (+ scratch indexes); the roll-out import reads it via `LEGACY_DB_DATABASE` |
| `lordicl_live` | the new build (`DB_DATABASE=lordicl_live`, env override — `.env` untouched, dev `lordicl_next` untouched) |

Build (§3 commands, `DB_DATABASE=lordicl_live LEGACY_DB_DATABASE=lordicl_octclosing`): migrate + RolloutSeeder + `legacy:rollout
--fresh --seed-plans` (25 min) + CashStockSeeder + pincodes + static QR + auto-map reports + audit = 29 min, no errors.
FCM import skipped (no service-account JSON on this PC — do it on the server).

### Reconciliation result

| item | legacy | new | verdict |
|---|---|---|---|
| GAP log (`tbl_gap_log` → GAP month lines) | 8,181 PAID ₹9,21,06,226 / 4 PENDING ₹65,250 | identical, net identical | **exact** |
| Wallets (cash / coupon / EPIN / earned / withdrawn) | 49,99,900 / 0 / 1,04,01,069 / 42,31,145 / 26,93,58,813 | identical | **exact** |
| Contracts (bonds) | active 27,559 ₹178.48 cr, closed 2,054 ₹3.14 cr | active 27,542 (17 blank rows skipped, same ₹), closed 2,054 | **exact by value** |
| Gold / silver stock (grams, `stock.quantity`) | 93,322 g / 59,717 g | 93,322 / 59,717 | **exact** |
| Billing margin | 62,450 rows ₹4,77,73,249.37 | 62,450 rows ₹4,77,73,249.39 | 2 paise rounding |
| Gold Saving instalments | 37,053 ₹8,73,22,628 | 37,050 ₹8,73,18,128 (3 rows of skipped blank bonds) | ok |
| Pending gold QRs (`tbl_digi_queue` → `digi_queue`) | 5,348 | 5,324 (24 of deleted members) | ok |
| CBC coupons | 37,944 PASSED / 123,756 PENDING | 37,916 paid / 123,748 pending | 36 rows of deleted members (none due Nov) |
| IC rows | 594,395 PAID / 11,455 PENDING / 417 PASSED | 594,359 / 11,173 / 389 | 309 rows (₹1.29 L) belong to members deleted in legacy; rest to skipped bonds |
| Digi gold | 2,071.40 g net of PASSED withdrawals | 1,578.41 g | new = net of **all** withdrawals (pending 493.60 g reserved) + 7 negative members clamped (5.60 g) + 2.23 g held by placeholder members — per-member diff = 0 |
| Branch cash | ₹11,30,14,998 | ₹11,24,04,998 after the seeder fix (was ₹6.76 cr) | ₹6,10,000 sits on 9 legacy `tbl_stock` rows whose branch was deleted from `tbl_branches` (brid 250, 281, 331, 333, 335, 451, 456, 481, 613) — unreachable in legacy too |
| Members | 16,995 | 17,008 = 16,995 + 13 inactive placeholders (deleted distributors who still own contracts) | ok |

**Fix made:** `CashStockSeeder` mapped legacy branches by name only; legacy has 1,180 `tbl_branches` rows named
"LORD JEWELLER" (one per city), so they all collapsed onto one branch and only the last row's cash survived (₹4.5 cr lost).
It now maps by name + city exactly as `LegacyRolloutImport` does and **sums** every legacy row that lands on the same branch
(HQ now ₹4.81 cr: brid 1 + the other Sivakasi "LORD JEWELLER" rows the import merges into it).

### New-engine October cut-off on the trial build

`payout:cutoff --cutoff=2026-10-31` on `lordicl_live` (run #1, 38 s): **12,643 bonds, 27,649 GAP rows, 4,921 members,
₹5,67,82,340 gross; 11,624 CBC coupons due ₹22,09,62,037** (log `storage/app/payouts/cutoff_2026-11_20261101_015424.log`).
Legacy's own pending-Nov coupon list: 11,624 / ₹22,09,71,037. The ₹9,000 gap is two known rows:

- coupon 55314 (₹15,000, LJW24710584324) belongs to bond 9973, which was **deleted from `tbl_bond`** — legacy Ascript will
  still PASS it (it works by date) and credit ₹6,000 EPIN; the new system has no contract to hang it on. 24 such orphan
  coupons exist (16 PASSED, 8 PENDING, this is the only one due in Nov).
- bond 133 (LJW24801, P201 ₹50,000, 20 of 24 coupons issued) has **no coupon rows at all** in legacy — its schedule was never
  created, so legacy will never pay its last 4. The new engine issued coupon 21 (₹6,000) for Nov. Board to decide: pay the
  remaining 4 (contract says so) or set `cbc_issued = cbc_count` to match legacy.

Next: when the legacy Ascript trial has run on `lordicl` (rows dated 2026-11-01 appear), run
`DB_DATABASE=lordicl_live php artisan payout:compare --period=2026-11-01 --export=...` — GAP register per member, GAP log
totals, CBC due. Expected: 100 % on GAP (the 24-Oct replay matched 4,470/4,470 members once the frozen upline was imported);
CBC differs by exactly the two rows above.
