# Debug mode
if [ "$APP_DEBUG" = "true" ]; then
    echo "Debug mode is enabled"
    echo "Auditing dependencies..."
    composer audit --no-interaction || echo "⚠️  composer audit reported advisories (continuing)."
    echo "Installing development dependencies..."
    composer install --dev --no-scripts
    echo "Clearing optimized classes..."
    php artisan optimize:clear
fi
