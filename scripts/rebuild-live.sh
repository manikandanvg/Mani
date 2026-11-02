#!/usr/bin/env bash
# Rebuild the ship-ready database from a legacy closing dump — ONE command on the PC (Git Bash).
# docs/MIGRATION-OCT-CLOSING.md §1–§4, §10.  Nothing here touches .env or the dev database:
# the target and the legacy source are passed as environment overrides.
#
#   bash scripts/rebuild-live.sh /path/to/lordicl-final-closing.sql[.gz] [--cutoff=2026-10-31]
#
# Steps: load dump → lordicl_octclosing (pristine legacy copy) → drop/create lordicl_live →
# migrate → RolloutSeeder → legacy:rollout --fresh --seed-plans → CashStockSeeder → pincodes →
# static QR → stage pending QRs → legacy contracts → push settings → auto-map reports →
# audit → [payout:cutoff dry-run].
set -euo pipefail

DUMP="${1:?usage: rebuild-live.sh <dump.sql|dump.sql.gz> [--cutoff=YYYY-MM-DD]}"
CUTOFF=""
for a in "${@:2}"; do case "$a" in --cutoff=*) CUTOFF="${a#--cutoff=}";; esac; done

MYSQL="${MYSQL:-C:/wamp64/bin/mysql/mysql8.2.0/bin/mysql}"
MYSQLDUMP="${MYSQLDUMP:-C:/wamp64/bin/mysql/mysql8.2.0/bin/mysqldump}"
LEGACY_DB="${LEGACY_DB:-lordicl_octclosing}"
TARGET_DB="${TARGET_DB:-lordicl_live}"
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LOG="$ROOT/storage/logs/rebuild-live_$(date +%Y%m%d_%H%M%S).log"
cd "$ROOT"

say() { printf '\n== %s  (%s)\n' "$1" "$(date +%H:%M:%S)" | tee -a "$LOG"; }

say "1. legacy source $LEGACY_DB <- $DUMP"
"$MYSQL" -uroot -e "DROP DATABASE IF EXISTS \`$LEGACY_DB\`; CREATE DATABASE \`$LEGACY_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
case "$DUMP" in
  *.gz) gzip -dc "$DUMP" | "$MYSQL" -uroot --max_allowed_packet=1024M --default-character-set=utf8mb4 "$LEGACY_DB" ;;
  *)    "$MYSQL" -uroot --max_allowed_packet=1024M --default-character-set=utf8mb4 "$LEGACY_DB" < "$DUMP" ;;
esac
"$MYSQL" -uroot -t "$LEGACY_DB" <<'SQL' | tee -a "$LOG"
SELECT 'closing: max bond date' k, MAX(bdate) v FROM tbl_bond
UNION ALL SELECT 'closing: max invoice date', MAX(estdate) FROM tbl_salesinvoice
UNION ALL SELECT 'members', COUNT(*) FROM tbl_member
UNION ALL SELECT 'GAP runs (dates)', GROUP_CONCAT(DISTINCT date ORDER BY date) FROM tbl_gap_log;
SQL

say "2. target $TARGET_DB"
"$MYSQL" -uroot -e "DROP DATABASE IF EXISTS \`$TARGET_DB\`; CREATE DATABASE \`$TARGET_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
export DB_DATABASE="$TARGET_DB" LEGACY_DB_DATABASE="$LEGACY_DB"
php artisan config:clear >/dev/null
php artisan migrate --force --no-ansi | tail -3 | tee -a "$LOG"
php artisan db:seed --class=RolloutSeeder --force --no-ansi | tee -a "$LOG"

say "3. legacy:rollout (≈25 min)"
php -d memory_limit=2048M artisan legacy:rollout --fresh --seed-plans --no-ansi | tee -a "$LOG"
php artisan db:seed --class=CashStockSeeder --force --no-ansi | tee -a "$LOG"

say "4. masters + cut-over one-offs"
php artisan pincodes:import storage/app/pincodes/all_india.csv --no-ansi | tail -1 | tee -a "$LOG"
php artisan branches:pincodes --fill --no-ansi | tail -2 | tee -a "$LOG"
php artisan branches:static-qr --no-ansi | tee -a "$LOG"
# Board 2026-11-01: legacy pending QRs are NOT settled here — staged for the Commissions pages (Pending Cash QR / Pending Gold QR)
php artisan legacy:stage-pending-qrs --no-ansi | tail -2 | tee -a "$LOG"
php artisan contracts:backfill-legacy --no-ansi | tail -1 | tee -a "$LOG"
php artisan legacy:import-orphan-logins --no-ansi | tail -1 | tee -a "$LOG"
# safety net: every legacy branch must own its branch (legacy_brid); a no-op after a clean roll-out
php artisan legacy:split-merged-branches --no-ansi | tail -3 | tee -a "$LOG"
# FCM / APNs credentials live in push_settings (not in the dump) — carry the dev row over when present.
"$MYSQL" -uroot -e "INSERT INTO \`$TARGET_DB\`.push_settings SELECT * FROM lordicl_next.push_settings WHERE NOT EXISTS (SELECT 1 FROM \`$TARGET_DB\`.push_settings)" 2>/dev/null || true
php artisan branches:auto-map --no-ansi | tail -2 | tee -a "$LOG" || true
php artisan members:auto-map --no-ansi | tail -2 | tee -a "$LOG" || true
php artisan branches:audit-sources --no-ansi | tail -1 | tee -a "$LOG" || true

if [ -n "$CUTOFF" ]; then
  say "5. payout:cutoff --cutoff=$CUTOFF --dry-run"
  php artisan payout:cutoff --cutoff="$CUTOFF" --dry-run --quiet-log --no-ansi | tee -a "$LOG"
fi

say "done — log $LOG"
echo "next: reconcile (docs/MIGRATION-OCT-CLOSING.md §4–§6), then ship: $MYSQLDUMP -uroot --single-transaction --quick $TARGET_DB | gzip > $TARGET_DB-closing.sql.gz"
