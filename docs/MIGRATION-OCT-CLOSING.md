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
php artisan legacy:settle-digi-withdrawals --dry-run   # then without --dry-run: pending legacy digi-gold withdrawal requests -> cash wallets
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

## 7. Ship to the server — straight onto lordicl.com (board: no subdomain, no redirect)

Rebuild on the PC first: `bash scripts/rebuild-live.sh <final-dump.sql.gz> --cutoff=2026-10-31` (one command: §1–§4 + the
cut-over one-offs + a dry cut-off). Reconcile (§4–§6). Then:

```bash
# PC
mysqldump -uroot --single-transaction --quick lordicl_live | gzip > lordicl_live-closing.sql.gz      # upload via aaPanel Files
```

On the server (aaPanel, Apache + PHP-FPM):

1. **Domain.** Website → the stopped legacy site → Domain → remove lordicl.com + www (keep the site and its files: the legacy
   database stays for the P&L / Employee reports). Website → the new software's site → Domain → add lordicl.com + www →
   SSL → Let's Encrypt → Force HTTPS. Delete the old subdomain from that site afterwards; nothing references it.
2. **Code + database.**
   ```bash
   cd /www/wwwroot/<site> && git pull manikandanvg main && composer install --no-dev --optimize-autoloader
   mysql -e "CREATE DATABASE lordicl_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"   # aaPanel → Databases, grant the app user
   gzip -dc /www/backup/lordicl_live-closing.sql.gz | mysql lordicl_live
   # .env: APP_URL=https://lordicl.com  DB_DATABASE=lordicl_live  LEGACY_DB_DATABASE=lordicl  SESSION_DOMAIN=null
   php artisan migrate --force && php artisan storage:link
   php artisan config:clear && php artisan route:clear && php artisan view:clear && php artisan optimize
   php artisan push:import-fcm <firebase service-account json>
   php artisan branches:geocode          # slow, map pins only
   php artisan golive:check
   ```
3. **Holding mode until go-live** (Website → site → Config, inside `<VirtualHost *:443>` after `DocumentRoot`; office IP
   instead of the placeholder). Policy pages stay public for the Play review; everything else shows `public/holding.html`
   with a 503; the office IP sees the full site:
   ```apache
   ErrorDocument 503 /holding.html
   <IfModule mod_rewrite.c>
       RewriteEngine On
       RewriteCond %{REMOTE_ADDR} !^203\.0\.113\.10$
       RewriteCond %{REQUEST_URI} !^/page/(privacy-policy|terms|refund-policy|return-policy|shipping-policy)
       RewriteCond %{REQUEST_URI} !^/(css|js|build|storage|images|fonts|vendor)/
       RewriteCond %{REQUEST_URI} !^/(holding\.html|favicon\.ico|robots\.txt)$
       RewriteRule .* - [R=503,L]
   </IfModule>
   ```
   Remove the block and restart Apache on go-live day. Play Console privacy policy URL: `https://lordicl.com/page/privacy-policy`.
4. **Outside services that call us** (all on lordicl.com now): Razorpay webhook → `https://lordicl.com/webhooks/razorpay`;
   WhatsApp provider callback → `https://lordicl.com/api/v1/webhooks/whatsapp`; Zoom marketplace apps (allowed domain +
   event endpoint `https://lordicl.com/webhooks/zoom`); clear `WHATSAPP_TEST_RECIPIENT`.
5. **L-BOX devices** carry the API address in firmware settings: re-point both live boxes through the pairing command
   (docs/ROLLOUT.md) once the site answers on lordicl.com.
6. **App**: 3.11.15 / 31101501 (`https://lordicl.com/api/v1`) is the Play release; it works the moment the holding block is
   removed. Older legacy app versions stop at the switch and must update.

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

