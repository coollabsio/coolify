# Debug mode
if [ "$DEBUG" = "true" ]; then
    echo "Debug mode is enabled"
    echo "Installing development dependencies..."
    composer install --dev --no-scripts
fi
