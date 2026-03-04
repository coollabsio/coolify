#!/bin/bash

# Script para limpiar completamente las cachés de Coolify y forzar regeneración de vistas

set -e

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Limpiando completamente las cachés de Coolify${NC}"
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

# Paso 1: Detener el contenedor temporalmente
echo -e "${BLUE}Paso 1/7: Deteniendo contenedor...${NC}"
docker stop "$COOLIFY_CONTAINER" || true
sleep 2
echo -e "${GREEN}✓ Contenedor detenido${NC}"
echo ""

# Paso 2: Eliminar vistas compiladas desde el host
echo -e "${BLUE}Paso 2/7: Eliminando vistas compiladas desde el host...${NC}"
if [ -d "$COOLIFY_DIR/storage/framework/views" ]; then
    rm -rf "$COOLIFY_DIR/storage/framework/views"/*
    VIEW_COUNT=$(ls -la "$COOLIFY_DIR/storage/framework/views" 2>/dev/null | wc -l)
    echo -e "${GREEN}✓ Vistas compiladas eliminadas (archivos restantes: $VIEW_COUNT)${NC}"
else
    echo -e "${YELLOW}⚠️  Directorio de vistas no encontrado${NC}"
fi
echo ""

# Paso 3: Eliminar otras cachés desde el host
echo -e "${BLUE}Paso 3/7: Eliminando otras cachés desde el host...${NC}"
rm -rf "$COOLIFY_DIR/bootstrap/cache"/*.php 2>/dev/null || true
rm -rf "$COOLIFY_DIR/storage/framework/cache"/* 2>/dev/null || true
rm -rf "$COOLIFY_DIR/storage/framework/sessions"/* 2>/dev/null || true
echo -e "${GREEN}✓ Otras cachés eliminadas${NC}"
echo ""

# Paso 4: Reiniciar el contenedor
echo -e "${BLUE}Paso 4/7: Reiniciando contenedor...${NC}"
docker start "$COOLIFY_CONTAINER"
sleep 10
echo -e "${GREEN}✓ Contenedor reiniciado${NC}"
echo ""

# Paso 5: Limpiar todas las cachés dentro del contenedor
echo -e "${BLUE}Paso 5/7: Limpiando cachés dentro del contenedor...${NC}"
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan optimize:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan view:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan config:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan cache:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && rm -rf storage/framework/views/*" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && rm -rf bootstrap/cache/*.php" || true
echo -e "${GREEN}✓ Cachés limpiadas dentro del contenedor${NC}"
echo ""

# Paso 6: Verificar rutas
echo -e "${BLUE}Paso 6/7: Verificando rutas registradas...${NC}"
ROUTES=$(docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:list | grep -E '(files|Files)'" || echo "")
if [ -z "$ROUTES" ]; then
    echo -e "${RED}⚠️  No se encontraron rutas de 'files'${NC}"
else
    echo -e "${GREEN}✓ Rutas encontradas:${NC}"
    echo "$ROUTES"
fi
echo ""

# Paso 7: Regenerar cachés
echo -e "${BLUE}Paso 7/7: Regenerando cachés...${NC}"
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan config:cache" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:cache" || true
echo -e "${GREEN}✓ Cachés regeneradas${NC}"
echo ""

# Verificar permisos
echo -e "${BLUE}Verificando permisos...${NC}"
docker exec "$COOLIFY_CONTAINER" chown -R www-data:www-data /var/www/html/storage/framework/views || true
docker exec "$COOLIFY_CONTAINER" chmod -R 775 /var/www/html/storage/framework/views || true
echo -e "${GREEN}✓ Permisos verificados${NC}"
echo ""

echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Limpieza completa finalizada${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}📝 Próximos pasos:${NC}"
echo -e "${YELLOW}   1. Recarga la página en el navegador con Ctrl+F5${NC}"
echo -e "${YELLOW}   2. Si aún no aparece, verifica los logs: docker logs coolify --tail 50${NC}"
echo ""
