#!/usr/bin/env bash
# Deploy / optimise the new software on the SERVER (aaPanel, Apache + PHP-FPM) — run from the site root
# as the PHP user, every time code is pulled. docs/DEPLOY-LORDICL-COM.md has the one-time steps around it.
#
#   bash scripts/deploy-live.sh            # pull + install + migrate + cache + assets + checks
#   bash scripts/deploy-live.sh --no-pull  # same without git pull (files copied by hand)
#
# Safe to re-run. Never run on the dev PC (config cache breaks the tests there).
set -euo pipefail
cd "$(dirname "$0")/.."

PULL=1
for a in "$@"; do case "$a" in --no-pull) PULL=0;; esac; done

say() { printf '\n\033[1;36m== %s\033[0m\n' "$*"; }

[ -f .env ] || { echo "no .env — copy .env.production.example to .env and fill it first"; exit 1; }
grep -q '^APP_ENV=production' .env || echo "WARNING: APP_ENV is not production in .env"
grep -q '^APP_DEBUG=false' .env || echo "WARNING: APP_DEBUG is not false in .env"

say "1. maintenance on"
php artisan down --retry=30 --secret=deploying || true

if [ "$PULL" = 1 ]; then
  say "2. git pull"
  git pull --ff-only manikandanvg main || git pull --ff-only origin main
fi

say "3. composer (production, optimised autoloader)"
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader

say "4. database"
php artisan migrate --force --no-interaction

say "5. storage link + permissions"
php artisan storage:link || true
chmod -R ug+rwX storage bootstrap/cache

say "6. clear old caches, then build the production caches"
php artisan optimize:clear
php artisan filament:assets
php artisan icons:cache
php artisan optimize            # config + route + view + event caches
php artisan queue:restart || true

say "7. go-live check"
php artisan golive:check || true

say "8. maintenance off"
php artisan up

echo
echo "done. If PHP-FPM uses OPcache (it should), reload it: aaPanel → App Store → PHP → Reload, so the new code is served."
