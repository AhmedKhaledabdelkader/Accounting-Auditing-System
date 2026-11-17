#!/usr/bin/env bash
# Browser tests (PR 13): serves the app on port 8010 with its own SQLite file, runs `php artisan dusk`, stops the server.
# Extra arguments go to Dusk (e.g. --filter=...). Needs Chrome and a matching ChromeDriver (`php artisan dusk:chrome-driver`);
# set DUSK_CHROME_BINARY when Chrome isn't installed system-wide (docs/08).
set -euo pipefail
cd "$(dirname "$0")/.."

export APP_ENV=testing APP_URL=http://127.0.0.1:8010 DB_CONNECTION=sqlite DB_DATABASE="$PWD/database/dusk.sqlite" DB_URL=
export PRIOR_PDF_CACHE="$(mktemp -d)"
export CACHE_STORE=array SESSION_DRIVER=file QUEUE_CONNECTION=sync CLASSIFIER_CACHE=false CLASSIFIER_LOG_CHANNEL=null
: > "$DB_DATABASE"
php artisan migrate --force --quiet

# Laravel's router for the built-in server runs from public/ (as `php artisan serve` does).
(cd public && exec php -S 127.0.0.1:8010 ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php) > storage/logs/dusk-server.log 2>&1 &
SERVER=$!
trap 'kill $SERVER 2>/dev/null || true' EXIT
for _ in $(seq 1 50); do curl -s -o /dev/null http://127.0.0.1:8010/up && break; sleep 0.1; done

php artisan dusk "$@"
