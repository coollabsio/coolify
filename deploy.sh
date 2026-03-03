#!/bin/bash

# Script de despliegue para File Explorer
# Este script sube los cambios del explorador de archivos a GitHub

set -e  # Salir si hay algún error

echo "🚀 Iniciando despliegue del File Explorer..."
echo ""

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
NC='\033[0m' # No Color

# Verificar que estamos en un repositorio git
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo -e "${RED}❌ Error: No estás en un repositorio Git${NC}"
    exit 1
fi

# Verificar que hay cambios
if git diff --quiet && git diff --cached --quiet; then
    echo -e "${YELLOW}⚠️  No hay cambios para commitear${NC}"
    exit 0
fi

# Mostrar estado actual
echo -e "${YELLOW}📋 Estado actual del repositorio:${NC}"
git status --short
echo ""

# Archivos modificados/creados relacionados con File Explorer
FILES=(
    "app/Livewire/Project/Shared/FileExplorer.php"
    "resources/views/livewire/project/shared/file-explorer.blade.php"
    "routes/web.php"
    "resources/views/livewire/project/application/heading.blade.php"
    "resources/views/livewire/project/database/heading.blade.php"
    "resources/views/livewire/project/service/heading.blade.php"
    "tests/Feature/FileExplorerTest.php"
)

# Verificar que los archivos existen
echo -e "${YELLOW}🔍 Verificando archivos...${NC}"
for file in "${FILES[@]}"; do
    if [ -f "$file" ]; then
        echo -e "  ${GREEN}✓${NC} $file"
    else
        echo -e "  ${RED}✗${NC} $file (no encontrado)"
    fi
done
echo ""

# Agregar archivos
echo -e "${YELLOW}📦 Agregando archivos al staging...${NC}"
git add "${FILES[@]}"
echo -e "${GREEN}✓ Archivos agregados${NC}"
echo ""

# Crear commit
COMMIT_MESSAGE="feat: Add File Explorer feature for containers

- Add FileExplorer Livewire component with full file management
- Support for listing, viewing, editing text files
- Upload files to containers
- Create folders
- Compress/decompress files (zip, tar, tar.gz, tar.bz2, tar.xz, gz, bz2, xz)
- Move and delete files
- Download files from containers
- Add navigation links in application, database, and service headings
- Add download route with secure token-based access
- Add comprehensive tests
- Support multiple compression formats with automatic fallback"

echo -e "${YELLOW}💾 Creando commit...${NC}"
git commit -m "$COMMIT_MESSAGE"
echo -e "${GREEN}✓ Commit creado${NC}"
echo ""

# Mostrar información del commit
echo -e "${YELLOW}📝 Último commit:${NC}"
git log -1 --stat
echo ""

# Preguntar si hacer push
read -p "¿Deseas hacer push a GitHub? (y/n): " -n 1 -r
echo ""
if [[ $REPLY =~ ^[Yy]$ ]]; then
    echo -e "${YELLOW}📤 Haciendo push a GitHub...${NC}"
    
    # Obtener la rama actual
    BRANCH=$(git branch --show-current)
    echo -e "Rama actual: ${GREEN}$BRANCH${NC}"
    
    # Hacer push
    if git push origin "$BRANCH"; then
        echo ""
        echo -e "${GREEN}✅ ¡Despliegue completado exitosamente!${NC}"
        echo ""
        echo -e "Los cambios han sido subidos a: ${GREEN}origin/$BRANCH${NC}"
    else
        echo ""
        echo -e "${RED}❌ Error al hacer push${NC}"
        echo "Verifica tu conexión y permisos de GitHub"
        exit 1
    fi
else
    echo -e "${YELLOW}⚠️  Push cancelado. Los cambios están commiteados localmente.${NC}"
    echo "Puedes hacer push manualmente con: git push"
fi

echo ""
echo -e "${GREEN}✨ Proceso completado${NC}"
