# Hosting on AWS (cheap / free-trial safe)

One Laravel app serves **both** Filament and the Flutter API from the **same URL and same MySQL database**.

| Client | URL |
|---|---|
| Filament admin | `https://YOUR_DOMAIN/admin` |
| Flutter API | `https://YOUR_DOMAIN/api/v1` |
| Database | MySQL on the same server |

---

## Honest cost note (read this)

**AWS is not free forever for a web server.**

| What | Reality |
|---|---|
| New-account Free Tier (EC2 `t2`/`t3.micro`) | Usually **12 months**, then you pay |
| Lightsail free trial | Often **3 months** on one small plan, then ~$3.50–$5/month |
| Always Free on AWS | Not enough for Laravel + MySQL 24/7 |

**Stay inside free / near-free:**

1. Use **one** small instance only (no RDS, no load balancer, no Elastic IP extras if avoidable).
2. Put MySQL **on that same box**.
3. Use **Cloudflare** (free) in front for HTTPS + DNS.
4. Keep off-site backups on **Cloudflare R2** (already chosen in `docs/operations.md`) — not a second AWS bill.
5. Set a calendar reminder **30 days before** free trial ends → either stop the instance or accept ~$5/month.

If you need **$0 forever**, use **Oracle Cloud Always Free** (ARM VM) instead of AWS. Same app setup; different cloud login. Say the word and we can document that path.

---

## Target shape (do not overbuild)

```
Phone / Filament browser
        │
        ▼
  Cloudflare (free DNS + HTTPS)
        │
        ▼
  One Lightsail or EC2 box
   ├─ nginx
   ├─ PHP 8.4 + Laravel (Filament + API)
   ├─ MySQL (same machine)
   ├─ supervisor (queue + scheduler)
   └─ nightly backup → Cloudflare R2
```

**Do not create:** RDS, ElastiCache, ECS, Elastic Beanstalk, ALB, NAT gateway, multiple AZs. Those burn the free trial fast.

---

## Step A — Create the server (AWS console)

### Option 1 (simplest): Lightsail

1. AWS Console → **Lightsail** → Create instance.
2. Platform: **Linux/Unix** → Blueprint: **OS Only** → **Ubuntu 24.04**.
3. Plan: **smallest** that has the free trial badge (often $3.50–$5 / 512MB–1GB). Prefer **1 GB RAM** if offered free — Laravel + MySQL is tight on 512 MB.
4. Region: **Cape Town (`af-south-1`)** if available, else **Ireland (`eu-west-1`)**.
5. Name: `schoolbook`.
6. Networking → open ports: **22** (SSH), **80**, **443**. Leave MySQL **closed** to the world.
7. Attach a **static IP** (Lightsail static IPs are free while attached to a running instance).

### Option 2: EC2 Free Tier

1. EC2 → Launch instance → Amazon Linux 2023 or Ubuntu 24.04.
2. Type: **`t3.micro`** or **`t2.micro`** (Free Tier eligible).
3. Storage: **30 GB gp3** (Free Tier allowance) — do not pick 100 GB.
4. Security group: SSH from your IP only; HTTP/HTTPS from anywhere.
5. Same rule: **no RDS**.

Download / save the SSH key once.

---

## Step B — Domain (optional) or IP-only

**You do not need a paid domain to go live.**

### Option B1 — IP only (no domain money)

Use the instance public IPv4:

```env
APP_URL=http://18.215.14.20
```

Flutter (More long-press / build define):

```text
http://18.215.14.20/api/v1
```

Filament: `http://18.215.14.20/admin`

