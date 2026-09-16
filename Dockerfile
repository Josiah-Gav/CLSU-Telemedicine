# CLSU Infirmary Telemedicine — Railway image.
#
# A Dockerfile rather than Railway's Nixpacks PHP provider, for one reason: the
# document root. Serving the repository root instead of public/ would publish
# .env, storage/ and vendor/ over HTTP, which is the single worst failure this
# deployment can have. Nixpacks derives that from build-time heuristics; here it
# is one explicit line that can be read, reviewed and tested. The PHP and Node
# versions are pinned for the same reason — the build cannot drift underneath a
# capstone demo.
#
# Apache with mod_php rather than nginx + php-fpm: one process instead of two,
# so no supervisor, and public/.htaccess (already in the repository, already
# correct) does the URL rewriting. At the expected TAM volume this is ample.

# ---------------------------------------------------------------------------
# Stage 1 — PHP dependencies
# ---------------------------------------------------------------------------
FROM composer:2 AS vendor

WORKDIR /app

# Manifests first so this layer is reused whenever only application code moves.
COPY composer.json composer.lock ./

# --no-scripts: package:discover runs artisan, which needs application files
# that are not in this layer yet. The autoloader is completed in the final
# stage, once the full source is present.
RUN composer install \
        --no-dev \
        --no-scripts \
        --no-interaction \
        --prefer-dist \
        --optimize-autoloader

# ---------------------------------------------------------------------------
# Stage 2 — Frontend assets
# ---------------------------------------------------------------------------
# Node is a build-time dependency only; it is absent from the runtime image.
# Pinned to 22 because vite@7 and laravel-vite-plugin@2 both declare
# engines.node ^20.19.0 || >=22.12.0.
FROM node:22-alpine AS assets

WORKDIR /app

# npm ci (not install) so the build is reproducible from package-lock.json and
# cannot silently resolve a different dependency tree than the one tested.
COPY package.json package-lock.json ./
RUN npm ci

COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources

# tailwind.config.js scans Laravel's pagination views inside vendor/, so that
# path has to exist or those utility classes are purged from the bundle.
COPY --from=vendor /app/vendor ./vendor

RUN npm run build

# Fail the build here rather than shipping an image whose every page throws on
# the @vite directive for a missing manifest.
RUN test -f public/build/manifest.json

# ---------------------------------------------------------------------------
# Stage 3 — Runtime
# ---------------------------------------------------------------------------
# php:8.2 matches composer.json's "php": "^8.2" and the 8.2 used in development.
# Deliberately not upgraded as part of a deployment change.
FROM php:8.2-apache AS runtime

# pdo_mysql is the only extension the application needs that the base image does
# not already enable. Everything else composer.lock asks for (ctype, dom,
# fileinfo, filter, hash, iconv, json, libxml, mbstring, openssl, pcre, session,
# tokenizer) plus curl, which the Cloudinary SDK uses, is compiled in by default.
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite headers \
    && rm -rf /var/lib/apt/lists/*

# Laravel's own recommended baseline, applied to the production INI.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini"

# Attachment limits come from the application: 50 MB for a video message
# attachment, 10 MB otherwise, up to 3 files per message. PHP has to accept a
# request slightly larger than the largest permitted upload, or the framework
# validation that produces the friendly error is never reached.
RUN { \
        echo 'upload_max_filesize = 64M'; \
        echo 'post_max_size = 72M'; \
        echo 'memory_limit = 256M'; \
        echo 'max_execution_time = 120'; \
        echo 'expose_php = Off'; \
    } > "$PHP_INI_DIR/conf.d/99-telemed.ini"

WORKDIR /var/www/html

COPY docker/000-default.conf /etc/apache2/sites-available/000-default.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint
RUN chmod +x /usr/local/bin/entrypoint

COPY . .
COPY --from=vendor  /app/vendor       ./vendor
COPY --from=assets  /app/public/build ./public/build

# Completes the autoloader now that the application files stage 1 lacked are
# present. composer's own post-autoload-dump hook runs package:discover, so
# that is covered here and does not need a second invocation.
#
# The composer binary is borrowed from the vendor stage and removed again in the
# same layer: it is needed for this one command and has no business remaining in
# a production image.
COPY --from=vendor /usr/bin/composer /usr/local/bin/composer
RUN composer dump-autoload --no-dev --optimize --no-interaction \
    && rm -f /usr/local/bin/composer

# Recreated explicitly rather than relying on COPY. Laravel requires these
# directories to exist before it can boot, but each one holds nothing but a
# .gitignore placeholder, and .dockerignore excludes their contents — Docker
# does not create a directory that ends up empty, so without this the runtime
# would fail on the first session write or view compile.
RUN mkdir -p \
        storage/app/private \
        storage/app/public \
        storage/framework/cache/data \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

# Apache runs as www-data and Laravel writes to exactly these two trees.
RUN chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R ug+rwX storage bootstrap/cache

# Railway routes to the port named by $PORT, which is assigned per deployment
# and is not known now. The entrypoint binds it at start; 8080 is only the
# local default.
ENV PORT=8080
EXPOSE 8080

ENTRYPOINT ["entrypoint"]
CMD ["apache2-foreground"]
