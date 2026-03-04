#!/bin/bash

# Script para verificar qué archivos hay realmente dentro del contenedor de Coolify

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Verificando archivos dentro del contenedor de Coolify${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""

# Detectar contenedor
COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)

if [ -z "$COOLIFY_CONTAINER" ]; then
    echo -e "${RED}❌ Contenedor 'coolify' no encontrado${NC}"
    exit 1
fi

echo -e "${YELLOW}🐳 Contenedor encontrado: $COOLIFY_CONTAINER${NC}"
echo ""

# Verificar el archivo heading.blade.php dentro del contenedor
echo -e "${BLUE}1. Verificando archivo heading.blade.php dentro del contenedor...${NC}"
echo ""
docker exec "$COOLIFY_CONTAINER" cat /var/www/html/resources/views/livewire/project/service/heading.blade.php | grep -A 8 "Terminal" || echo -e "${RED}No se encontró 'Terminal' en el archivo${NC}"
echo ""

# Verificar si existe el archivo FileExplorer.php
echo -e "${BLUE}2. Verificando si existe FileExplorer.php...${NC}"
docker exec "$COOLIFY_CONTAINER" test -f /var/www/html/app/Livewire/Project/Shared/FileExplorer.php && echo -e "${GREEN}✓ FileExplorer.php existe${NC}" || echo -e "${RED}✗ FileExplorer.php NO existe${NC}"
echo ""

# Verificar las rutas registradas
echo -e "${BLUE}3. Verificando rutas registradas...${NC}"
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:list | grep -E '(files|Files)'" || echo -e "${YELLOW}No se encontraron rutas de 'files'${NC}"
echo ""

# Verificar la imagen de Docker que está usando
echo -e "${BLUE}4. Verificando imagen de Docker...${NC}"
docker inspect "$COOLIFY_CONTAINER" | grep -i "image" | head -3
echo ""

# Verificar volúmenes montados
echo -e "${BLUE}5. Verificando volúmenes montados...${NC}"
docker inspect "$COOLIFY_CONTAINER" | grep -A 10 "Mounts" | head -15
echo ""

# Comparar archivo del host vs contenedor
echo -e "${BLUE}6. Comparando archivo del host vs contenedor...${NC}"
if [ -f "/data/coolify/source/resources/views/livewire/project/service/heading.blade.php" ]; then
    echo -e "${YELLOW}Archivo en el host:${NC}"
    grep -A 8 "Terminal" /data/coolify/source/resources/views/livewire/project/service/heading.blade.php | head -10
    echo ""
    echo -e "${YELLOW}Archivo en el contenedor:${NC}"
    docker exec "$COOLIFY_CONTAINER" cat /var/www/html/resources/views/livewire/project/service/heading.blade.php | grep -A 8 "Terminal" | head -10
    echo ""

    # Comparar directamente
    HOST_CONTENT=$(grep -A 8 "Terminal" /data/coolify/source/resources/views/livewire/project/service/heading.blade.php | grep -c "Files" || echo "0")
    CONTAINER_CONTENT=$(docker exec "$COOLIFY_CONTAINER" cat /var/www/html/resources/views/livewire/project/service/heading.blade.php | grep -A 8 "Terminal" | grep -c "Files" || echo "0")

    if [ "$HOST_CONTENT" -gt 0 ] && [ "$CONTAINER_CONTENT" -eq 0 ]; then
        echo -e "${RED}❌ PROBLEMA DETECTADO: El archivo en el host tiene 'Files' pero el contenedor NO${NC}"
        echo -e "${YELLOW}El contenedor está usando una imagen de Docker antigua que no tiene los cambios${NC}"
    elif [ "$HOST_CONTENT" -gt 0 ] && [ "$CONTAINER_CONTENT" -gt 0 ]; then
        echo -e "${GREEN}✓ Ambos archivos tienen 'Files'${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  No se encontró el archivo en el host${NC}"
fi

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Verificación completada${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