**Result (1 Nov, legacy Ascript run on `lordicl` from the CLI, then `payout:compare --period=2026-11-01`): GAP register
100 % match — 4,921 members, 27,649 rows, ₹5,67,82,340 gross, TDS / service / net identical; CBC due 11,624 = 11,624, worth
differs by the two rows above; one contract counter differs (bond 29909, legacy `$insetfirstcom` quirk). Full table in
docs/CUTOFF-PLAN.md §6. Diff workbook: Desktop/db-backups/oct-cutoff-compare.xlsx (empty = no GAP mismatch).**

## 10. Cut-over additions (1 Nov 2026, afternoon)

Decided with the user after the §9 report ("consider those too"):

| item | what happens now |
|---|---|
| Pending dealer orders (`tbl_brorderrequest` PENDING, 1,219 / ₹5.84 cr) | imported by `legacy:rollout` as PENDING order requests, source **legacy**, `request_no` LEG-<transid>, lines from `tbl_br_purchase_cart` (route BO/RO, product by legacy prid = catalog code). Mostly cash-stock top-ups (CASH product) plus coin orders. "RO" = payment received → `paid_amount`; legacy expiry and remarks kept in `payment_remarks`. The supplier approves/rejects them on Order Requests like any new order (approving a CASH line raises the dealer's cash stock, no seller leg). 10 orders of a non-imported branch and 53 without lines are listed in the import warnings. |
| Digi-gold withdrawal requests (`tbl_digiwithdrawal` PENDING, 1,001 / 493.59 g / ₹67,41,391) | `php artisan legacy:settle-digi-withdrawals` after the roll-out: worth fixed at request time → member **cash wallet**; request → delivered; the mirrored `digi_queue` DIGIWD ticket → redeemed so no dealer pays it in cash. Dry-run first. Activity Log entry with the totals. |
| HQ digi cash | the HQ admin's `ci_users` row (₹2,96,348 digi cash) is now summed into branch #1 (was skipped with the login). |
| Legacy logins without a branch (12, billing −₹4,03,506) | still skipped; the warning now shows the balances. Board: write off or assign. |
| Ownerless pending gold QRs (27, 686 g, ₹1.06 cr — distributor deleted in legacy) | not importable (no member). Board decision. |

Cut-over mechanics agreed the same day: the new software goes **straight onto lordicl.com** (no stop at a subdomain; the
subdomain is removed), the app is 3.11.15 / 31101501 with `https://lordicl.com/api/v1` baked in and is in Play review,
and until go-live the legacy site stays up but locked (routes-layer lock → holding page, office IP exempt) so the privacy
policy URL keeps answering for Play. The final legacy dump is taken **after** the lock is confirmed.

## 11. Readiness check of `lordicl_live` (1 Nov 2026, night) — the 14-point checklist

Database built from `lordicl_octclosing` (= the user's October closing dump, taken BEFORE the legacy October run) by
`scripts/rebuild-live.sh`. **It is a rehearsal build**: the ship build must come from the dump taken AFTER the October
approval in legacy, with the same script. Figures as of this build:

| # | question | answer on `lordicl_live` |
|---|---|---|
| 1 | cash QR pending | `digi_queue` route DIGIWD: 962 redeemed (settled into cash wallets by `legacy:settle-digi-withdrawals`), **17 pending / 18.175 g / ₹2,69,218** with no matching withdrawal request — board to rule (pay from cash wallet or leave for the dealer). Admin: Digi-Gold → Digi Queue. |
| 2 | gold QR pending | `digi_queue` route INVOICE: **5,108 gold / 5,545.75 g / ₹8.86 cr** and **216 silver / 949.2 g / ₹18.56 L**, all pending delivery; 133 of them reference a bond that no longer exists in legacy. Redeem at any branch with stock (Redeem screen). |
| 3 | digi-gold wallet | `member_wallets.digi_gold_grams` **1,578.41 g** across 8,798 members (net of every withdrawal request, pending ones already settled to cash), silver 0. Branch digi cash ₹4.19 L on `branches.digi_cash_balance`. |
| 4 | pending commission to approve | IC 11,173 rows ₹37.20 L (+ 389 legacy PASSED rows ₹9.19 L parked as `approved`); GAP 4 legacy rows ₹65,250 + run #1 27,649 rows ₹5.68 cr (October, generated, NOT approved); billing margin 62,450 rows ₹4.78 cr already `passed`; CBC: **11,351 coupons dated October 2026 worth ₹20.16 cr are PENDING in legacy too** — the legacy 1 Oct run never passed them (system collapse month); they sit on Commission Approval → CBC with the 50/10/40 split, board to approve or drop. 112,398 future coupons (₹208 cr) pending by date, as designed. |
| 5 | what lordicl_live holds | 17,008 members (16,995 + 13 placeholders), 225 branches, 29,596 contracts (27,542 active ₹178.5 cr / 2,054 closed), 29,608 invoices / 32,060 lines, 37,050 Gold Saving instalments, 641,755 ledger rows, 161,665 coupons, 62,450 margin rows, 664 stock rows (93,322 g gold / 59,717 g silver / ₹11.24 cr cash), 1,156 pending dealer orders (legacy), 1,001 settled digi withdrawals, 165,602 pincodes, 224 L-BOX devices, FCM push settings copied from dev. |
| 6 | base database | `lordicl_octclosing` = the user's `lordicldb.sql (4).zip` phpMyAdmin export (265 MB, 1 Nov 02:00) loaded untouched + scratch indexes; `lordicl` is the same dump with the user's legacy October trial run on it. |
| 7 | legacy data missing | only rows of members/branches deleted in legacy (36 coupons, 309 IC rows ₹1.29 L, 24 gold QRs ₹1.06 cr, ₹6.1 L cash on 9 deleted branches, 12 logins without a branch) and the 17 unmatched DIGIWD tickets — all listed in §9 and the import warnings. No settings/credential tables: `settings` (Commission Setup → defaults 5/5/50), `whatsapp_settings` (falls back to config/services.php), `kyc_settings`, `firmwares`, `task_types` are empty — fill on the live admin after go-live. |
| 8 | optimised | 2026-11-02 index migration (ledger/coupon/bond/contract/queue date + status indexes); cut-off runs in 44 s; Distributors list no longer offers "all" rows per page. On the server: `php artisan optimize` + OPcache (never on the dev PC). |
| 9 | things the board may miss | (a) **October-dated CBC ₹20.16 cr never passed in legacy** (item 4); (b) the old `engine:run --only=gap/cbc` scheduler steps were still armed for the 1st — RETIRED now, `payout:cutoff` is scheduled instead; (c) legacy contracts had no settlement engine: 730 contracts (₹98 L) reach their validity in Nov 2026, 714 (₹2.14 cr) in Dec, 678 (₹2.34 cr) in Jan — HQ now sees them on Settlements and decides each by hand (auto-settlement is OFF for legacy contracts; one-line switch in SettlementService if the board wants the plan rule applied); (d) EPIN balances ₹1.04 cr still on `epin_balance` with no screen (board: convert or drop); (e) WHATSAPP_TEST_RECIPIENT must be blank on the live .env or every OTP goes to the test phone. |
| 10 | G11 / +1 / +2 pending QR | Gold Saving plans: **PLUS1 (P209) 351 gold QRs ₹49.64 L + 10 silver ₹1.48 L; PLUS2 (P208) 648 gold ₹1.74 cr + 13 silver ₹58 K; PLUS3 (P200) 1,139 gold ₹1.61 cr + 54 silver ₹2.31 L** — all pending delivery in `digi_queue`. |
| 11 | closed contracts, where seen | 2,054 legacy-closed contracts: Sales & Contracts (status filter Closed) and now **Settlements** (`member_contracts` backfilled by `contracts:backfill-legacy`, source legacy, LEG-<bond id>); the app's My Contracts lists them with status closed. |
| 12 | Settlement table empty | it was: legacy contracts existed only as bonds. `contracts:backfill-legacy` writes one contract row per bond (29,596 — 27,542 active, 2,054 closed); `contract_settlements` stays empty until HQ generates the first settlement. Legacy `return_date` is never after the bond date, so the end date = bond date + plan validity. |
| 13 | monthly cut-off console only? | No longer: **Payout Runs → "Generate cut-off"** (super admin, date picker, refused if the month already has a run) plus the scheduler on the 1st 03:00 (`payout:cutoff`, pending rows) and `cron:run` on day 1 for daily-cron hosts. Console still works. |
| 14 | app wiring | 3.11.15 / 31101501 → `https://lordicl.com/api/v1`; `personal_access_tokens` empty, so every distributor logs in afresh (WhatsApp OTP). Members get coupon/EPIN = 0 from the API. Push needs the FCM row (copied) and APNs later. |

Distributors table (same day): one global search over every field incl. hidden columns and the senior/referred
distributor, every column sortable, **Cash wallet** column (go-live: distributors will bill and request payouts from
day one), filters Status / TBP Stage / Branch / KYC / Place / Cash > 0 / Has team / Date of Join, page sizes 10–100.

### Same night, after the dealer-dashboard screenshot

- **Gold / silver stock showed 0 g on dealer dashboards** (legacy: 76.50 gm / 75.00 gm for HARSHA): the catalog rows
  imported from legacy carry no `default_weight`; the widget multiplied pieces by NULL. The importer now derives grams
  per piece from the legacy name / `tbl_product.prdesc` (mg), the widget and `gramsFromPieces()` use `unitWeight()`
  (name-derived fallback), and the 23 catalog rows on `lordicl_live` / `lordicl_next` were filled. HARSHA now reads
  76.500 g / 75.000 g, same pieces (63 / 26).
- **Generate cut-off** asks for the board's static pass-phrase (`PAYOUT_CUTOFF_PASSWORD` in .env overrides the built-in
  one) and a confirmation tick; a wrong entry or an unticked box generates nothing, Cancel closes the box.
- **Domain move done by the user:** legacy site = `https://old.lordicl.com`; `lordicl.com` is idle for the new software.
  The OLD Android app still calls `lordicl.com/app/…` → the new site forwards every `/app/*` call (any method, 308, path
  + query kept) to `LEGACY_SITE_URL` (default old.lordicl.com); `/app/genealogy/*` (new app) stays. `next.lordicl.com`
  stays as an alias of the new site for 3.11.14 phones until they update.

## 12. Board ruling on the legacy pending QRs (1 Nov 2026, night) — two Commissions pages

The board does not want the cut-over to settle anything on its own ("various dates, various gold prices"). The
cut-over now **stages** the legacy pending items and Head Office decides on two pages under **Commissions**:

| page | rows | what HQ does |
|---|---|---|
| **Pending Cash QR** (`digi_withdrawals`) | every legacy digi-gold withdrawal request still PENDING (1,001) **plus** the 17 dealer-side cash tickets with no request behind them (source *ticket*) — 1,018 rows, 511 g, with the rate and rupee worth fixed on each row's own date | per row or in bulk: **Cash wallet** (the worth → `cash_balance`) or **Digi-gold** (the grams → `digi_gold_grams` + a credit line in `digi_gold_txns`, source `legacy_cash_qr`, at that date's rate). Either way the dealer ticket (`digi_queue` DIGIWD) closes. `legacy:settle-digi-withdrawals` is no longer run by the rebuild (kept for a deliberate bulk cash settlement). |
| **Pending Gold QR** (`redeemable_qrs`, source *legacy*) | every legacy invoice QR awaiting delivery: 5,108 gold + 216 silver (35 of contracts deleted in legacy), each with `priced_on` and `frozen_rate` = the legacy `goldprice` of its date | **Send via WhatsApp** (QR image + message, single or bulk; also the app inbox). The distributor redeems on the Redeem QR screen at **any branch**: legacy QRs are never billing-branch-locked and the invoice prices that metal at the QR's frozen rate, not today's. The `digi_queue` row is marked *staged* so it can never be redeemed twice. |

