#!/bin/bash

# Script para copiar los archivos actualizados al contenedor de Coolify

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Copiando archivos actualizados al contenedor de Coolify${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""

# Detectar directorio de Coolify
if [ -d "/data/coolify/source" ]; then
    COOLIFY_DIR="/data/coolify/source"
elif [ -d "/var/www/html" ]; then
    COOLIFY_DIR="/var/www/html"
else
    echo -e "${RED}❌ No se encontró el directorio de Coolify${NC}"
    exit 1
fi

echo -e "${YELLOW}📁 Directorio de Coolify: $COOLIFY_DIR${NC}"
echo ""

# Detectar contenedor
COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)

if [ -z "$COOLIFY_CONTAINER" ]; then
    echo -e "${RED}❌ Contenedor 'coolify' no encontrado${NC}"
    exit 1
fi

echo -e "${YELLOW}🐳 Contenedor encontrado: $COOLIFY_CONTAINER${NC}"
echo ""

# Crear directorios necesarios si no existen
echo -e "${BLUE}Creando directorios necesarios...${NC}"
docker exec "$COOLIFY_CONTAINER" mkdir -p /var/www/html/app/Livewire/Project/Shared || true
docker exec "$COOLIFY_CONTAINER" mkdir -p /var/www/html/resources/views/livewire/project/shared || true
docker exec "$COOLIFY_CONTAINER" chown -R www-data:www-data /var/www/html/app/Livewire || true
docker exec "$COOLIFY_CONTAINER" chown -R www-data:www-data /var/www/html/resources/views || true
echo -e "${GREEN}✓ Directorios creados${NC}"
echo ""

# Lista de archivos a copiar
FILES=(
    "resources/views/livewire/project/service/heading.blade.php"
    "resources/views/livewire/project/database/heading.blade.php"
    "resources/views/livewire/project/application/heading.blade.php"
    "app/Livewire/Project/Shared/FileExplorer.php"
    "resources/views/livewire/project/shared/file-explorer.blade.php"
    "routes/web.php"
)

echo -e "${BLUE}Copiando archivos...${NC}"
echo ""

for file in "${FILES[@]}"; do
    SOURCE_FILE="$COOLIFY_DIR/$file"
    DEST_FILE="/var/www/html/$file"

    if [ -f "$SOURCE_FILE" ]; then
        echo -e "${YELLOW}📄 Copiando: $file${NC}"
        docker cp "$SOURCE_FILE" "$COOLIFY_CONTAINER:$DEST_FILE"

        # Asegurar permisos correctos (usando root para chown)
        docker exec -u root "$COOLIFY_CONTAINER" chown www-data:www-data "$DEST_FILE" || true
        docker exec -u root "$COOLIFY_CONTAINER" chmod 644 "$DEST_FILE" || true

        echo -e "${GREEN}✓ $file copiado${NC}"
    else
        echo -e "${RED}✗ No se encontró: $SOURCE_FILE${NC}"
    fi
done

echo ""
echo -e "${BLUE}Limpiando cachés dentro del contenedor...${NC}"
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan optimize:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan view:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && rm -rf storage/framework/views/*" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan config:cache" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:cache" || true

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Archivos copiados y cachés limpiadas${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}📝 Próximos pasos:${NC}"
echo -e "${YELLOW}   1. Recarga la página en el navegador con Ctrl+F5${NC}"
echo -e "${YELLOW}   2. El botón 'Files' debería aparecer ahora${NC}"
echo ""
