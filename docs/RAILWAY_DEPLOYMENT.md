# Railway Deployment

How this repository is built and run on Railway, and the steps that must be
performed by hand. Written for whoever deploys or re-deploys the client/TAM
environment.

## Two deployments, one codebase

| Environment | Who operates it | Purpose | Documented in |
|---|---|---|---|
| **Hostinger** | CLSU MISO | MISO's own technical QA of the approved build | `docs/HOSTINGER_QA.md` |
| **Railway** | Development team | Client/TAM environment for real CLSU Infirmary workflow use | this file |

The two are **independent**. They have separate databases, separate Cloudinary
clouds, separate mail credentials and separate admin accounts. Nothing here
changes how Hostinger is deployed, and the container configuration in this
repository is inert on Hostinger, which serves PHP through a conventional web
server with a document root and needs no image. **Do not synchronise the two
databases.**

## Architecture

Three services plus one database. Nothing else.

| Component | What it is | Required |
|---|---|---|
| **Web** | This repo's `Dockerfile` — Apache + mod_php serving `public/` | Yes |
| **Scheduler** | The *same image*, started with `php artisan schedule:work` | Yes |
| **Worker** | The *same image*, started with `php artisan queue:work` | **Yes** — see *Queue worker* |
| **MySQL** | Railway MySQL plugin | Yes |
| Volume | Mounted at `/var/www/html/storage/app/medical` | Strongly recommended — see *Storage* |
| Redis | — | **No.** Sessions, cache and queue are all database-backed. |

### Why a Dockerfile rather than Nixpacks

Two reasons, both of which a build provider would decide by heuristic:

1. **The document root.** Serving the repository root instead of `public/`
   publishes `.env`, `storage/` (medical files) and `vendor/` over HTTP. A
   Dockerfile makes that one reviewable line in `docker/000-default.conf`.
2. **The Node version.** `vite@7.3.5` and `laravel-vite-plugin@2.1.0` both
   declare `engines.node: "^20.19.0 || >=22.12.0"`, and `package.json` has no
   `engines` field to pin it. An older default Node fails `npm run build`,
   leaving no `public/build/manifest.json` — after which every page throws on
   the `@vite` directive.

