#!/command/with-contenv sh
set -eu

cd /var/www/html

# When the project is bind-mounted, host ownership may be root:root while the
# app runs as www-data (UID 1000). Fix the minimum needed so composer/laravel
# can write, without a recursive chown of the whole tree.
prepare_bind_mount() {
    mkdir -p \
        storage/framework/cache \
        storage/framework/sessions \
        storage/framework/views \
        storage/logs \
        bootstrap/cache

    if [ "$(id -u)" = "0" ]; then
        # Top-level only: allows creating vendor/ when the mount is root-owned
        chown www-data:www-data /var/www/html 2>/dev/null || true
        chown -R www-data:www-data storage bootstrap/cache 2>/dev/null || true
        git config --global --add safe.directory /var/www/html 2>/dev/null || true
    fi
}

prepare_bind_mount

echo "👉 init-setup: composer install..."
# Run as root when needed so a root-owned bind mount cannot block vendor/
# creation, then hand writable paths to www-data for PHP-FPM.
composer install --no-interaction

echo "👉 init-setup: migrate..."
php artisan migrate --step --force

echo "👉 init-setup: artisan dev --init..."
php artisan dev --init

if [ "$(id -u)" = "0" ]; then
    chown -R www-data:www-data vendor storage bootstrap/cache 2>/dev/null || true
fi

echo "👉 init-setup: done"
