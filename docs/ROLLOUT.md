# Roll-out runbook — legacy `lordicl` → lordicl-next (board resolution 2026-10-24)

## What changed for roll-out

| Board item | Where |
|---|---|
| Front page wording (Global Gold Pvt Ltd, Utility, Blessed Gold, slides, HUID, Branch / Dealers, About content, Sivakasi) | `resources/views/storefront/*`, `StorefrontController::heroSlides`, `StorefrontDemoSeeder::aboutBody` |
| Admin nav: Distributor List, Distributor Place, Sales & Contracts, Contracts (bonds), Settlements (member contracts) | `MemberResource`, `GenealogyTree`, `BondResource`, `MemberContractResource`, `AdminPanelProvider` |
| Order Requests → date-interval filter | `BranchOrderResource` (filter `ordered_between`) |
| Branch **kind**: dealer / Lord's Branch (dealer becomes in-charge; payroll by TBP stage already exists) | `branches.kind`, Branch form + table filter |
| Scheme **availability**: both / Lord's Branches only / dealers only (RD, G12 …) | `plans.available_to`, Scheme form "Availability", enforced in `SalesService` + Sales picker |
| Distributor Place = folder (file-explorer) view, chart one click away | `/admin/genealogy` (default folder) · `?view=chart` |
| Utility products (gold spoon, silver tumbler …) | Master → Products (E-com) material "Utility"; public category "Utility"; Catalog material "Utility" |
| Legacy data migration | `php artisan legacy:rollout` (below) |

## Fresh live database

```bash
# 1. empty database + schema
mysql -uroot -e "CREATE DATABASE lordicl_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
# .env: DB_DATABASE=lordicl_live, LEGACY_DB_DATABASE=lordicl (the recent legacy DB)
php artisan migrate --force

# 2. masters only (roles, super admin, currencies, languages, ranks, Sivakasi HQ, categories, rate, charge brackets)
php artisan db:seed --class=RolloutSeeder --force

# 3. legacy data (MOUs → v2 scheme sheet → everything else)
php artisan legacy:rollout --dry-run          # counts only, writes nothing
php -d memory_limit=1024M artisan legacy:rollout --fresh --seed-plans

# 4. per-branch cash stock from legacy
php artisan db:seed --class=CashStockSeeder --force

# 5. after import
php artisan ranks:recompute      # if TBP ranks should be re-derived from BV (legacy mylevel was carried over)
php artisan branches:audit-sources
php artisan storage:link
```

Change the super admin password (`admin@lordicl.com`) immediately after seeding.

## What the importer does (and does not)

