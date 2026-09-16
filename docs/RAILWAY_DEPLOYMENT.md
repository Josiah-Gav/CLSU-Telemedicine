# Railway Deployment

How this repository is built and run on Railway, and the steps that must be
performed by hand. Written for whoever deploys or re-deploys the application.

## Architecture

Two Railway services plus one database. Nothing else.

| Component | What it is | Required |
|---|---|---|
| **Web** | This repo's `Dockerfile` — Apache + mod_php serving `public/` | Yes |
| **Scheduler** | The *same image*, started with `php artisan schedule:work` | Yes |
| **MySQL** | Railway MySQL plugin | Yes |
| Queue worker | — | **No.** Nothing in the application implements `ShouldQueue`; there is no `app/Jobs`, `app/Events` or `app/Listeners`, and all mail is sent synchronously by design. |
| Redis | — | **No.** Sessions, cache and queue are all database-backed. |
| Volume | — | Optional. See *Storage* below. |

### Why a Dockerfile rather than Nixpacks

The document root. Serving the repository root instead of `public/` publishes
`.env`, `storage/` (medical files) and `vendor/` over HTTP. A Dockerfile makes
that one explicit, reviewable line in `docker/000-default.conf`; a build
provider derives it from heuristics. The PHP and Node versions are pinned for
the same reason — nothing about the build should change underneath a demo.

## Build

Handled entirely by the `Dockerfile`:

1. `composer install --no-dev --optimize-autoloader`
2. `npm ci && npm run build` → `public/build/manifest.json`
   (the build *fails* if the manifest is missing, rather than shipping an image
   whose every page throws on `@vite`)
3. `composer dump-autoload --no-dev --optimize` (its post-autoload-dump hook
   runs `package:discover`; the composer binary is removed again in the same
   layer so it is not present in the running image)

`public/build` is intentionally **not** committed — it is generated per deploy.

## Runtime

`docker/entrypoint.sh` on each container start:

- binds Apache to Railway's `$PORT`
- clears, then rebuilds, the config/route/view caches — at *start*, not at build,
  because the environment variables they bake in only exist at runtime
- runs `storage:link` (harmless; not actually required by this application)

It deliberately does **not** run migrations. A redeploy overlaps containers, and
concurrent `migrate` runs against one database is how a schema ends up
half-applied. Migrating is a separate deliberate step.

## Services to create

**Web service** — deploy from this repo. `railway.json` selects the Dockerfile
and sets the healthcheck to `/up`. No start command override needed.

**Scheduler service** — deploy from the *same* repo, then override the start
command with:

```
php artisan schedule:work
```

`schedule:work` is a long-running foreground process that internally ticks every
minute — the right shape for a container platform. Railway has no crontab, and
`schedule:run` would need an external trigger once per minute.

Do **not** run the scheduler inside the web container: it would compete with
HTTP request handling and would be duplicated by every replica.

| Task | Frequency | Consequence if it never runs |
|---|---|---|
| `consultations:mark-missed-slots` | every minute | Booked slots never become `missed`, which blocks physician takeover and blocks restarting a lapsed consultation. **This is the one that matters.** |
| `consultations:expire-intake-sessions` | every minute | Stored intake status goes stale. The availability gate itself is unaffected — `PhysicianAvailabilityService` already treats a stale open session as unavailable when it reads one. |
| `auth:clear-resets staff_invitations` | daily | Expired invitation tokens accumulate. They are already refused; this is hygiene. |

## Environment variables

Set on **both** services. See `.env.example` for the full annotated list.

**Application:** `APP_KEY` (generate fresh — never reuse the development key),
`APP_ENV=production`, `APP_DEBUG=false`, `APP_URL=https://<domain>`, `APP_NAME`.

**Database:** `DB_CONNECTION=mysql` plus host/port/database/username/password
from the Railway MySQL service.

**Session / cache / queue:** `SESSION_DRIVER=database`,
`SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`, `QUEUE_CONNECTION=sync`.

**Logging:** `LOG_CHANNEL=stderr`, `LOG_LEVEL=error`. The container filesystem is
ephemeral, so a log file under `storage/` is lost on restart.

**Cloudinary:** `CLOUDINARY_URL` — required; one URL carrying cloud name, API key
and API secret.

**Jitsi:** `JITSI_DOMAIN`, `JITSI_APP_ID`, `JITSI_API_KEY_ID`,
`JITSI_PRIVATE_KEY` (single line, newlines escaped as `\n`).

**Mail:** `MAIL_MAILER=smtp` plus host/port/username/password and
`MAIL_FROM_ADDRESS` / `MAIL_FROM_NAME`. This is load-bearing: every route is
`auth`+`verified`, so if mail fails **no patient can complete a first login and
no staff account can be provisioned.**

## After the first deploy

1. **Migrate** — one-off, against the empty database:
   ```
   php artisan migrate --force
   ```
2. **Do not seed.** `DatabaseSeeder` creates `test@example.com` with the password
   `password`.
3. **Create the first admin** by hand. There is no admin in a fresh database, and
   `admin/users/*` is the only path that provisions nurses and physicians, so
   without this the deployment is unusable. `User::booted()` auto-verifies an
   admin, so the account can sign in immediately.

   From a one-off shell on the web service (`railway run php artisan tinker`,
   or the service's own shell):

   ```php
   App\Models\User::create([
       'first_name'     => 'Firstname',
       'last_name'      => 'Lastname',
       'email'          => 'admin@clsu.edu.ph',
       'password'       => Illuminate\Support\Facades\Hash::make('<a strong password>'),
       'role'           => 'admin',
       'account_status' => 'active',
       'user_type'      => 'staff',
       'department'     => 'Infirmary',
   ]);
   ```

   Type the password interactively rather than pasting it into a script or a
   deploy log, and change it after first sign-in. Create exactly one admin this
   way; every later staff account goes through the invitation flow at
   `/admin/users/create`, which emails the invitee a link to set their own
   password and never exposes one to the administrator.
4. **Verify the document root** before anything else: `https://<domain>/.env`
   must return 404.

## Storage

| Category | Where it lives | Survives redeploy |
|---|---|---|
| Medical attachments, prescriptions (normal path) | Cloudinary, `authenticated` delivery | Yes |
| Medical files when Cloudinary is unreachable | `storage/app/medical` in the container | **No** |
| Sessions, cache | MySQL | Yes |
| Logs | stderr → Railway | Retained by Railway |
| Vite assets | rebuilt each deploy | n/a |

The one real exposure is the fallback row: if Cloudinary is briefly unreachable
during an upload, the file is written locally and lost on the next redeploy,
while the database row survives pointing at a file that is gone. Every fallback
is logged by `MedicalFileStorage`, so it is detectable.

For a TAM deployment this is an accepted risk rather than a reason to add a
volume. Attach a Railway volume mounted at `/var/www/html/storage/app/medical`
before real patient data, or if the logs show the fallback is being used.
