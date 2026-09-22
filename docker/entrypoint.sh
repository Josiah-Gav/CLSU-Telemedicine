#!/bin/sh
set -e

# Container start-up for the Telemedicine services on Railway.
#
# This runs for ALL THREE services built from this image — web, scheduler and
# worker — because Railway's custom start command replaces the Dockerfile's CMD
# while the ENTRYPOINT still applies. That is deliberate: the scheduler and the
# worker need the same warmed config/route/view caches the web service does, and
# they need the same writable storage tree. The Apache-specific lines below are
# harmless no-ops in those containers.
#
# Deliberately does NOT run migrations. Railway may start more than one
# container (a redeploy overlaps the old and new ones, and any replica count
# above 1 starts several at once), and concurrent `migrate` runs against one
# database is how a schema ends up half-applied. Migrating is a separate,
# deliberate step — see docs/RAILWAY_DEPLOYMENT.md.
#
# It also does not create an admin account. Bootstrapping the first admin is a
# controlled one-off through AdminUserSeeder, not something that should happen
# on every boot.

PORT="${PORT:-8080}"

# Railway assigns the port per deployment, so Apache is pointed at it here
# rather than being baked into the image.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/\${PORT}/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# ServerName silences Apache's start-up warning and keeps the logs readable.
if ! grep -q '^ServerName' /etc/apache2/apache2.conf; then
    echo "ServerName localhost" >> /etc/apache2/apache2.conf
fi

# When a Railway volume is mounted at storage/app/medical, the mount shadows the
# directory the image created and arrives owned by root, which would make every
# Cloudinary-fallback write fail. Re-establishing ownership here is the only
# point at which the mounted filesystem is actually visible.
#
# Cheap in practice: this directory only ever receives a file when a Cloudinary
# upload has failed, so it stays small.
# ponytail: recursive chown on every boot; switch to a one-shot marker file if
# the fallback directory ever grows large enough for this to be slow.
mkdir -p storage/app/medical
chown -R www-data:www-data storage/app/medical || true

# Caches are built at start, not at build time: the environment variables they
# bake in (APP_URL, database, mail, Cloudinary, Jitsi) only exist at runtime on
# Railway. Caching at build would freeze a set of nulls into the image.
#
# Cleared first so a redeploy can never run against a cache left by an earlier
# image layer, and each step is tolerant of failure — a cold cache is slower but
# correct, whereas refusing to boot over it would take the whole service down.
php artisan config:clear >/dev/null 2>&1 || true
php artisan route:clear  >/dev/null 2>&1 || true
php artisan view:clear   >/dev/null 2>&1 || true

php artisan config:cache || echo "warning: config:cache failed; continuing with runtime config"
php artisan route:cache  || echo "warning: route:cache failed; continuing without a route cache"
php artisan view:cache   || echo "warning: view:cache failed; views will compile on demand"

# Not required by this application: no view or controller resolves a URL
# through the public/storage symlink, and every medical file is served by an
# authorizing controller (AttachmentController, ConsultationMessageController).
# Created anyway because it is harmless, and because its absence would be a
# confusing failure if a future view did use it.
php artisan storage:link >/dev/null 2>&1 || true

exec "$@"
