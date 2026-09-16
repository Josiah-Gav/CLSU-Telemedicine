# Hostinger QA Environment

The team's internal QA/testing deployment. Separate from Railway, which hosts
the client/TAM deployment.

**Nothing in this repository is Hostinger-specific.** No configuration has been
added for it, because none is needed: Hostinger is conventional PHP/Apache
shared hosting and the application already runs that way locally under XAMPP.
The `Dockerfile`, `railway.json`, `.dockerignore` and `docker/` directory are
for Railway only and are simply ignored here.

This document records what the environment must provide. It is a requirements
list, not a script.

## Runtime

| Requirement | Value | Notes |
|---|---|---|
| PHP | **8.2 or 8.3** | `composer.json` requires `^8.2`. Do not run 8.1. |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `ctype`, `dom`, `fileinfo`, `filter`, `hash`, `iconv`, `json`, `libxml`, `pcre`, `session`, `tokenizer`, `curl` | All but `pdo_mysql` and `curl` are compiled into stock PHP. `openssl` is not optional — Jitsi tokens are RS256-signed in-process. `curl` is used by the Cloudinary SDK. |
| MySQL | **5.7+ / MariaDB 10.2+** | `notifications.data` is a native JSON column. No MySQL 8-only feature is used — `SymptomAnalytics` aggregates in PHP specifically to avoid `JSON_TABLE`. |
| Apache | with `mod_rewrite` | `public/.htaccess` ships with the application and holds the front-controller rewrite. |

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
`public_html` to it. **Verify by requesting `https://<qa-domain>/.env` — it must
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
For a QA environment:

- `APP_ENV=production`, `APP_DEBUG=false` — QA should behave like production,
  including its error pages. Debug output would expose configuration.
- `APP_KEY` — generate a fresh one (`php artisan key:generate`). Do **not** reuse
  the development or Railway key.
- `APP_URL=https://<qa-domain>`
- `SESSION_SECURE_COOKIE=true` if QA is served over HTTPS.
- `LOG_LEVEL=error`. `LOG_CHANNEL=stack` is fine here — unlike Railway, the
  filesystem persists, so `storage/logs/laravel.log` survives. Consider
  `LOG_STACK=daily` so it rotates instead of growing without bound.
- Database, mail, Cloudinary and Jitsi as below.

**Trusted proxies:** `bootstrap/app.php` trusts `*`, which is correct behind a
proxy that terminates TLS. If the QA host is reached directly by clients rather
than through a proxy or CDN, that setting lets a client present its own
`X-Forwarded-*` headers — re-examine it before treating QA as internet-facing.

## External services

Use **separate credentials from Railway**, so QA testing cannot touch client
data.

| Service | Requirement |
|---|---|
| **Cloudinary** | `CLOUDINARY_URL`. Use a **separate cloud or folder** from the TAM deployment. Medical uploads use `authenticated` delivery; QA must not write into the client's asset store. |
| **Jitsi JaaS** | `JITSI_DOMAIN`, `JITSI_APP_ID`, `JITSI_API_KEY_ID`, `JITSI_PRIVATE_KEY`. Video needs **HTTPS** — `getUserMedia` will not run outside a secure context, so QA must have a valid certificate. |
| **SMTP** | `MAIL_*`. Load-bearing even in QA: every route is `auth`+`verified`, so without working mail nobody can complete a first login or accept a staff invitation. A sandbox inbox (e.g. Mailtrap) is appropriate for QA and avoids mailing real people during testing. |

## Database setup

```
php artisan migrate --force
```

Against an **empty** database. Never run `php artisan db:seed` — `DatabaseSeeder`
creates `test@example.com` with the password `password`.

Then create one admin by hand; the procedure is identical to Railway's and is
written out in [RAILWAY_DEPLOYMENT.md](RAILWAY_DEPLOYMENT.md) under *After the
first deploy*. Every other staff account goes through the invitation flow.

## Scheduler

Three scheduled commands, two of them every minute. Unlike Railway, Hostinger
usually offers cron, so use it:

```
* * * * * cd /home/<user>/<app> && php artisan schedule:run >> /dev/null 2>&1
```

One entry. Laravel's scheduler decides internally which commands are due;
do **not** add a cron line per command.

Without it, booked schedule slots never become `missed`, which blocks physician
takeover and blocks restarting a consultation whose window has lapsed.

## Not required

- **No queue worker.** Nothing implements `ShouldQueue`; there is no `app/Jobs`,
  `app/Events` or `app/Listeners`, and mail is synchronous by design.
- **No Redis.** Sessions, cache and queue are all database-backed.
- **No Docker.** The Railway `Dockerfile` is irrelevant here.
- **No `storage:link`** strictly speaking — no view resolves a URL through
  `public/storage`, and every medical file is served by an authorizing
  controller. Harmless to run.

## Writable directories

`storage/` and `bootstrap/cache/` must be writable by the web-server user.
Unlike the Railway container, this filesystem persists, so the Cloudinary local
fallback is durable here — a file written when Cloudinary is unreachable stays
put across deployments.

## After deploying

```
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

Re-run these after any `.env` change — a cached config ignores later edits.