- **HQ = Sivakasi.** Legacy branch 1 (LORD JEWELLER, 62K Gandhi Road, GST 33AREPB3962B3ZD) overwrites the HQ row in place. Nothing is called Coimbatore any more.
- **Schemes** are matched to the board's v2 sheet **by name** (codes were reshuffled: legacy 208 Zonal → P207, 211 Area Distributor → P210, 212 G10 Silver → P211 …). Existing scheme configuration is never modified. "G12 Smart Gold Savings Plan" (legacy 207, retired) has no v2 twin → kept as inactive `L207` so its bonds still resolve.
- **Dealers** (ci_users) → `distributor` logins, branch-scoped, **bcrypt passwords kept** (dealers log in with their old password). Their branch gets the dealership level (Reseller/Taluk/District/Zonal/Wholesaler/Area Dealer), accrued margins, digi cash and its stock source (the upline dealer's branch). Every branch starts as **kind = dealer**; convert to Lord's Branch on Master → Branches.
- **Members** get the branch of the login that entered them and their TBP rank (legacy `mylevel`).
- **Commission history row by row** (board 2026-10-31: "don't merge commission from legacy — show the complete earning data"):
  - Sales Incentive (IC): all 5.2 lakh legacy rows, one ledger line each (bond, from-member, bill no, status).
  - Turnover salary (GAP): the monthly payout log → one line per monthly payout (net, TDS, service charge, `GAP <month> ×n` reference). **Pending Oct-2026 GAP (≈₹4.6 Cr) lands in Commission Approval as pending** — approve there to credit wallets.
  - Dealer billing margin: all 53k rows, one line each.
  - Digi gold only is summarised: purchases − withdrawals → 1 `OPENING` line per member + wallet grams. Pending withdrawals / pending QR queue rows stay as rows.
  - A live database imported before 2026-10-31 carries the old single-line history: re-run `legacy:rollout --fresh --seed-plans` from the cut-over dump (≈15 min with the row-level commissions), or accept the summary lines.
- **Row-level** (cannot shrink): bonds (contracts), RD instalments, **CBC coupons** (individually redeemable), sales invoices + lines.
- **Not imported** (historic/operational, decided 2026-10-24): branch order requests, dealer requests, estimates/quotations, memos, events, online orders, employee table (empty).

## Dev database

`lordicl_next` was backed up to `Desktop\db-backups\lordicl_next-pre-rollout-2026-10-24.sql` and then imported with `legacy:rollout --fresh` (plans already v2 there). Test members/branches that are not in legacy remain.

## Live server (aaPanel, /www/wwwroot/next.lordicl.com)

The legacy DB on the live server is the one that matters at cut-over (the local `lordicl` copy is a snapshot). Two ways; A is simpler and keeps the heavy job off the server.

### A. Import locally, ship a dump (recommended)

```bash
# on the live server: freeze legacy + dump it (legacy app read-only from here on)
mysqldump -u<user> -p --single-transaction lordicl > /www/backup/lordicl-cutover.sql

# on the PC: load that dump as the legacy source, build the live DB from it
mysql -uroot -e "DROP DATABASE IF EXISTS lordicl; CREATE DATABASE lordicl CHARACTER SET utf8mb4"
mysql -uroot lordicl < lordicl-cutover.sql
mysql -uroot -e "CREATE DATABASE lordicl_live CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
DB_DATABASE=lordicl_live php artisan migrate --force
DB_DATABASE=lordicl_live php artisan db:seed --class=RolloutSeeder --force
DB_DATABASE=lordicl_live php -d memory_limit=1024M artisan legacy:rollout --fresh --seed-plans
DB_DATABASE=lordicl_live php artisan db:seed --class=CashStockSeeder --force
C:\wamp64\bin\mysql\mysql8.2.0\bin\mysqldump -uroot --single-transaction lordicl_live > lordicl_live.sql

# ship + load on the live server (aaPanel → Databases → create `lordicl_live` + user first)
scp lordicl_live.sql root@<live>:/www/backup/
mysql -u<user> -p lordicl_live < /www/backup/lordicl_live.sql
```

### B. Import on the server

```bash
cd /www/wwwroot/next.lordicl.com
git pull && composer install --no-dev --optimize-autoloader
# .env: DB_DATABASE=lordicl_live  LEGACY_DB_DATABASE=lordicl  LEGACY_DB_USERNAME/PASSWORD=<legacy db user>
php artisan migrate --force
php artisan db:seed --class=RolloutSeeder --force
php artisan legacy:rollout --dry-run
php -d memory_limit=1024M -d max_execution_time=0 artisan legacy:rollout --fresh --seed-plans   # ~8 min
php artisan db:seed --class=CashStockSeeder --force
```

### Board 2026-10-31 corrections — after `migrate` on the live server

```bash
php artisan migrate --force                 # invoice cancel columns, branch payment QR, catalog unit weights,
                                            # premium CMS pages + FAQ (CmsContentSeeder runs inside the migration)
php artisan pincodes:import storage/app/pincodes/all_india.csv   # only if the pincodes master is empty (fresh DB) — the filler + Sales PIN auto-fill read it
php artisan branches:pincodes --fill        # auto-fill missing pincodes from address / city (pincode master, India Post API fallback)
php artisan branches:pincodes --export=/www/backup/branches-without-pincode.xlsx   # the rest, for the board
php artisan branches:geocode                # store-locator pins for branches without coordinates (Nominatim, ~1 s each; 480 branches ≈ 9 min)
php artisan branches:static-qr              # static payment QR (virtual L-BOX device) for every dealer
php artisan branches:auto-map               # dealer supplier by pincode (report) → --apply to write   (docs/ACTIVITY-LOG.md)
php artisan members:auto-map                # distributors without a branch → local counter by pincode → --apply
php artisan stock:import-opening --template=/www/backup/opening-stock.xlsx         # sheet to hand to the branches
php artisan stock:import-opening /path/filled.xlsx --dry-run && php artisan stock:import-opening /path/filled.xlsx
```

- `.env`: `MONTHLY_TASKS_ENABLED=false` (default) keeps the Monthly Tasks screens idle until the L-BOX launch; `GOOGLE_PLAY_PACKAGE` + `GOOGLE_PLAY_SERVICE_ACCOUNT_JSON` enable the daily Play-review → Testimonials import (`testimonials:import-play-reviews`).
- Branch payment QR = the L-BOX static QR without a box: `php artisan branches:static-qr` generates one virtual device per dealer; print from Master → Branches → Static QR; the app scans it under Wallet → Withdraw at branch, and the store list carries `payment_qr_url` (UPI id / image stay optional extras).
- Redemption OTPs now go out as app push + inbox only (no WhatsApp).
- **Branches without "View as Dealer" (user 2026-11-03, 94 on live):** `php artisan legacy:fix-loginless-branches --dry-run` then without `--dry-run`. 34 are real dealerships whose person holds TWO legacy logins under one member code (e.g. a Reseller login at one brid and an AREA DEALER login at another) — `legacy:rollout` keeps one users row per member code, so the other branch lost its login; each gets its own login back (own hash, own or `<slug>.<id>@dealer.lordicl.local` e-mail). 59 are empty legacy placeholder rows ("LORD JEWELLER / SIVAKASI", no login, no data) and are deactivated. Branch #20 (brid 27) keeps its INACTIVE login — activate it on Staff if the board wants it back.
- **Wallet withdrawals (board 2026-11-03): a branch never fronts the cash.** `migrate` adds `branch_payout_requests`. Chain: pending (member scanned the branch QR) → the branch ticks rows on L-BOX → Wallet Withdrawals → *Request payment from HQ* → requested → HQ transfers to the dealer's bank and presses *Pay* (UTR + note) on L-BOX → Branch Payout Requests → admin paid → the branch presses *Disburse*. Only HQ cancels (refund). The 13 rows the branches marked disbursed on 2–3 Nov before this rule go back to pending with
  `php artisan withdrawals:undo-disbursals --ids=1,2,4,5,6,10,11,15,17,30,31,33,42 --dry-run` then without `--dry-run` (no balance changes — the money is still owed to those members).

### Either way, afterwards on the live server

```bash
php artisan storage:link
php artisan config:clear && php artisan route:clear && php artisan view:clear
php artisan optimize            # config/route cache for production
php artisan ranks:recompute     # optional: re-derive TBP ranks from BV
php artisan branches:audit-sources
```

- aaPanel PHP settings: `memory_limit` ≥ 256M for PHP-FPM (Distributor Place / reports on 16k members), CLI is used for the import so no `max_execution_time` change is needed for the web.
- Change `admin@lordicl.com`'s password; dealers log in with their legacy passwords (hashes copied).
- Copy `storage/app/public` uploads (member photos, product images) from legacy if they are to be shown.
- Point the legacy app at a maintenance page once the dump is taken: anything entered there after the dump is lost.
