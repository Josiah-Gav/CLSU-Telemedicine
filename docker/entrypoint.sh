#!/bin/sh
set -e

# Container start-up for the Telemedicine web service.
#
# Deliberately does NOT run migrations. Railway may start more than one
# container (a redeploy overlaps the old and new ones, and any replica count
# above 1 starts several at once), and concurrent `migrate` runs against one
# database is how a schema ends up half-applied. Migrating is a separate,
# deliberate step — see the deployment checklist.
#
# It also does not create an admin account. Bootstrapping the first admin is a
# controlled one-off, not something that should happen on every boot.

PORT="${PORT:-8080}"

# Railway assigns the port per deployment, so Apache is pointed at it here
# rather than being baked into the image.
sed -i "s/^Listen .*/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/\${PORT}/${PORT}/g" /etc/apache2/sites-available/000-default.conf

# ServerName silences Apache's start-up warning and keeps the logs readable.
if ! grep -q '^ServerName' /etc/apache2/apache2.conf; then
    echo "ServerName localhost" >> /etc/apache2/apache2.conf
fi

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
# authorizing controller. Created anyway because it is harmless, and because
# its absence would be a confusing failure if a future view did use it.
php artisan storage:link >/dev/null 2>&1 || true

exec "$@"