PHP is pinned to 8.2 (matching `composer.json`'s `"php": "^8.2"`) and Node to
22 for the same reason: nothing about the build should shift underneath a
capstone demo.

## Build

Handled entirely by the `Dockerfile`:

1. `composer install --no-dev --optimize-autoloader` (stage 1)
2. `npm ci && npm run build` → `public/build/manifest.json` (stage 2).
   The build **fails** if the manifest is missing, rather than shipping an
   image whose every page throws on `@vite`.
3. `composer dump-autoload --no-dev --optimize` (stage 3). Its
   `post-autoload-dump` hook runs `package:discover`; the composer binary is
   removed again in the same layer so it is not present in the running image.

`public/build` is intentionally **not** committed — it is generated per deploy.

`.dockerignore` keeps `.env`, `storage/app/medical`, `.git`, `vendor`,
`node_modules` and the test suite out of the build context entirely.

### PHP extensions

Only `pdo_mysql` is installed on top of `php:8.2-apache`. Verified against
every `ext-*` requirement in `composer.lock`'s non-dev tree — `ctype`, `date`,
`dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `mbstring`,
`openssl`, `pcre`, `session`, `tokenizer` — plus `curl` (Cloudinary SDK,
Guzzle) and `zlib` (dompdf stream compression), all of which the base image
compiles in. `ext-gd` is deliberately absent: dompdf only *suggests* it for
image processing, and neither PDF export view contains an image.

## Runtime

`docker/entrypoint.sh` runs on every container start, for **all three
services** — Railway's custom start command replaces the Dockerfile's `CMD`
while the `ENTRYPOINT` still applies, which is what gives the scheduler and
worker the same warmed caches and writable storage tree as the web service.

It:

- binds Apache to Railway's `$PORT`
- ensures `storage/app/medical` exists and is owned by `www-data` — necessary
  because a mounted Railway volume shadows the image's directory and arrives
  root-owned
- clears, then rebuilds, the config/route/view caches — at *start*, not at
  build, because the environment variables they bake in only exist at runtime
- runs `storage:link` (harmless; not actually required by this application —
  every medical file is served by an authorizing controller)

It deliberately does **not** run migrations. A redeploy overlaps containers,
and concurrent `migrate` runs against one database is how a schema ends up
half-applied. Migrating is a separate deliberate step.

## Services to create

### Web service

Deploy from this repo. `railway.json` selects the Dockerfile and sets the
healthcheck to `/up`. No start command override needed.

`/up` is Laravel's built-in health route, registered by
`withRouting(health: '/up')` in `bootstrap/app.php`. It carries **no
middleware**, so it does not touch the session or the database — it reports
that PHP is up, not that MySQL is reachable.

### Scheduler service

Deploy from the *same* repo, then override the start command with:

```
php artisan schedule:work
```

`schedule:work` is a long-running foreground process that internally ticks
every minute — the right shape for a container platform. Railway has no
crontab, and `schedule:run` would need an external trigger once per minute.

Do **not** run the scheduler inside the web container: it would compete with
HTTP request handling and would be duplicated by every replica.

**Four** commands are registered in `routes/console.php`:

| Task | Frequency | Consequence if it never runs |
|---|---|---|
| `consultations:mark-missed-slots` | every minute | Booked slots never become `missed`, which blocks physician takeover and blocks restarting a lapsed consultation. **This is the one that breaks workflow** — a TAM respondent who misses their slot has no path forward, and no error is shown anywhere. |
| `consultations:expire-intake-sessions` | every minute | Stored intake status goes stale. The availability gate itself is unaffected — `PhysicianAvailabilityService` already treats a stale open session as unavailable when it reads one. |
| `consultations:send-reminders` | every 15 minutes | Patients receive no reminder email before a booked consultation. De-duplication is `schedule_slots.reminder_sent_at`, not the run cadence. |
| `auth:clear-resets staff_invitations` | daily | Expired staff invitation tokens accumulate. They are already refused; this is hygiene. The broker name is required — omitting it would target `password_reset_tokens` instead. |

### Worker service — required

Deploy from the *same* repo, then override the start command with:

```
php artisan queue:work
```

**This corrects earlier documentation which stated no worker was needed.** That
was true when it was written and is now false. Four notifications implement
`ShouldQueue`, and `QUEUE_CONNECTION=database` stores them in the `jobs` table:

| Notification | Sent when |
|---|---|
| `ConsultationScheduled` | a physician schedules or reschedules a consultation |
| `FollowUpScheduled` | a follow-up is approved and scheduled |
| `ConsultationCompleted` | a consultation is completed |
| `ConsultationReminder` | 24 hours before a booked consultation (needs the scheduler too) |

**Nothing sends them unless a worker processes that table**, and the failure is
silent — no error reaches the user or the logs. These are exactly the emails a
TAM respondent notices.

`StaffAccountInvitation`, email verification and password-reset mail are
**synchronous by design** and are unaffected by the worker. Do not queue
`StaffAccountInvitation`: it carries the plaintext invitation token, which
queuing would serialize into `jobs.payload`. A test asserts it does not
implement `ShouldQueue`.

Run `php artisan queue:restart` after every deployment, or let the worker
service cycle — a long-running worker keeps the old code in memory.

### Healthcheck on the non-web services

`railway.json` sits at the repository root and applies to every service built
from it, so the scheduler and worker would inherit `healthcheckPath: /up`.
Neither listens on a port. **Clear the healthcheck path in each of those two
services' settings** (or point their config-as-code path elsewhere), and
confirm after the first deploy that neither is restart-looping.

## Environment variables

Set on **all three** services. Every variable below is tied to a real `env()`
call in `config/` or `database/seeders/`; see `.env.example` for the full
annotated list. There are **no** `env()` calls in `app/`, `routes/` or
`resources/` except `AdminUserSeeder`, which is safe because Railway supplies
real process environment variables rather than only a `.env` file.

### Application

| Variable | Value | Notes |
|---|---|---|
| `APP_NAME` | `CLSU Telemedicine` | Also seeds the session cookie name |
| `APP_ENV` | `production` | |
| `APP_KEY` | *generate fresh* | `php artisan key:generate --show`. **Never reuse the development key.** |
| `APP_DEBUG` | `false` | **Must be set.** Debug output would expose configuration, database credentials and file paths on any error page. |
| `APP_URL` | `https://<railway-domain>` | Signed verification and reset links, and `MAIL_EHLO_DOMAIN`, derive from this |

### Database

| Variable | Value |
|---|---|
| `DB_CONNECTION` | `mysql` — **required**, `config/database.php` defaults to `sqlite` |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | from the Railway MySQL service |

Use Railway's reference-variable syntax (e.g. `${{MySQL.MYSQLHOST}}`) so a
database rotation propagates automatically; confirm the exact variable names
the MySQL plugin exposes in the Railway dashboard. `config/database.php` also
honours a single `DB_URL` if you prefer the connection-string form —
`DB_CONNECTION=mysql` is still required alongside it.

**Do not copy local XAMPP values.** The development `.env` uses
`DB_PORT=3309` and `DB_DATABASE=telemed` on `127.0.0.1`; none of that applies
here.

### Session / cache / queue

| Variable | Value | Notes |
|---|---|---|
| `SESSION_DRIVER` | `database` | |
| `SESSION_SECURE_COOKIE` | `true` | **Must be set explicitly.** `config/session.php` has *no default* for this key, so leaving it unset means the session cookie is never marked `Secure` and a browser will send it over plain HTTP. |
| `SESSION_LIFETIME` | `120` | Optional; this is the default |
| `CACHE_STORE` | `database` | |
| `QUEUE_CONNECTION` | `database` | Requires the worker service above |

### Logging

| Variable | Value | Notes |
|---|---|---|
| `LOG_CHANNEL` | `stderr` | The container filesystem is ephemeral; a log file under `storage/` is lost on restart. Railway captures stderr. |
| `LOG_LEVEL` | `error` | `debug` records far more context than a medical system should retain |

### Cloudinary

| Variable | Required | Notes |
|---|---|---|
| `CLOUDINARY_URL` | Yes | One URL carries cloud name, API key **and** API secret: `cloudinary://<api_key>:<api_secret>@<cloud_name>`. Use the **client's own cloud**, not one shared with development or with the Hostinger QA environment. |
| `CLOUDINARY_UPLOAD_TIMEOUT` | No | Defaults to 10 seconds |
| `CLOUDINARY_SIGNED_URL_TTL` | No | Defaults to 300 seconds |

### Jitsi (JaaS / 8x8)

| Variable | Required | Notes |
|---|---|---|
| `JITSI_DOMAIN` | Yes | `8x8.vc` |
| `JITSI_APP_ID` | Yes | The `vpaas-magic-cookie-…` tenant identifier |
| `JITSI_API_KEY_ID` | Yes | Becomes the JWT `kid` header |
| `JITSI_PRIVATE_KEY` | Yes | RSA private key on a **single line**, newlines escaped as literal `\n`. `config/services.php` restores them at config-build time, which keeps it correct under `config:cache`. |

All four are mandatory: `JitsiService::requiredConfig()` throws if any is
empty, naming the config key and never the value.

Video requires **HTTPS** — `getUserMedia` will not run outside a secure
context. Railway terminates TLS at its edge, which is also why
`bootstrap/app.php` sets `trustProxies(at: '*')`; that is correct here
precisely because a Railway service is only reachable through that edge.

### Mail

| Variable | Required | Notes |
|---|---|---|
| `MAIL_MAILER` | Yes | `smtp`. **Must be set** — `config/mail.php` defaults to `log`, which silently discards every email. |
| `MAIL_HOST`, `MAIL_PORT` | Yes | Provider-specific |
| `MAIL_USERNAME`, `MAIL_PASSWORD` | Yes | Provider-specific |
| `MAIL_FROM_ADDRESS` | Yes | Must be on a domain verified with the provider |
| `MAIL_FROM_NAME` | Yes | e.g. `CLSU Infirmary Telemedicine` |
| `MAIL_SCHEME` | No | Leave unset. Laravel 12 has no `MAIL_ENCRYPTION`; Symfony derives TLS from the port (587/2525 STARTTLS, 465 implicit). |

**Do not reuse the personal Gmail credentials from the development `.env`.**
Provision a mail identity for this deployment. Note that domain verification
with most providers has a lead time of hours to a day, and mail is
load-bearing: most routes are `auth`+`verified`, so without working mail a
patient cannot complete a first login and no staff account can be provisioned.

### Consultation intake

Both exist in `config/consultations.php`; both are optional, non-secret
operational limits.

| Variable | Default | Meaning |
|---|---|---|
| `CONSULTATION_QUEUE_LIMIT` | `20` | Maximum consultation requests sitting at `request_status = 'pending'` before new requests are refused with HTTP 503. A **concurrency** gate on the nurse review queue, counted globally — not a cumulative cap. A request that reaches `reviewed` frees a slot immediately. |
| `CONSULTATION_INTAKE_STALE_AFTER` | `120` | Seconds a physician's open intake session may go without a heartbeat before it stops keeping consultations open |

There is deliberately **no total-consultation limit, no Jitsi room cap, no
participant cap and no MAU counting** in this deployment. JaaS usage and cost
are handled separately, outside the application.

### Admin provisioning — one-off

| Variable | Required | Notes |
|---|---|---|
| `ADMIN_EMAIL` | For the seed run | Must be a valid email address or the seeder skips |
| `ADMIN_PASSWORD` | For the seed run | **Secret.** Remove after seeding. |
| `ADMIN_FIRST_NAME` | No | Defaults to `Admin` |
| `ADMIN_LAST_NAME` | No | Defaults to `User` |

### Never commit

`APP_KEY`, `DB_PASSWORD`, `CLOUDINARY_URL`, `JITSI_PRIVATE_KEY`,
`MAIL_PASSWORD`, `ADMIN_PASSWORD`. All of these live in Railway's variable
store only. `.env` is gitignored and has never been committed; keep it that
way.

## After the first deploy

### 1. Verify the document root — before anything else

```
https://<domain>/.env      → must return 404
https://<domain>/vendor/   → must return 403 or 404
https://<domain>/up        → must return 200
```

If `/.env` returns a file, stop and fix the document root before going
further.

### 2. Migrate

One-off, against the **empty** database, from a shell on the web service:

```
php artisan migrate --force
```

Note that two migrations (`alter_consultations_status_enum` and
`alter_status_enum_on_schedule_slots_table`) return early on SQLite and
therefore execute for the first time here, on MySQL. Confirm the command exits
cleanly rather than assuming it did.

### 3. Create the first admin

There is no admin in a fresh database, and `admin/users/*` is the only path
that provisions nurses and physicians, so without this the deployment is
unusable.

`database/seeders/AdminUserSeeder.php` creates exactly one admin from
environment variables and is idempotent: if an account with the configured
email already exists it makes no changes at all, so re-running it is always
safe. `User::booted()` auto-verifies an admin, so the account can sign in
immediately — the seeder does not need to, and does not, set that itself.

1. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` as Railway variables on the web
   service.
2. Run:

   ```
   php artisan db:seed --class=AdminUserSeeder --force
   ```

3. Sign in as that admin and confirm access.
4. Remove `ADMIN_PASSWORD` from the Railway variables. This does **not** affect
   the account that was already created — only the password's hash is stored,
   never the plaintext.

Every later staff account goes through the invitation flow at
`/admin/users/create`, which emails the invitee a link to set their own
password. This seeder is only for the one account that has to exist before that
flow can be used.

### Seeding rules

- **Never run the plain `php artisan db:seed`.** `DatabaseSeeder` creates
  `test@example.com` with the password `password`, active and pre-verified.
  That account must never exist in a client environment.
- **Do not run `QaTestUsersSeeder`** on this deployment. It creates six
  accounts with passwords hardcoded in the file and echoed to the console. It
  exists for MISO's QA on Hostinger, not for client/TAM use.
- `AdminUserSeeder` is deliberately **not** registered in `DatabaseSeeder` and
  is always invoked by its own class name.
- **Do not copy the Hostinger QA database into Railway.** This environment
  starts with exactly one admin and nothing else; patients self-register and
  staff are invited.

## Storage

| Category | Where it lives | Survives redeploy |
|---|---|---|
| Medical attachments, prescriptions (normal path) | Cloudinary, `authenticated` delivery | Yes |
| Medical files when Cloudinary is unreachable | `storage/app/medical` in the container | **Only with a volume** |
| Sessions, cache, queued jobs | MySQL | Yes |
| Logs | stderr → Railway | Retained by Railway |
| Vite assets | rebuilt each deploy | n/a |

`App\Services\MedicalFileStorage` uploads every medical file to Cloudinary with
delivery type `authenticated` and reads it back through a short-lived signed
URL minted only after the request has been authorized. When Cloudinary is
unreachable it falls back to the private local disk
(`config/filesystems.php` → `message_attachments`, rooted at
`storage/app/medical`) and stores a disk-relative reference in the database.

**Railway's filesystem is ephemeral.** Without a volume, a fallback file is
lost on the next redeploy or restart while the database row survives pointing
at a file that is gone — a physician clicking that attachment gets a 404.

**Attach a Railway volume mounted at:**

```
/var/www/html/storage/app/medical
```

`docker/entrypoint.sh` chowns that path to `www-data` at start, which is
required because a mounted volume shadows the image's directory and arrives
root-owned.

Every fallback is logged by `MedicalFileStorage`, so if you choose to deploy
without the volume, the exposure is at least detectable in the Railway logs.

## After every deployment

The entrypoint rebuilds the config, route and view caches automatically on each
container start, so no manual cache commands are needed. Two things are not
automatic:

```
php artisan migrate --force      # only when migrations changed
php artisan queue:restart        # or let the worker service cycle
```

## Not required

- **No Redis.** Sessions, cache and queue are all database-backed.
- **No separate Node service.** Node is build-time only and is absent from the
  runtime image.
- **No replicas above 1** for the scheduler or worker, which would duplicate
  every scheduled run and every job attempt.
