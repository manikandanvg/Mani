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
- **One line per distributor** instead of history rows:
  - Sales Incentive (IC): 5.2 lakh rows → ≤2 ledger lines per member (`LEGACY-IC ×n` paid / pending / approved).
  - Turnover salary (GAP): the monthly payout log (net, TDS, service charge) → ≤2 lines per member. **Pending Oct-2026 GAP (≈₹4.6 Cr) lands in Commission Approval as pending** — approve there to credit wallets.
  - Dealer billing margin: 53k rows → 1 line per dealer branch (`LEGACY-BM ×n`).
  - Digi gold: purchases − withdrawals (all statuses, as legacy deducted at request) → 1 `OPENING` line per member + wallet grams. Pending withdrawals / pending QR queue rows stay as rows.
- **Row-level** (cannot shrink): bonds (contracts), RD instalments, **CBC coupons** (individually redeemable), sales invoices + lines.
- **Not imported** (historic/operational, decided 2026-10-24): branch order requests, dealer requests, estimates/quotations, memos, events, online orders, employee table (empty).

## Dev database

`lordicl_next` was backed up to `Desktop\db-backups\lordicl_next-pre-rollout-2026-10-24.sql` and then imported with `legacy:rollout --fresh` (plans already v2 there). Test members/branches that are not in legacy remain.
