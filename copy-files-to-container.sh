#!/bin/bash

# Script genérico para copiar archivos modificados al contenedor de Coolify
# Uso: ./copy-files-to-container.sh [archivo1] [archivo2] ...
# Si no se pasan argumentos, detecta archivos modificados con git

# set -e  # Comentado para permitir manejo de errores manual

# Colores para output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Copiando archivos al contenedor de Coolify${NC}"
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

# Determinar qué archivos copiar
FILES=()

# Verificar si se pasa --all
if [ "$1" == "--all" ]; then
    echo -e "${BLUE}Modo: Copiar todos los archivos de directorios clave${NC}"
    KEY_DIRS=(
        "app"
        "resources/views"
        "routes"
        "tests"
        "config"
    )

    for dir in "${KEY_DIRS[@]}"; do
        if [ -d "$COOLIFY_DIR/$dir" ]; then
            echo -e "${YELLOW}Escaneando: $dir${NC}"
            while IFS= read -r file; do
                RELATIVE_PATH="${file#$COOLIFY_DIR/}"
                FILES+=("$RELATIVE_PATH")
            done < <(find "$COOLIFY_DIR/$dir" -type f \( -name "*.php" -o -name "*.blade.php" -o -name "*.js" -o -name "*.vue" -o -name "*.ts" -o -name "*.tsx" -o -name "*.jsx" -o -name "*.json" -o -name "*.yaml" -o -name "*.yml" \) 2>/dev/null)
        fi
    done