| **Pending Digi** (`digi_orders` source OPENING) | every distributor's legacy digi-gold balance (all legacy credits − all withdrawals): 8,798 rows, 1,578.41 g, each with the last legacy date and rate | **Send to Digi-gold** per row or in bulk: one `legacy_opening` line in `digi_gold_txns` (dated on the legacy date) + `member_wallets.digi_gold_grams`. The import no longer pushes anything into the purse (`importDigiOpening` writes the OPENING rows as *pending* and clears any earlier push); the app purse reads 0 g until HQ sends. 0 g rows are not listed. `PendingDigiService`, test `PendingDigiTest`. |

Mechanics: `php artisan legacy:stage-pending-qrs [--dry-run]` (idempotent; in `scripts/rebuild-live.sh` after the roll-out),
migrations `2026_11_02_000300` + `000400` (`redeemable_qrs.source/priced_on/frozen_rate`, `bond_id` nullable;
`digi_withdrawals.source/qr_code/legacy_ref/rate/decision/decided_by/decided_at`; `digi_queue.rate` + status *staged*).
The importer now carries `tbl_digi_queue.goldprice` into `digi_queue.rate`. On the rehearsal build the earlier automatic
cash settlement was reversed (wallets back to ₹49,99,900) before staging. Tests: `PendingLegacyQrTest`.

