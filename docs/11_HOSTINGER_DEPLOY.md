# 11 — Hostinger shared hosting (RankwayAI)

SSH + cron allowed. PHP **8.2+**, MySQL, document root → Laravel `public/`.

Replace placeholders:

| Placeholder | Example |
|-------------|---------|
| `USER` | `u123456789` |
| `DOMAIN` | `rankwayai.com` |
| `APP` | `/home/USER/domains/DOMAIN/rankwayai` |

---

## A. Hostinger panel (once)

1. Domain + **SSL** (Force HTTPS).
2. **Databases → MySQL** → create DB + user → note host (often `localhost`), name, user, password.
3. **Advanced → PHP Configuration** → **8.2** or **8.3**.
4. **Advanced → SSH Access** → enable, note host/user.
5. **Domains** → your domain → **Document root**:

```
/home/USER/domains/DOMAIN/rankwayai/public
```

If the panel only allows `public_html`, use **layout B** below.

---

## B. Folder layouts

### Layout A (recommended) — custom folder + document root

```
/home/USER/domains/DOMAIN/
  rankwayai/          ← Laravel root (composer, app, .env)
    public/           ← document root points HERE
```

### Layout B — stuck with `public_html`

```
/home/USER/domains/DOMAIN/
  laravel/            ← Laravel root (outside web)
  public_html/        ← only contents of public/ (+ fixed index.php)
```

After upload of `public/*` into `public_html`, edit `public_html/index.php`:

```php
require __DIR__.'/../laravel/vendor/autoload.php';
$app = require_once __DIR__.'/../laravel/bootstrap/app.php';
```

(Adjust `../laravel` to your real path.)

---

## C. Local Mac — prepare release

```bash
cd /Users/anil/Projects/marketing-os

composer install --no-dev --optimize-autoloader
npm ci
npm run build

# optional zip (exclude junk)
zip -r /tmp/rankwayai-release.zip . \
  -x "*.git*" \
  -x "*node_modules*" \
  -x "*.env" \
  -x "*storage/logs/*" \
  -x "*storage/framework/cache/*" \
  -x "*storage/framework/sessions/*" \
  -x "*storage/framework/views/*"
```

`public/build/manifest.json` must exist before upload.

---

## D. Upload

### Option 1 — Git (best if repo is remote)

```bash
ssh USER@YOUR_SSH_HOST
cd ~/domains/DOMAIN
git clone YOUR_REPO_URL rankwayai
cd rankwayai
composer install --no-dev --optimize-autoloader
# if you built locally, rsync public/build separately:
# from Mac:
# rsync -avz public/build/ USER@HOST:~/domains/DOMAIN/rankwayai/public/build/
```

### Option 2 — rsync from Mac

```bash
cd /Users/anil/Projects/php/rankwayai

rsync -avz --delete \
  --exclude='.git' \
  --exclude='node_modules' \
  --exclude='.env' \
  --exclude='storage/app/public/**' \
  --exclude='public/storage' \
  --exclude='storage/logs/*' \
  --exclude='storage/framework/cache/data/*' \
  --exclude='storage/framework/sessions/*' \
  --exclude='storage/framework/views/*' \
  ./ USER@YOUR_SSH_HOST:~/domains/DOMAIN/rankwayai/
```

> **Never overwrite** server `.env` or `storage/app/public` (uploaded media). Recreate the symlink after sync.

Then SSH:

```bash
cd ~/domains/DOMAIN/rankwayai
composer install --no-dev --optimize-autoloader
php artisan storage:link
```
---

## E. Server — `.env` + Laravel setup

```bash
cd ~/domains/DOMAIN/rankwayai
cp .env.example .env
nano .env
```

Minimum production values:

```env
APP_NAME=rankwayAI
APP_ENV=production
APP_DEBUG=false
APP_URL=https://DOMAIN
FRONTEND_URL=https://DOMAIN
SEO_PUBLIC_URL=https://DOMAIN
SEO_MARKETING_CONTACT_EMAIL=info@vibgyorsolution.com

DB_CONNECTION=mysql
DB_HOST=localhost
DB_PORT=3306
DB_DATABASE=your_db
DB_USERNAME=your_user
DB_PASSWORD=your_password

SESSION_DRIVER=database
CACHE_STORE=database
QUEUE_CONNECTION=database

LOG_CHANNEL=stack
LOG_LEVEL=error

MAIL_MAILER=smtp
MAIL_HOST=smtp.hostinger.com
MAIL_PORT=465
MAIL_USERNAME=noreply@DOMAIN
MAIL_PASSWORD=your_mailbox_password
MAIL_ENCRYPTION=ssl
MAIL_FROM_ADDRESS="noreply@DOMAIN"
MAIL_FROM_NAME="RankwayAI"

FILESYSTEM_DISK=local
MEDIA_DISK=public
```

