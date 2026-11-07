#!/bin/sh

# Exit the script as soon as a command fails
set -e

# Create mysql databases if none exists
php artisan mysql:createdb

# Run migrations
php artisan migrate --force

# Run migrations for sandbox too
php artisan sandbox:migrate --force

# Seed database
php artisan fleetbase:seed

# Create permissions, policies, and roles
php artisan fleetbase:create-permissions

# Restart queue
php artisan queue:restart

# Sync scheduler
php artisan schedule-monitor:sync

# Clear cache
php artisan cache:clear
php artisan route:clear

# Optimize
php artisan config:cache
php artisan route:cache

# Check what the web server's workers need at start-up (APP_KEY, APP_URL, booting
# the app as a worker does). A worker that fails at start-up hides its error, so
# this stops the deploy here with a readable message instead.
php artisan pod:check-runtime

# Initialize registry
php artisan registry:init

# Notify open install pages that setup has completed
php artisan fleetbase:notify-installed || true

# Restart octane
# php artisan octane:reload
