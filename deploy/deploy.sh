#!/usr/bin/env bash
#
# Deploy the current main branch on a single server. Run it from the app directory as
# the app user. A failing step stops the script and leaves the app in maintenance
# mode, so a half-done deploy (new code, old schema) never serves requests.
set -euo pipefail

cd "$(dirname "$0")/.."

php artisan down --retry=15

git pull --ff-only
composer install --no-dev --prefer-dist --no-interaction --optimize-autoloader
npm ci
npm run build

php artisan migrate --force

# Cache config, routes, events and views. Code must read settings with config(),
# never env(), once the config is cached.
php artisan optimize

# Workers are long-running processes that keep the old code in memory. This tells
# each one to exit after its current job; Supervisor starts it again with the new code.
php artisan queue:restart
php artisan reverb:restart || true

php artisan up