Then:

```bash
php artisan key:generate
php artisan storage:link
php artisan migrate --force

# optional first login seed
php artisan db:seed --class=DemoAccountsSeeder --force

chmod -R ug+rwx storage bootstrap/cache
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Demo login (if seeded): `info@vibgyorsolution.com` / `Password1!` — change password immediately.

Health check: `https://DOMAIN/up` → should return OK.

---

## F. Cron jobs (Hostinger → Advanced → Cron Jobs)

### Cron 1 — Laravel scheduler (required)

```
* * * * *
```

Command:

```bash
cd /home/USER/domains/DOMAIN/rankwayai && php artisan schedule:run >> /dev/null 2>&1
```

This runs:

| Command | When |
|---------|------|
| `social:publish-due` | every minute |
| `channels:send-due` | every minute |
| `seo:run-due` | hourly |

### Cron 2 — queue drain (recommended on shared)

```
* * * * *
```

Command:

```bash
cd /home/USER/domains/DOMAIN/rankwayai && php artisan queue:work --stop-when-empty --max-time=50 --tries=3 >> /dev/null 2>&1
```

### Better queue (SSH session / screen)

```bash
cd ~/domains/DOMAIN/rankwayai
screen -S rankway-queue
php artisan queue:work --sleep=3 --tries=3
# Ctrl+A then D to detach
```

---

## G. After go-live

1. Homepage `/`, `/about`, `/pricing`, `/contact`
2. Login + SEO scan on a real domain
3. Google Cloud OAuth — add redirect:

```
https://DOMAIN/seo/gsc/callback
```

4. Do **not** create a folder `public/brand/` (shadows Brand Kit route `/brand`)
5. Media uploads: ensure `storage/app/public` writable + `storage:link` done

---

## H. Redeploy (updates) — safe scripts

**Do not** unzip over `public_html` by hand (that wipes uploads + storage link).

Protected forever (see `scripts/deploy-excludes.txt`):

| Path | Why |
|------|-----|
| `.env` | production secrets |
| `storage/app/public/` | uploaded media |
| `public/storage` | symlink (recreated by post-deploy) |

### Preferred — rsync from Mac

```bash
./scripts/hostinger-deploy.sh USER@HOST:~/domains/DOMAIN/rankwayai
# then SSH:
cd ~/domains/DOMAIN/rankwayai && bash scripts/hostinger-post-deploy.sh
```

### Zip upload (cPanel / Hostinger)

1. Local: `./scripts/hostinger-prepare.sh /tmp/rankwayai-release.zip`
2. Upload zip to server (outside app, e.g. home dir)
3. SSH:

```bash
cd ~/domains/DOMAIN/rankwayai
bash scripts/hostinger-safe-extract.sh ~/rankwayai-release.zip
```

`safe-extract` syncs code with excludes, then runs `storage:link` + migrate + caches. Uploads and `.env` stay.---

## I. Common errors

| Symptom | Fix |
|---------|-----|
| White page / 500 | `storage/logs/laravel.log`; permissions on `storage` + `bootstrap/cache` |
| No CSS/JS | Missing `public/build` — rebuild + upload |
| 404 on all routes | Document root not `public`; `.htaccess` rewrite off |
| `/brand` Not Found | Remove `public/brand` directory if present |
| Jobs never run | Cron path wrong; test: `php artisan schedule:run` over SSH |
| GSC connect fails | Production redirect URI + APP_URL must match HTTPS domain |
| Contact form silent | SMTP `.env` + mailbox created in Hostinger |

---

## J. Quick SSH smoke test

```bash
cd ~/domains/DOMAIN/rankwayai
php -v
php artisan about
php artisan schedule:list
php artisan migrate:status
curl -I https://DOMAIN/up
```