elif [ $# -gt 0 ]; then
    # Si se pasan argumentos, usar esos archivos
    echo -e "${BLUE}Modo: Archivos específicos pasados como argumentos${NC}"
    for arg in "$@"; do
        # Convertir rutas relativas a absolutas si es necesario
        if [[ "$arg" == /* ]]; then
            FILES+=("$arg")
        else
            FILES+=("$arg")
        fi
    done
else
    # Detectar archivos modificados usando git o por fecha
    echo -e "${BLUE}Modo: Detección automática de archivos modificados${NC}"
    cd "$COOLIFY_DIR"

    KEY_DIRS=(
        "app"
        "resources/views"
        "routes"
        "tests"
        "config"
    )

    if git rev-parse --git-dir > /dev/null 2>&1; then
        echo -e "${YELLOW}Usando git para detectar archivos modificados...${NC}"

        # Archivos modificados en el working directory (no staged)
        MODIFIED_FILES=$(git diff --name-only --diff-filter=ACMR 2>/dev/null || echo "")
        if [ ! -z "$MODIFIED_FILES" ]; then
            echo -e "${BLUE}   Archivos modificados (working directory): $(echo "$MODIFIED_FILES" | wc -l)${NC}"
        fi

        # Archivos staged
        STAGED_FILES=$(git diff --cached --name-only --diff-filter=ACMR 2>/dev/null || echo "")
        if [ ! -z "$STAGED_FILES" ]; then
            echo -e "${BLUE}   Archivos staged: $(echo "$STAGED_FILES" | wc -l)${NC}"
        fi

        # Archivos nuevos (untracked pero relevantes)
        UNTRACKED_FILES=$(git ls-files --others --exclude-standard 2>/dev/null | grep -E '\.(php|blade\.php|js|vue|ts|tsx|jsx|css|scss|json|yaml|yml)$' || echo "")
        if [ ! -z "$UNTRACKED_FILES" ]; then
            echo -e "${BLUE}   Archivos nuevos (untracked): $(echo "$UNTRACKED_FILES" | wc -l)${NC}"
        fi

        # Combinar y filtrar solo archivos relevantes
        ALL_FILES=$(echo -e "$MODIFIED_FILES\n$STAGED_FILES\n$UNTRACKED_FILES" | sort -u | grep -v '^$')

        if [ ! -z "$ALL_FILES" ]; then
            echo -e "${GREEN}   Procesando archivos del working directory...${NC}"
            # Filtrar solo archivos relevantes (PHP, Blade, JS, etc.)
            while IFS= read -r file; do
                if [[ "$file" =~ \.(php|blade\.php|js|vue|ts|tsx|jsx|css|scss|json|yaml|yml)$ ]] || [[ "$file" =~ ^(routes|config|app|resources|tests)/ ]]; then
                    FILES+=("$file")
                fi
            done <<< "$ALL_FILES"
            echo -e "${GREEN}   ✓ Se encontraron ${#FILES[@]} archivo(s) relevante(s)${NC}"
        else
            echo -e "${YELLOW}   No se encontraron archivos modificados en el working directory${NC}"
        fi
    else
        echo -e "${YELLOW}⚠️  No se encontró repositorio git${NC}"
    fi

    # Si no hay cambios en el working directory, comparar con el último commit
    if [ ${#FILES[@]} -eq 0 ]; then
        if git rev-parse --git-dir > /dev/null 2>&1; then
            echo -e "${YELLOW}⚠️  No se encontraron archivos modificados en el working directory${NC}"
            echo -e "${YELLOW}   Comparando con el último commit (HEAD)...${NC}"

            # Obtener el hash del último commit
            LAST_COMMIT=$(git rev-parse HEAD 2>/dev/null || echo "")

            if [ ! -z "$LAST_COMMIT" ]; then
                echo -e "${BLUE}   Último commit: ${LAST_COMMIT:0:8}...${NC}"

                # Archivos modificados en el último commit comparados con HEAD~1
                # Si es el primer commit, comparar con un árbol vacío
                if git rev-parse HEAD~1 > /dev/null 2>&1; then
                    COMMIT_FILES=$(git diff --name-only --diff-filter=ACMR HEAD~1 HEAD 2>/dev/null || echo "")
                else
                    # Primer commit: incluir todos los archivos del commit
                    COMMIT_FILES=$(git diff-tree --no-commit-id --name-only --diff-filter=ACMR -r HEAD 2>/dev/null || echo "")
                fi

                if [ ! -z "$COMMIT_FILES" ]; then
                    COMMIT_COUNT=$(echo "$COMMIT_FILES" | wc -l)
                    echo -e "${GREEN}   Encontrados $COMMIT_COUNT archivo(s) en el último commit${NC}"
                    # Filtrar solo archivos relevantes
                    while IFS= read -r file; do
                        if [[ "$file" =~ \.(php|blade\.php|js|vue|ts|tsx|jsx|css|scss|json|yaml|yml)$ ]] || [[ "$file" =~ ^(routes|config|app|resources|tests)/ ]]; then
                            # Verificar que el archivo existe en el working directory
                            if [ -f "$COOLIFY_DIR/$file" ]; then
                                FILES+=("$file")
                            else
                                echo -e "${YELLOW}     ⚠️  Archivo no existe en working directory: $file${NC}"
                            fi
                        fi
                    done <<< "$COMMIT_FILES"

                    if [ ${#FILES[@]} -eq 0 ]; then
                        echo -e "${YELLOW}   No se encontraron archivos relevantes después del filtrado${NC}"
                    else
                        echo -e "${GREEN}   ✓ Se encontraron ${#FILES[@]} archivo(s) relevante(s) del último commit${NC}"
                    fi
                else
                    echo -e "${YELLOW}   No se encontraron archivos en el último commit${NC}"
                fi
            else
                echo -e "${YELLOW}   No se pudo obtener el último commit${NC}"
            fi
        fi
    fi

    # Si aún no hay cambios, buscar archivos modificados recientemente
    if [ ${#FILES[@]} -eq 0 ]; then
        if git rev-parse --git-dir > /dev/null 2>&1; then
            echo -e "${YELLOW}⚠️  No se encontraron archivos modificados con git${NC}"
        else
            echo -e "${YELLOW}⚠️  No se encontró repositorio git${NC}"
        fi
        echo -e "${YELLOW}   Buscando archivos modificados recientemente (últimos 7 días)...${NC}"

        # Buscar archivos modificados recientemente
        for dir in "${KEY_DIRS[@]}"; do
            if [ -d "$COOLIFY_DIR/$dir" ]; then
                while IFS= read -r file; do
                    RELATIVE_PATH="${file#$COOLIFY_DIR/}"
                    FILES+=("$RELATIVE_PATH")
                done < <(find "$COOLIFY_DIR/$dir" -type f \( -name "*.php" -o -name "*.blade.php" -o -name "*.js" -o -name "*.vue" -o -name "*.ts" -o -name "*.tsx" -o -name "*.jsx" -o -name "*.json" -o -name "*.yaml" -o -name "*.yml" \) -mtime -7 2>/dev/null)
            fi
        done
    fi
fi

# Eliminar duplicados y vacíos
FILES=($(printf "%s\n" "${FILES[@]}" | grep -v '^$' | sort -u))

if [ ${#FILES[@]} -eq 0 ]; then
    echo -e "${YELLOW}⚠️  No se encontraron archivos para copiar${NC}"
    echo ""
    echo -e "${BLUE}Posibles razones:${NC}"
    echo -e "  • Todos los cambios ya están commiteados en git"
    echo -e "  • Los archivos no se modificaron en los últimos 7 días"
    echo -e "  • Los archivos modificados están fuera de los directorios clave"
    echo ""
    echo -e "${BLUE}Opciones disponibles:${NC}"
    echo -e "${YELLOW}   1. Copiar archivos específicos:${NC}"
    echo -e "      $0 app/Models/User.php routes/web.php"
    echo ""
    echo -e "${YELLOW}   2. Copiar todos los archivos de directorios clave:${NC}"
    echo -e "      $0 --all"
    echo ""
    echo -e "${YELLOW}   3. Ver archivos modificados recientemente:${NC}"
    RECENT_COUNT=$(find "$COOLIFY_DIR/app" "$COOLIFY_DIR/resources/views" "$COOLIFY_DIR/routes" -type f \( -name "*.php" -o -name "*.blade.php" \) -mtime -30 2>/dev/null | wc -l)
    if [ "$RECENT_COUNT" -gt 0 ]; then
        echo -e "${BLUE}   Encontrados $RECENT_COUNT archivos modificados en los últimos 30 días${NC}"
        echo -e "${BLUE}   Ejecuta con --all para copiarlos todos${NC}"
        echo ""
        echo -e "${BLUE}   Algunos ejemplos:${NC}"
        find "$COOLIFY_DIR/app" "$COOLIFY_DIR/resources/views" "$COOLIFY_DIR/routes" -type f \( -name "*.php" -o -name "*.blade.php" \) -mtime -30 2>/dev/null | head -5 | sed "s|$COOLIFY_DIR/||" | sed 's/^/      - /'
    else
        echo -e "${YELLOW}   No se encontraron archivos modificados recientemente${NC}"
    fi
    exit 0
fi

echo -e "${GREEN}✓ Se encontraron ${#FILES[@]} archivo(s) para copiar${NC}"
if [ ${#FILES[@]} -le 10 ]; then
    echo -e "${BLUE}Archivos a copiar:${NC}"
    for file in "${FILES[@]}"; do
        echo -e "  - $file"
    done
elif [ ${#FILES[@]} -le 50 ]; then
    echo -e "${BLUE}Primeros 10 archivos a copiar:${NC}"
    for file in "${FILES[@]:0:10}"; do
        echo -e "  - $file"
    done
    echo -e "${YELLOW}  ... y ${#FILES[@]} más${NC}"
fi
echo ""

# Crear directorios necesarios si no existen
echo -e "${BLUE}Creando directorios necesarios...${NC}"
for file in "${FILES[@]}"; do
    DEST_DIR="/var/www/html/$(dirname "$file")"
    docker exec "$COOLIFY_CONTAINER" mkdir -p "$DEST_DIR" 2>/dev/null || true
done
docker exec "$COOLIFY_CONTAINER" chown -R www-data:www-data /var/www/html/app /var/www/html/resources /var/www/html/routes /var/www/html/tests /var/www/html/config 2>/dev/null || true
echo -e "${GREEN}✓ Directorios preparados${NC}"
echo ""

echo -e "${BLUE}Copiando archivos...${NC}"
echo ""

# Contador de archivos copiados
COPIED_COUNT=0
FAILED_COUNT=0
SKIPPED_COUNT=0

for file in "${FILES[@]}"; do
    # Normalizar ruta (eliminar ./ al inicio si existe)
    file="${file#./}"
    SOURCE_FILE="$COOLIFY_DIR/$file"
    DEST_FILE="/var/www/html/$file"

    # Crear directorio de destino si no existe
    DEST_DIR=$(dirname "$DEST_FILE")
    docker exec "$COOLIFY_CONTAINER" mkdir -p "$DEST_DIR" 2>/dev/null || true

    if [ -f "$SOURCE_FILE" ]; then
        echo -e "${YELLOW}📄 Copiando: $file${NC}"
        if docker cp "$SOURCE_FILE" "$COOLIFY_CONTAINER:$DEST_FILE" 2>/dev/null; then
            # Asegurar permisos correctos (usando root para chown)
            docker exec -u root "$COOLIFY_CONTAINER" chown www-data:www-data "$DEST_FILE" 2>/dev/null || true
            docker exec -u root "$COOLIFY_CONTAINER" chmod 644 "$DEST_FILE" 2>/dev/null || true

            echo -e "${GREEN}✓ $file copiado${NC}"
            ((COPIED_COUNT++))
        else
            echo -e "${RED}✗ Error al copiar: $file${NC}"
            ((FAILED_COUNT++))
        fi
    else
        echo -e "${YELLOW}⚠️  No se encontró (saltando): $SOURCE_FILE${NC}"
        ((SKIPPED_COUNT++))
    fi
done

echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Resumen:${NC}"
echo -e "${GREEN}  ✓ Archivos copiados: $COPIED_COUNT${NC}"
if [ $FAILED_COUNT -gt 0 ]; then
    echo -e "${RED}  ✗ Archivos fallidos: $FAILED_COUNT${NC}"
fi
if [ $SKIPPED_COUNT -gt 0 ]; then
    echo -e "${YELLOW}  ⚠️  Archivos saltados: $SKIPPED_COUNT${NC}"
fi
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo ""

echo ""
echo -e "${BLUE}Limpiando cachés dentro del contenedor...${NC}"
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan optimize:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan view:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan config:clear" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && rm -rf storage/framework/views/*" || true
docker exec -u root "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && rm -rf bootstrap/cache/*.php" || true

echo -e "${BLUE}Regenerando cachés...${NC}"
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan config:cache" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:cache" || true
docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan view:cache" || true

echo -e "${BLUE}Verificando rutas...${NC}"
ROUTES=$(docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:list | grep -E '(files|Files)'" || echo "")
if [ ! -z "$ROUTES" ]; then
    echo -e "${GREEN}✓ Rutas registradas correctamente:${NC}"
    echo "$ROUTES" | head -5
else
    echo -e "${YELLOW}⚠️  No se encontraron rutas de 'files'${NC}"
fi

echo ""
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo -e "${GREEN}✅ Archivos copiados y cachés limpiadas${NC}"
echo -e "${GREEN}═══════════════════════════════════════════════════════${NC}"
echo ""
echo -e "${YELLOW}📝 Próximos pasos:${NC}"
echo -e "${YELLOW}   1. Recarga la página en el navegador con Ctrl+F5${NC}"
echo -e "${YELLOW}   2. El botón 'Files' debería aparecer ahora${NC}"
echo ""