Notes:
- Stay on **HTTP** (Let's Encrypt needs a domain name).
- Android release builds need cleartext allowed for that host (debug already allows it).
- When you can afford ~$5–15/year for a domain later, put DNS on Cloudflare Free and add Certbot HTTPS.

### Option B2 — Domain + Cloudflare free (later)

1. Buy any domain.
2. Cloudflare → Add site → free plan → point DNS:
   - `A` `@` → Lightsail/EC2 static IP (proxied orange cloud)
   - `A` `www` → same (optional)
3. SSL/TLS mode: **Full (strict)** after Certbot on the server (or **Full** while testing).

```env
APP_URL=https://app.yourdomain.com
```

---

## Step C — Server packages (SSH)

```bash
sudo apt update && sudo apt upgrade -y
sudo apt install -y nginx mysql-server unzip git curl
# PHP 8.4 (Ubuntu 24.04 may need ondrej/php PPA on 22.04)
sudo apt install -y php8.4-fpm php8.4-mysql php8.4-xml php8.4-mbstring \
  php8.4-curl php8.4-zip php8.4-gd php8.4-intl php8.4-bcmath php8.4-cli
sudo apt install -y composer supervisor certbot python3-certbot-nginx
```

Create DB (local only):

```bash
sudo mysql -e "CREATE DATABASE schoolbook CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'schoolbook_app'@'localhost' IDENTIFIED BY 'LONG_RANDOM_PASSWORD';
GRANT ALL ON schoolbook.* TO 'schoolbook_app'@'localhost';
FLUSH PRIVILEGES;"
```

Deploy Laravel under `/var/www/schoolbook` (git clone or upload `laravel/`), web root = `public/`.

`.env` essentials:

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://app.yourdomain.com
DB_DATABASE=schoolbook
DB_USERNAME=schoolbook_app
DB_PASSWORD=...
QUEUE_CONNECTION=database
BACKUP_DISKS=backups,offsite
BACKUP_ARCHIVE_PASSWORD=...   # required with offsite
# BACKUP_OFFSITE_* → Cloudflare R2 (docs/operations.md)
```

Then:

```bash
cd /var/www/schoolbook/laravel
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan migrate --force
php artisan sequences:prepare
php artisan owner:create
php artisan config:cache && php artisan route:cache && php artisan view:cache && php artisan filament:optimize
sudo chown -R www-data:www-data storage bootstrap/cache
```

nginx site → `root .../public;` → Certbot → HTTPS.

Cron:

```cron
* * * * * cd /var/www/schoolbook/laravel && php artisan schedule:run >> /dev/null 2>&1
```

Supervisor: `php artisan queue:work --tries=3 --max-time=3600`.

Import / migrate your **existing** MySQL dump from the Windows `schoolbook` DB so Filament books and phone share the same data.

---

## Step D — One APK, many client servers

Ship **one** product APK. Server setup is **hidden**: long-press the **More** title → enter setup PIN → paste that client's host. Cashiers never see a Server section.

```powershell
# Product APK (shared by all clients)
flutter build apk --release `
  --dart-define=API_BASE_URL=https://fallback.yourdomain.com/api/v1 `
  --dart-define=DEV_SERVER_PIN=your-long-secret
```

On the device: More → **long-press “More”** (or tap it **7 times** quickly) → PIN → paste `https://client-a.example.com` (`/api/v1` added if missing) → Save.

Keep the PIN to yourself. **Debug / emulator** (`flutter run`): same gesture, **no PIN**.

Emulator default `10.0.2.2:8000` is **local only**.

### Oracle Free Tier + card step

Oracle **always** asks for a card (identity check — they say they won’t charge Free Tier). The card form loads from `shop.oracle.com`. If that page **refused to connect** for days, that is a known Oracle/payment-iframe problem (region, ISP, or their storefront), not something wrong with your card entry yet.

Try: another browser / mobile data / no VPN / ad-blocker off. Ghana and some ISPs often cannot reach that shop host at all.

**Practical path:** skip Oracle for now and use **AWS Lightsail** (this doc) — same Laravel app, card works there, free trial then ~$5/month.

---

## Free-trial guardrails (stay under the free line)

| Do | Don't |
|---|---|
| 1 micro/small instance | Second instance “for staging” on the same free account |
| MySQL on the box | RDS (burns free tier + leftover bill) |
| Cloudflare HTTPS | AWS ALB / ACM-only stack |
| R2 backups | Extra S3 + Glacier + CloudWatch alarms everywhere |
| Stop instance when unused in trial | Leave unused NAT / Elastic IP detached (AWS charges) |
| Billing → Budgets → **$1 alarm** | Ignore Cost Explorer |

Set: **AWS Billing → Budgets → zero-spend or $5 budget + email**.

---

## Local same-DB check (before AWS)

On this PC, Filament already uses `DB_DATABASE=schoolbook`. Point the phone/emulator at the same Laravel process:

```powershell
# From flutter/
flutter run --dart-define=API_BASE_URL=http://10.0.2.2:8000/api/v1   # emulator
# Real phone on same Wi‑Fi (replace with your PC LAN IP):
flutter run --dart-define=API_BASE_URL=http://192.168.x.x:8000/api/v1
```

Laravel must be running: `cd laravel; php artisan serve --host=0.0.0.0 --port=8000`.

---

## Current box (owner)

| | |
|---|---|
| IPv4 | `18.215.14.20` |
| User | `ubuntu` |
| Region | `us-east-1` (Virginia) |
| Domain | **none** — use `http://18.215.14.20` until a domain is affordable |

## Next actions for you

1. Security group / firewall: **22, 80, 443** open (MySQL closed to the world).
2. Billing → **$1 budget alert**.
3. SSH in (Windows PowerShell; fix the `.pem` path):

```powershell
ssh -i "$env:USERPROFILE\.ssh\YOUR-KEY.pem" ubuntu@18.215.14.20
```

4. On the server, run the package + DB steps in **Step C**, with:

```env
APP_URL=http://18.215.14.20
```

5. Reply when SSH works and packages are installed — next is nginx + clone Laravel + R2 `.env`.
