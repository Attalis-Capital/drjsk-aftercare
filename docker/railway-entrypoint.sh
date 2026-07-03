#!/bin/sh
# Railway container entrypoint for drjsk-aftercare staging (mission #1723).
#
# Single-container web tier: php-fpm + nginx. Runs the release steps (migrate +
# demo seed) once, then starts php-fpm and nginx in the foreground.
#
# Secrets are read from Railway-injected environment variables only; nothing is
# echoed. Do not print env values here.
set -e

cd /var/www/html

# Laravel needs an APP_KEY. It must come from a Railway variable; fail loudly if
# missing rather than silently generating an ephemeral key per deploy.
if [ -z "${APP_KEY}" ]; then
    echo "FATAL: APP_KEY is not set (set it as a Railway variable)." >&2
    exit 1
fi

# Substitute the Railway-assigned $PORT into the nginx server block.
: "${PORT:=8080}"
envsubst '${PORT}' < /etc/nginx/http.d/railway-nginx.conf.template > /etc/nginx/http.d/default.conf

# Cache framework config/routes/views for production performance.
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Release steps: schema + demo data. --force is required in non-interactive
# (non-local) environments. Seeding is demo-only (DemoScenarioSeeder); this
# instance must never hold real patient data.
php artisan migrate --force
php artisan db:seed --force

# Storage symlink for public file access (idempotent).
php artisan storage:link || true

# Start php-fpm in the background, nginx in the foreground (PID 1 semantics).
php-fpm -D
exec nginx -g 'daemon off;'
