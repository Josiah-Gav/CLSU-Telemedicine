# Hostinger Deployment

How the CLSU Infirmary Telemedicine system is deployed. Written for MISO, who
deploy and operate the production environment, and for the development team.

## Deployment model

There is **one** deployed environment, and MISO controls it.

| Stage | Who | Where |
|---|---|---|
| **Development** | Development team | Local XAMPP — Apache, PHP, MySQL/MariaDB |
| **Internal QA** | Development team | Local, before a build is handed over |
| **Deployment** | MISO | Hostinger, after MISO's own QA of the approved build |
| **Client/TAM testing** | Development team | Against MISO's Hostinger deployment, after MISO completes deployment and QA |

The team does not host a second public copy of the application.

**Nothing in this repository is Hostinger-specific**, and no container or
platform configuration is needed. Hostinger serves PHP through a conventional
web server with a document root, which is how the application already runs
under XAMPP.

This document records what the environment must provide. It is a requirements
list, not a script. Anything that depends on how MISO configures Hostinger is
marked as a decision for MISO rather than assumed.

## Runtime

| Requirement | Value | Notes |
|---|---|---|
| PHP | **8.2 or 8.3** | `composer.json` requires `^8.2`. Do not run 8.1. |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `pcre`, `session`, `tokenizer`, `curl` | All but `pdo_mysql` and `curl` are compiled into stock PHP. `openssl` is not optional — Jitsi tokens are RS256-signed in-process. `curl` is used by the Cloudinary SDK. |
| MySQL | **5.7+ / MariaDB 10.2+** | `notifications.data` is a native JSON column. No MySQL 8-only feature is used — `SymptomAnalytics` aggregates in PHP specifically to avoid `JSON_TABLE`. |
| Web server | URL rewriting enabled | `public/.htaccess` ships with the application and holds the front-controller rewrite. |
| Cron | Every minute | Required for the scheduler. See *Scheduler*. |

## Document root

The single most important setting.

```
document root → /public
```

Point the domain at the application's `public/` directory. If the document root
is the project root instead, `.env`, `storage/` (medical files and logs),
`vendor/` and the whole source tree are served over HTTP.

On Hostinger this usually means either pointing the domain's root at
`public/`, or placing the application outside `public_html` and symlinking
`public_html` to it. **Verify by requesting `https://<domain>/.env` — it must
return 404**, not a file.

## Build

Hostinger shared hosting generally has no Node. Two options:

1. **Build locally, upload the output** (simplest): run `npm ci && npm run build`
   on a developer machine and upload `public/build/`. Note that `public/build`
   is gitignored, so it will not arrive via `git pull` — it must be copied.
2. **Build on the server** if the plan provides Node ≥ 20.19 or ≥ 22.12
   (`vite@7` and `laravel-vite-plugin@2` both require this).

Either way `public/build/manifest.json` must exist at runtime, or every page
throws on the `@vite` directive.

Composer must run with production flags:

```
composer install --no-dev --optimize-autoloader
```

## Configuration

Create `.env` on the server from `.env.example`, which documents every variable.

- `APP_ENV=production`, `APP_DEBUG=false` — debug output would expose
  configuration.
- `APP_KEY` — generate a fresh one (`php artisan key:generate`). Do **not** reuse
  a development key.
- `APP_URL=https://<domain>`
- `DB_CONNECTION=mysql` plus `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`,
  `DB_PASSWORD` for the Hostinger database.
- `SESSION_DRIVER=database`, `CACHE_STORE=database`, `QUEUE_CONNECTION=database`.
- `SESSION_SECURE_COOKIE=true` — the site must be served over HTTPS.
- `LOG_CHANNEL=stack`, `LOG_LEVEL=error`. The filesystem persists, so
  `storage/logs/laravel.log` survives; `LOG_STACK=daily` rotates it instead of
  letting one file grow without bound.
- Mail, Cloudinary and Jitsi as below.

## Trusted proxies — decision for MISO

`bootstrap/app.php` trusts forwarded headers from **any** address (`'*'`). This
matters in both directions, and which one applies depends on how MISO sets up
the domain, which this repository cannot know:

| If the site is reached… | Then |
|---|---|
| **Only through a proxy/CDN that terminates TLS** (e.g. Cloudflare in proxy mode, or a Hostinger CDN/load balancer) | `'*'` is needed. Without it Laravel sees plain HTTP, generates `http://` URLs on an `https://` site, `@vite` assets are blocked as mixed content, and Jitsi video refuses to start. |
| **Directly** (TLS terminated by Hostinger's own web server, no proxy in front) | `'*'` is not needed and is unsafe: any client can send its own `X-Forwarded-For` and evade the per-IP login and guest-auth throttles, or send `X-Forwarded-Host`/`Proto`. Tighten it before go-live — to the proxy's published address ranges, or back to `['127.0.0.1', '::1']`. |

A proxy in front is only safe with `'*'` if the origin server cannot also be
reached directly, bypassing it.

## External services

| Service | Requirement |
|---|---|
| **Cloudinary** | `CLOUDINARY_URL`. Medical uploads use `authenticated` delivery and are read back through short-lived signed URLs. Use the client's own cloud — not one shared with development. |
| **Jitsi JaaS** | `JITSI_DOMAIN`, `JITSI_APP_ID`, `JITSI_API_KEY_ID`, `JITSI_PRIVATE_KEY` (single line, newlines escaped as `\n`). Video needs **HTTPS** — `getUserMedia` will not run outside a secure context, so the domain must have a valid certificate. |
| **SMTP** | `MAIL_MAILER=smtp` plus `MAIL_*`. Load-bearing: every route is `auth`+`verified`, so without working mail nobody can complete a first login or accept a staff invitation. |

## Database setup

```
php artisan migrate --force
```

Against an **empty** database. **Never run the plain `php artisan db:seed`** —
`DatabaseSeeder` creates `test@example.com` with the password `password`, which
must never exist in production. `AdminUserSeeder` below is the only seeder
intended for production/admin provisioning, and it is always invoked by its
own class name, never through the default seeder.

### Create the first admin

There is no admin in a fresh database, and `admin/users/*` is the only path that
provisions nurses and physicians, so without this the deployment is unusable.
`database/seeders/AdminUserSeeder.php` creates exactly one admin from
environment variables, and is idempotent: if an account with the configured
email already exists it makes no changes at all, so re-running it is always
safe. `User::booted()` auto-verifies an admin, so the account can sign in
immediately — the seeder does not need to, and does not, set that itself.

Lifecycle for a fresh deployment:

1. `php artisan migrate --force`
2. Set `ADMIN_EMAIL` and `ADMIN_PASSWORD` as real environment variables on the
   server (not in a file that gets committed — see the warnings below).
3. `php artisan db:seed --class=AdminUserSeeder --force`
4. Sign in as that admin and confirm access.
5. Remove `ADMIN_PASSWORD` from the server environment if it's no longer
   needed. This does **not** affect the account that was already created —
   only the password's hash is stored, never the plaintext, so deleting the
   environment variable neither removes nor disables the admin.

`ADMIN_FIRST_NAME`/`ADMIN_LAST_NAME` are optional and default to `Admin`/`User`
if omitted. Every later staff account goes through the invitation flow at
`/admin/users/create` instead, which emails the invitee a link to set their
own password — this seeder is only for the one account that has to exist
before that flow can be used.

**Warnings:**

- Never commit a real admin email/password to Git, including in `.env` or any
  deployment script. `.env.example` ships with `ADMIN_EMAIL`/`ADMIN_PASSWORD`
  left blank for exactly this reason.
- Never run the default `php artisan db:seed` in production (see above).
- The password is only ever read from the environment at the moment the
  seeder runs; it is never logged, echoed, or persisted anywhere but the
  hashed `users.password` column.

## Scheduler

Four scheduled commands are registered in `routes/console.php`. Hostinger
provides cron, so the scheduler is driven by **one** cron entry:

```
* * * * * cd /home/<user>/<app> && php artisan schedule:run >> /dev/null 2>&1
```

Laravel's scheduler decides internally which commands are due; do **not** add a
cron line per command. (`php artisan schedule:work` is the long-running
alternative for hosts without cron — it is not needed here.)

| Task | Frequency | Consequence if it never runs |
|---|---|---|
| `consultations:mark-missed-slots` | every minute | Booked slots never become `missed`, which blocks physician takeover and blocks restarting a lapsed consultation. |
| `consultations:expire-intake-sessions` | every minute | Stored intake status goes stale. The availability gate itself is unaffected — `PhysicianAvailabilityService` treats a stale open session as unavailable when it reads one. |
| `consultations:send-reminders` | every 15 minutes | Patients receive no reminder email before a booked consultation. |
| `auth:clear-resets staff_invitations` | daily | Expired invitation tokens accumulate. They are already refused; this is hygiene. |

The cron path and PHP binary path depend on the Hostinger account — MISO should
confirm them in hPanel.

## Queue worker — required

Four email notifications implement `ShouldQueue` — `ConsultationScheduled`,
`FollowUpScheduled`, `ConsultationCompleted` and `ConsultationReminder` — and
`QUEUE_CONNECTION=database` stores them in the `jobs` table. **Nothing sends
them unless a worker processes that table.** `StaffAccountInvitation` and
password-reset mail are synchronous and unaffected.

How the worker runs is a decision for MISO, depending on the Hostinger plan:

- **VPS / process supervisor:** a persistent `php artisan queue:work` kept alive
  by the supervisor.
- **Shared hosting (no persistent processes):** a cron entry that drains the
  queue and exits, e.g.
  `* * * * * cd /home/<user>/<app> && php artisan queue:work --stop-when-empty >> /dev/null 2>&1`.

Restart or let the worker cycle after every deployment, since a long-running
worker keeps the old code in memory (`php artisan queue:restart`).

## Not required

- **No Redis.** Sessions, cache and queue are all database-backed.
- **No Docker.** The application runs directly on PHP and a web server.
- **No `storage:link`** strictly speaking — no view resolves a URL through
  `public/storage`, and every medical file is served by an authorizing
  controller. Harmless to run.

## Writable directories and backups

`storage/` and `bootstrap/cache/` must be writable by the web-server user.

The filesystem persists, so the Cloudinary local fallback is durable: if
Cloudinary is unreachable during an upload, the file is written to
`storage/app/medical` and stays there. Every fallback is logged by
`MedicalFileStorage`. That directory holds patient files, so it must be
included in backups alongside the database, and must never be web-accessible —
it is not, provided the document root is `public/`.

## After every deployment

```
php artisan migrate --force
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan queue:restart
```

Re-run the cache commands after any `.env` change — a cached config ignores
later edits.
