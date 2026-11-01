# Putting the new software on lordicl.com — what you must do

Companion to `docs/MIGRATION-OCT-CLOSING.md` §7 (domain, holding mode, webhooks). This is the hands-on checklist, in
order. Everything that "optimises" the code (Composer autoloader, config/route/view/event caches, Filament assets,
icon cache, OPcache) happens **on the server** with `scripts/deploy-live.sh` — the dev PC must never cache config.

## 0. Before you leave the PC

| what | where |
|---|---|
| Code | pushed to GitHub `manikandanvg/Mani`, branch `main` — the server pulls it, do not FTP the folder (vendor, node_modules, storage and caches are PC-specific) |
| Database | the ship dump: `Desktop\NOV\lordicl_live-….sql.gz` (rebuild from the post-approval legacy dump first if the board wants legacy's October approval kept — §7) |
| Secrets | the PC's `.env` values for APP_KEY, Razorpay, Zoom, PAN, PAYOUT_CUTOFF_PASSWORD — type them into the server `.env`, never commit them |
| FCM | the Firebase service-account JSON (for `push:import-fcm`), unless the dump already carries the push_settings row |

## 1. Server one-time setup (aaPanel)

1. **Site**: Website → Add site → domain `lordicl.com` + `www.lordicl.com` (+ `next.lordicl.com` as an extra domain while
   3.11.14 phones are still out there) → PHP 8.2+ → root `/www/wwwroot/lordicl` → **Run directory `/public`** →
   SSL → Let's Encrypt → Force HTTPS.
2. **PHP** (App Store → PHP → settings): extensions `gd, mbstring, bcmath, intl, zip, pdo_mysql, fileinfo, exif, opcache`;
   Disabled functions: remove `proc_open, exec, shell_exec, symlink, putenv` (PDF, QR, storage:link, TTS need them);
   `memory_limit 1024M`, `max_execution_time 300`, `upload_max_filesize 64M`, `post_max_size 64M`; OPcache ON.
3. **Database**: Databases → Add → `lordicl_live` (utf8mb4_unicode_ci) + a user; import the ship dump:
   `gzip -dc /www/backup/lordicl_live-….sql.gz | mysql -u<user> -p lordicl_live`. The legacy database `lordicl` stays
   (reports read it; old.lordicl.com uses it).
4. **Code**:
   ```bash
   cd /www/wwwroot/lordicl
   git clone https://github.com/manikandanvg/Mani.git .      # or: git pull manikandanvg main
   cp .env.production.example .env && nano .env                # fill every blank (APP_KEY from the PC!)
   bash scripts/deploy-live.sh --no-pull
   chown -R www:www .                                          # the PHP-FPM user in aaPanel is "www"
   ```
   `deploy-live.sh` = composer install (no dev, optimised), migrate, storage:link, clear + rebuild caches,
   `filament:assets`, `icons:cache`, `golive:check`. Read the check's FAIL lines and fix them before go-live.
5. **Cron** (aaPanel → Cron → Shell, every minute):
   `cd /www/wwwroot/lordicl && php artisan schedule:run >> /dev/null 2>&1`
   (or, on hosts without per-minute cron, once a day at 03:00: `php artisan cron:run`). This drives the monthly
   cut-off on the 1st, the daily settlement / recompute, FX rates, meeting reminders and the queue drain.
6. **Holding mode** until the board says go: §7 step 3 (Apache block with the office IP). Remove it on go-live day.

## 2. Settings to fill in the admin after the first login

System → **Push Notification Settings** (FCM service account, or `php artisan push:import-fcm <json>`), **WhatsApp
Settings** (if different from the config defaults), **Commission Setup** (TDS / service / CBC split — defaults 5 / 5 / 50),
**KYC settings**, Monthly Tasks → task types, **Live Rates** (today's gold / silver). Change the `admin@lordicl.com`
password.

## 3. Outside services pointed at lordicl.com

Razorpay webhook `https://lordicl.com/webhooks/razorpay`; WhatsApp provider callback
`https://lordicl.com/api/v1/webhooks/whatsapp`; Zoom marketplace apps (allowed domain + `https://lordicl.com/webhooks/zoom`);
Play Console privacy policy `https://lordicl.com/page/privacy-policy`; both L-BOX boxes re-pointed to
`https://lordicl.com` (pairing command, docs/ROLLOUT.md). `old.lordicl.com` keeps the legacy site; the new site forwards
`lordicl.com/app/*` there for the old Android app (`LEGACY_SITE_URL`).

## 4. Every later update

```bash
cd /www/wwwroot/lordicl && bash scripts/deploy-live.sh
```
then reload PHP in aaPanel (OPcache). The script puts the site in maintenance for the minute it runs.

## 5. Verify (§8 of the migration runbook)

Super-admin login → Dashboard; a dealer login → Dashboard shows gold / silver / cash stock; Commissions → Commission
Approval, Payout Runs, Pending Cash QR, Pending Gold QR, Pending Digi all list their rows; Sales → Bill date is
protected; app 3.11.15 logs in with the WhatsApp OTP (test recipient blank!). Activity Log shows only the import run.