**Back-dated billing (same night):** the migration day stacked up bills that must carry that day's date. Sales now has a
**Bill date** picker for the HQ super admin only (today by default, up to 31 days back, never in the future). It is
**protected**: fixed to today; picking another date (or the lock icon inside the date box) opens a password popup, and
only the admin pass-phrase (`PAYOUT_CUTOFF_PASSWORD`, the cut-off one) unlocks it. The chosen date then stays for the
bills that follow until the lock icon is pressed again or the page is left. Everyone else bills on today's date, enforced in `Sales::generate()` as well as by hiding the field. The invoice, the contract
start, the member's joining date, the instalment schedule and the commissions all follow the chosen date (`SalesService`
already took `bill_date`). Test: `GoLiveWiringTest::test_hq_super_admin_can_back_date_a_bill…`.

## 13. Every legacy branch gets its own branch back (2 Nov 2026) — `legacy:split-merged-branches`

**What went wrong.** Legacy `tbl_branches` has 1,262 rows; 1,038 of them are *"LORD JEWELLER" in "SIVAKASI"* — every area
dealer / reseller / wholesaler / taluk dealer is registered under the Head Office name, and only `brid`, phone, in-charge
and address differ. `legacy:rollout` identified a branch by **name + city**, so it folded 1,037 of them **into Head
Office** (branch #1): 956 of the 1,171 imported logins, 5,509 members, ₹1.66 cr of dealer cash stock (577 dealers),
10,650 gold and 2,188 silver pieces, ₹24.58 L of billing margin and ₹3.18 L of digi cash all landed on HQ. A dealer who
logged in saw HQ's dashboard and could bill from HQ's pooled stock. The Branches page showed 228 branches.

**Fix.** `branches.legacy_brid` (migration `2026_11_02_000600`, unique) is the identity of a branch from now on; every
importer keys on it (`LegacyRolloutImport::importBranches`, `LegacyImport::importBranches`, `CashStockSeeder`). The
one-off repair is

```bash
cd /www/wwwroot/lordicl.com
php artisan down --secret=repair
php -d memory_limit=2048M artisan legacy:split-merged-branches --dry-run   # full simulation, rolled back, same report
php -d memory_limit=2048M artisan legacy:split-merged-branches             # ~1 min, ONE transaction
php artisan up
```

It creates one branch per legacy `brid` (same name/city, its own address, phone, in-charge, GST, dealership level from
the login's designation, supplier from the login's upline, GPS, margins and digi cash) and moves **out of the branch it
was pooled into** only what legacy says belongs to it: the logins (`ci_users.brid` → `users.member_code = mapid`), the
members they entered (`tbl_member.entryby`), the legacy **ACTIVE** stock per product (a `stock_movements` *transfer* on
both sides, `ref_type legacy_split`, `ref_id = brid`), bonds + `member_contracts LEG-<bondid>` + the QRs of those bonds,
RD entries (exact bond + date), sales invoices (`estid`), dealer commissions (matched on bill + mapped uid + value;
their `user_id`, which the importer could not resolve because `tbl_reseller_com.userid` is a ci_users id, is filled in)
and IC rows. Rows that already sit elsewhere are never touched, so it is idempotent; a stock shortfall on the pool
(HQ may have billed from it since go-live) is printed as a warning. Margins: each dealer gets its legacy figure; the pool
keeps max(what it has − what the dealers take, its own legacy figure) — legacy kept crediting billing margin after the
closing dump (₹32.86 L claimed vs ₹24.58 L pooled on 2 Nov), so HQ ends at its own ₹1,425 / ₹2.96 L digi cash.
Branches whose name + city was unique (SREE GOLD = brid 109, AURA = brid 541, …) are only stamped — their stock, logins
and rows are never touched. **Activity since go-live** (`--since`, default 2026-11-02): a bill or renewal made in the new
system by a login that still sat on HQ deducted HQ's pooled stock and carries HQ's branch id; each such deduction is a
`stock_movements` row with `created_by`, so the command moves those pieces from the dealer's restored stock back to the
pool, re-points the movement, the RD entry / invoice, its bonds, contracts and commission rows to the dealer's branch
(`ref_type legacy_split_activity`). Activity by logins that were already on their own branch is untouched. One
activity-log line summarises the run.

Dry run on the 2 Nov copy: 1,037 created, 224 stamped, 956 logins, 5,509 members, 10,264 contracts, 15,149 RD entries,
10,270 invoices, ~2.5 L IC rows moved; HQ cash stock back to its own ₹3.15 cr, 0 logins left on HQ. 23 warnings = logins
whose legacy `mapid` is shared by two branches (one user, first branch wins). Tests: `LegacySplitMergedBranchesTest`.
`rebuild-live.sh` runs it as a safety net (a no-op after a clean roll-out).

### 13a. Legacy billing margin → Commissions → **Pending Billing Margin** (2 Nov 2026, user ruling)

Nothing from the split lands in a wallet. Three different things are called "margin":

| legacy | what it is | where it goes in the new system |
|---|---|---|
| `tbl_reseller_com` rows (62,450, ₹4.78 cr, status PASSED) | history of every dealer margin ever earned — settled in legacy over the years | imported row-level with `reseller_commissions.source = legacy` (migration `2026_11_02_000800`); **never in the Commission Approval queue** (`CommissionApprovalService::query` skips source legacy). Before this, the queue listed all of them as approvable |
| `ci_users.billmargin` (₹32.86 L over the dealers) | the dealer's UNPAID accrued balance, what it saw in legacy | `branches.bill_margin` (dashboard figure) **and** one `pending_bill_margins` row per legacy branch (migration `…000700`, seeded by `legacy:split-merged-branches`). HQ decides on **Commissions → Pending Billing Margin**: *Send to wallet* (per row or bulk) credits the branch's distributor login → member cash wallet, no TDS / service charge (legacy balance, Pending Cash QR rule); *Reject* with a remark. Super admin only. HQ's own figure is a company figure and is not seeded |
| `ci_users.digicash` (₹21,233 dealers, ₹2.96 L HQ) | the branch's cash collected through QR / counter, used to pay order requests and stock returns | straight into `branches.digi_cash_balance` (the branch Digi Cash wallet) — user 2026-11-02: no pending step |

Margins earned in the new system (`source = new`, status passed) still go through Commission Approval as before.
Tests: `PendingBillMarginTest`, `LegacySplitMergedBranchesTest`.
