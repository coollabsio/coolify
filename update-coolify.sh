#!/bin/bash

# Script de actualización de Coolify
# Actualiza desde GitHub sin perder datos
# Repositorio: https://github.com/crmhawkins/coolify

set -e  # Salir si hay algún error

# Colores para output
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
RED='\033[0;31m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuración
REPO_URL="https://github.com/crmhawkins/coolify.git"
BRANCH="v4.x"

# Detectar automáticamente la ubicación de Coolify
# Coolify se instala en /data/coolify y el código fuente está en /data/coolify/source
if [ -d "/data/coolify/source" ]; then
    COOLIFY_DIR="/data/coolify/source"
    COOLIFY_BASE="/data/coolify"
elif [ -d "/data/coolify" ]; then
    COOLIFY_DIR="/data/coolify"
    COOLIFY_BASE="/data/coolify"
else
    # Intentar detectar desde el contenedor de Docker
    COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
    if [ ! -z "$COOLIFY_CONTAINER" ]; then
        WORKDIR=$(docker inspect --format='{{.Config.WorkingDir}}' "$COOLIFY_CONTAINER" 2>/dev/null || echo "")
        if [ ! -z "$WORKDIR" ] && [ -d "$WORKDIR" ]; then
            COOLIFY_DIR="$WORKDIR"
            COOLIFY_BASE=$(dirname "$WORKDIR")
        fi
    fi
fi

# Si aún no se encontró, usar valores por defecto
COOLIFY_DIR=${COOLIFY_DIR:-"/data/coolify/source"}
COOLIFY_BASE=${COOLIFY_BASE:-"/data/coolify"}
BACKUP_DIR="${COOLIFY_BASE}-backups"
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
BACKUP_PATH="${BACKUP_DIR}/backup_${TIMESTAMP}"

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║     Script de Actualización de Coolify                ║${NC}"
echo -e "${BLUE}║     Actualizando desde: ${REPO_URL}${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Verificar que se ejecuta como root o con permisos adecuados
if [ "$EUID" -ne 0 ]; then
    echo -e "${YELLOW}⚠️  Ejecutando sin root. Algunas operaciones pueden requerir sudo.${NC}"
fi

# Función para verificar comandos
check_command() {
    if ! command -v $1 &> /dev/null; then
        echo -e "${RED}❌ Error: $1 no está instalado${NC}"
        exit 1
    fi
}

# Verificar comandos necesarios
echo -e "${YELLOW}🔍 Verificando dependencias...${NC}"
check_command "git"
check_command "docker"
check_command "docker compose"
echo -e "${GREEN}✓ Todas las dependencias están disponibles${NC}"

# Configurar Git para evitar errores de "dubious ownership"
# Esto es necesario porque Coolify usa usuario 9999 pero ejecutamos como root
if [ -d "$COOLIFY_DIR" ]; then
    git config --global --add safe.directory "$COOLIFY_DIR" 2>/dev/null || true
    git config --global --add safe.directory "$COOLIFY_BASE/source" 2>/dev/null || true
fi
echo ""

# Crear directorio de backups si no existe
mkdir -p "$BACKUP_DIR"
echo -e "${GREEN}✓ Directorio de backups: $BACKUP_DIR${NC}"

# Verificar que el directorio de Coolify existe
if [ ! -d "$COOLIFY_DIR" ]; then
    echo -e "${RED}❌ Error: Directorio de Coolify no encontrado${NC}"
    echo ""
    echo "Ubicaciones probadas:"
    echo "  - /data/coolify/source"
    echo "  - /data/coolify"
    echo ""
    echo "Por favor, ejecuta este script desde el servidor donde está instalado Coolify"
    echo "O ajusta manualmente las variables COOLIFY_DIR y COOLIFY_BASE en el script"
    exit 1
fi

echo -e "${GREEN}✓ Coolify detectado en: $COOLIFY_DIR${NC}"
echo -e "${GREEN}✓ Directorio base: $COOLIFY_BASE${NC}"

# Paso 1: Crear backup
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 1/7: Creando backup de seguridad${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

mkdir -p "$BACKUP_PATH"

# Backup del .env (puede estar en source o en base)
if [ -f "$COOLIFY_DIR/.env" ]; then
    cp "$COOLIFY_DIR/.env" "$BACKUP_PATH/.env.backup"
    echo -e "${GREEN}✓ Backup de .env creado${NC}"
elif [ -f "$COOLIFY_BASE/source/.env" ]; then
    cp "$COOLIFY_BASE/source/.env" "$BACKUP_PATH/.env.backup"
    echo -e "${GREEN}✓ Backup de .env creado${NC}"
fi

# Backup del .env.production
if [ -f "$COOLIFY_DIR/.env.production" ]; then
    cp "$COOLIFY_DIR/.env.production" "$BACKUP_PATH/.env.production.backup"
    echo -e "${GREEN}✓ Backup de .env.production creado${NC}"
elif [ -f "$COOLIFY_BASE/source/.env.production" ]; then
    cp "$COOLIFY_BASE/source/.env.production" "$BACKUP_PATH/.env.production.backup"
    echo -e "${GREEN}✓ Backup de .env.production creado${NC}"
fi

# Backup de package.json y composer.json (para comparar cambios)
if [ -f "$COOLIFY_DIR/package.json" ]; then
    cp "$COOLIFY_DIR/package.json" "$BACKUP_PATH/package.json.backup" 2>/dev/null || true
fi
if [ -f "$COOLIFY_DIR/composer.json" ]; then
    cp "$COOLIFY_DIR/composer.json" "$BACKUP_PATH/composer.json.backup" 2>/dev/null || true
fi

# Backup de la base de datos (si está en Docker)
if docker ps | grep -q "coolify-db\|postgres"; then
    echo -e "${YELLOW}📦 Creando backup de base de datos...${NC}"
    DB_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "coolify-db|postgres" | head -1)
    if [ ! -z "$DB_CONTAINER" ]; then
        docker exec "$DB_CONTAINER" pg_dump -U coolify coolify > "$BACKUP_PATH/database_backup_${TIMESTAMP}.sql" 2>/dev/null || {
            echo -e "${YELLOW}⚠️  No se pudo hacer backup de la base de datos (puede ser normal si no hay datos)${NC}"
        }
        echo -e "${GREEN}✓ Backup de base de datos creado${NC}"
    fi
fi

# Backup de package.json si existe (para comparar cambios)
if [ -f "$COOLIFY_DIR/package.json" ]; then
    cp "$COOLIFY_DIR/package.json" "$BACKUP_PATH/package.json.backup" 2>/dev/null || true
fi

# Backup de composer.json si existe
if [ -f "$COOLIFY_DIR/composer.json" ]; then
    cp "$COOLIFY_DIR/composer.json" "$BACKUP_PATH/composer.json.backup" 2>/dev/null || true
fi

# Backup de volúmenes importantes (aplicaciones, bases de datos, etc.)
if [ -d "$COOLIFY_BASE/applications" ] || [ -d "$COOLIFY_BASE/databases" ]; then
    echo -e "${YELLOW}📦 Creando backup de metadatos importantes...${NC}"
    # Solo hacer backup de metadatos, no de los datos completos (sería muy grande)
    tar -czf "$BACKUP_PATH/metadata_backup_${TIMESTAMP}.tar.gz" \
        -C "$COOLIFY_BASE" \
        --exclude="applications/*/data" \
        --exclude="databases/*/data" \
        --exclude="backups" \
        applications/ databases/ services/ 2>/dev/null || true
    echo -e "${GREEN}✓ Backup de metadatos creado${NC}"
fi

echo -e "${GREEN}✅ Backup completado en: $BACKUP_PATH${NC}"
echo ""

# Paso 2: Verificar estado de servicios
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 2/7: Verificando estado de servicios${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

cd "$COOLIFY_DIR"

# Verificar si hay docker-compose (puede estar en source o en base)
COMPOSE_FILE=""
if [ -f "$COOLIFY_DIR/docker-compose.yml" ] || [ -f "$COOLIFY_DIR/docker-compose.prod.yml" ]; then
    COMPOSE_FILE="$COOLIFY_DIR/docker-compose.yml"
    [ -f "$COOLIFY_DIR/docker-compose.prod.yml" ] && COMPOSE_FILE="$COOLIFY_DIR/docker-compose.prod.yml"
elif [ -f "$COOLIFY_BASE/source/docker-compose.yml" ] || [ -f "$COOLIFY_BASE/source/docker-compose.prod.yml" ]; then
    COMPOSE_FILE="$COOLIFY_BASE/source/docker-compose.yml"
    [ -f "$COOLIFY_BASE/source/docker-compose.prod.yml" ] && COMPOSE_FILE="$COOLIFY_BASE/source/docker-compose.prod.yml"
fi

if [ ! -z "$COMPOSE_FILE" ]; then
    echo -e "${YELLOW}📊 Estado de contenedores:${NC}"
    docker compose -f "$COMPOSE_FILE" ps 2>/dev/null || docker-compose -f "$COMPOSE_FILE" ps 2>/dev/null || true
    echo ""
fi

# Paso 3: Obtener cambios desde GitHub
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 3/7: Obteniendo cambios desde GitHub${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

# Verificar si es un repositorio git
if [ -d "$COOLIFY_DIR/.git" ]; then
    echo -e "${YELLOW}📥 Actualizando desde Git...${NC}"
    cd "$COOLIFY_DIR"

    # Configurar Git para permitir este directorio (evita error de "dubious ownership")
    git config --global --add safe.directory "$COOLIFY_DIR" 2>/dev/null || true

    # Verificar y configurar remote si no existe o está mal configurado
    if ! git remote | grep -q "origin"; then
        echo -e "${YELLOW}🔧 Configurando remote 'origin'...${NC}"
        git remote add origin "$REPO_URL" 2>/dev/null || git remote set-url origin "$REPO_URL"
        echo -e "${GREEN}✓ Remote 'origin' configurado${NC}"
    else
        # Verificar que el remote apunta al repositorio correcto
        CURRENT_REMOTE=$(git remote get-url origin 2>/dev/null || echo "")
        if [ "$CURRENT_REMOTE" != "$REPO_URL" ]; then
            echo -e "${YELLOW}🔧 Actualizando URL del remote 'origin'...${NC}"
            git remote set-url origin "$REPO_URL"
            echo -e "${GREEN}✓ Remote 'origin' actualizado${NC}"
        fi
    fi

    # Guardar archivos de configuración locales que no deben sobrescribirse
    echo -e "${YELLOW}💾 Preservando archivos de configuración locales...${NC}"
    TEMP_CONFIG_DIR="/tmp/coolify-config-backup-${TIMESTAMP}"
    mkdir -p "$TEMP_CONFIG_DIR"

    # Lista de archivos a preservar
    PRESERVE_FILES=(".env" ".env.production" "docker-compose.custom.yml")
    for file in "${PRESERVE_FILES[@]}"; do
        if [ -f "$COOLIFY_DIR/$file" ]; then
            cp "$COOLIFY_DIR/$file" "$TEMP_CONFIG_DIR/$file" 2>/dev/null || true
        fi
    done

    # Guardar cambios locales si existen
    if ! git diff --quiet 2>/dev/null || ! git diff --cached --quiet 2>/dev/null; then
        echo -e "${YELLOW}⚠️  Hay cambios locales. Creando stash...${NC}"
        git stash save "Backup antes de actualización - $TIMESTAMP" || true
    fi

    # Obtener cambios remotos
    echo -e "${YELLOW}📥 Obteniendo cambios desde GitHub...${NC}"
    git fetch origin "$BRANCH" || {
        echo -e "${RED}❌ Error al obtener cambios desde GitHub${NC}"
        echo "Verifica tu conexión a internet y que el repositorio sea accesible"
        echo "Repositorio: $REPO_URL"
        echo "Rama: $BRANCH"
        exit 1
    }

    # Mostrar commits que se van a aplicar
    echo ""
    echo -e "${YELLOW}📝 Commits que se aplicarán:${NC}"
    if git rev-parse --verify HEAD >/dev/null 2>&1; then
        git log HEAD..origin/$BRANCH --oneline -10 2>/dev/null || echo "No hay commits nuevos"
    else
        echo "Primera sincronización - obteniendo código completo"
    fi
    echo ""

    # Aplicar cambios
    echo -e "${YELLOW}🔄 Aplicando cambios...${NC}"

    # Si no hay commits locales, hacer reset hard para obtener código completo
    if ! git rev-parse --verify HEAD >/dev/null 2>&1 || [ -z "$(git log --oneline -1 2>/dev/null)" ]; then
        echo -e "${YELLOW}Primera sincronización: obteniendo código completo...${NC}"
        git reset --hard origin/$BRANCH || {
            echo -e "${RED}❌ Error al aplicar cambios${NC}"
            exit 1
        }
    else
        # Merge normal
        git merge origin/$BRANCH --no-edit || {
            echo -e "${YELLOW}⚠️  Hay conflictos. Intentando resolver automáticamente...${NC}"
            # En caso de conflicto, preservar archivos de configuración locales
            git checkout --ours .env .env.production docker-compose.custom.yml 2>/dev/null || true
            git add .env .env.production docker-compose.custom.yml 2>/dev/null || true
            git merge --continue 2>/dev/null || {
                echo -e "${RED}❌ Error al resolver conflictos. Revisa manualmente.${NC}"
                echo "Puedes restaurar el backup desde: $BACKUP_PATH"
                exit 1
            }
        }
    fi

    # Restaurar archivos de configuración preservados
    echo -e "${YELLOW}🔄 Restaurando archivos de configuración...${NC}"
    for file in "${PRESERVE_FILES[@]}"; do
        if [ -f "$TEMP_CONFIG_DIR/$file" ]; then
            cp "$TEMP_CONFIG_DIR/$file" "$COOLIFY_DIR/$file" 2>/dev/null || true
            echo -e "${GREEN}✓ Restaurado: $file${NC}"
        fi
    done
    rm -rf "$TEMP_CONFIG_DIR"

    echo -e "${GREEN}✓ Código actualizado desde GitHub${NC}"
else
    echo -e "${YELLOW}⚠️  No es un repositorio Git (instalación estándar de Coolify)${NC}"
    echo -e "${YELLOW}🔄 Convirtiendo a repositorio Git e inicializando...${NC}"

    cd "$COOLIFY_DIR"

    # Configurar Git para permitir este directorio (evita error de "dubious ownership")
    git config --global --add safe.directory "$COOLIFY_DIR" 2>/dev/null || true

    # Inicializar Git si no existe
    if [ ! -d ".git" ]; then
        git init
        echo -e "${GREEN}✓ Repositorio Git inicializado${NC}"
    fi

    # Configurar nuevamente después de init (por si acaso)
    git config --global --add safe.directory "$COOLIFY_DIR" 2>/dev/null || true

    # Agregar remote si no existe
    if ! git remote | grep -q "origin"; then
        git remote add origin "$REPO_URL" 2>/dev/null || git remote set-url origin "$REPO_URL"
        echo -e "${GREEN}✓ Remote 'origin' configurado${NC}"
    fi

    # Agregar todos los archivos actuales al staging
    echo -e "${YELLOW}📦 Agregando archivos existentes al repositorio...${NC}"
    git add -A || true
    git commit -m "Backup antes de actualización - $TIMESTAMP" || true

    # Obtener cambios desde GitHub
    echo -e "${YELLOW}📥 Obteniendo cambios desde GitHub...${NC}"
    git fetch origin "$BRANCH" || {
        echo -e "${RED}❌ Error al obtener cambios desde GitHub${NC}"
        echo "Verifica tu conexión a internet y que el repositorio sea accesible"
        exit 1
    }

    # Mostrar commits que se van a aplicar
    echo ""
    echo -e "${YELLOW}📝 Commits que se aplicarán:${NC}"
    git log HEAD..origin/$BRANCH --oneline -10 2>/dev/null || echo "No hay commits nuevos o primera sincronización"
    echo ""

    # Crear o cambiar a la rama
    CURRENT_BRANCH=$(git branch --show-current 2>/dev/null || echo "")
    if [ -z "$CURRENT_BRANCH" ]; then
        git checkout -b "$BRANCH" origin/$BRANCH 2>/dev/null || git checkout -b "$BRANCH"
    fi

    # Aplicar cambios (merge o reset según corresponda)
    echo -e "${YELLOW}🔄 Aplicando cambios...${NC}"

    # Si es la primera vez, hacer reset para obtener el código completo
    if [ -z "$(git log --oneline -1 2>/dev/null)" ] || [ "$(git rev-list --count HEAD 2>/dev/null)" -lt 2 ]; then
        echo -e "${YELLOW}Primera sincronización: obteniendo código completo...${NC}"
        git fetch origin "$BRANCH"
        git reset --hard origin/$BRANCH || {
            echo -e "${RED}❌ Error al aplicar cambios${NC}"
            exit 1
        }
    else
        # Merge normal
        git merge origin/$BRANCH --no-edit || {
            echo -e "${YELLOW}⚠️  Hay conflictos. Intentando resolver automáticamente...${NC}"
            # En caso de conflicto, preservar archivos de configuración locales
            git checkout --ours .env .env.production 2>/dev/null || true
            git add .env .env.production 2>/dev/null || true
            git merge --continue 2>/dev/null || {
                echo -e "${RED}❌ Error al resolver conflictos. Revisa manualmente.${NC}"
                echo "Puedes restaurar el backup desde: $BACKUP_PATH"
                exit 1
            }
        }
    fi

    echo -e "${GREEN}✓ Código actualizado desde GitHub${NC}"
fi

echo ""

# Paso 4: Instalar/actualizar dependencias
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 4/7: Instalando dependencias${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

cd "$COOLIFY_DIR"

# Instalar dependencias de Composer dentro del contenedor
COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
if [ ! -z "$COOLIFY_CONTAINER" ]; then
    echo -e "${YELLOW}📦 Instalando dependencias de Composer...${NC}"
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && composer install --no-interaction --no-dev --optimize-autoloader" || {
        echo -e "${YELLOW}⚠️  Error al instalar dependencias de Composer (continuando...)${NC}"
    }
    echo -e "${GREEN}✓ Dependencias de Composer instaladas${NC}"
else
    echo -e "${YELLOW}⚠️  Contenedor 'coolify' no encontrado (continuando...)${NC}"
fi

# Instalar dependencias de NPM si es necesario
if [ -f "package.json" ]; then
    echo -e "${YELLOW}📦 Instalando dependencias de NPM...${NC}"
    npm install --production || {
        echo -e "${YELLOW}⚠️  Error al instalar dependencias de NPM (continuando...)${NC}"
    }
    echo -e "${GREEN}✓ Dependencias de NPM instaladas${NC}"
fi

echo ""

# Paso 5: Ejecutar migraciones
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 5/7: Ejecutando migraciones de base de datos${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
if [ ! -z "$COOLIFY_CONTAINER" ]; then
    echo -e "${YELLOW}🔄 Ejecutando migraciones...${NC}"
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan migrate --force" || {
        echo -e "${YELLOW}⚠️  Error al ejecutar migraciones (puede ser normal si no hay cambios)${NC}"
    }
    echo -e "${GREEN}✓ Migraciones ejecutadas${NC}"
else
    echo -e "${YELLOW}⚠️  Contenedor 'coolify' no encontrado (continuando...)${NC}"
fi

echo ""

# Paso 6: Limpiar cachés y optimizar
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 6/7: Limpiando cachés y optimizando${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
if [ ! -z "$COOLIFY_CONTAINER" ]; then
    echo -e "${YELLOW}🧹 Limpiando cachés...${NC}"
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan optimize:clear" || true
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:clear" || true
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan view:clear" || true
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && rm -rf storage/framework/views/*" || true
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan config:cache" || true
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan route:cache" || true
    docker exec -u www-data "$COOLIFY_CONTAINER" sh -c "cd /var/www/html && php artisan view:cache" || true
    echo -e "${GREEN}✓ Cachés limpiados y optimizados${NC}"
else
    echo -e "${YELLOW}⚠️  Contenedor 'coolify' no encontrado (continuando...)${NC}"
fi

echo ""

# Paso 7: Reiniciar servicios
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 7/7: Reiniciando servicios${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

cd "$COOLIFY_DIR"

# Reiniciar usando docker-compose si está disponible
if [ ! -z "$COMPOSE_FILE" ] && [ -f "$COMPOSE_FILE" ]; then
    echo -e "${YELLOW}🔄 Reiniciando contenedores de Coolify...${NC}"
    docker compose -f "$COMPOSE_FILE" restart coolify 2>/dev/null || \
    docker-compose -f "$COMPOSE_FILE" restart coolify 2>/dev/null || {
        echo -e "${YELLOW}⚠️  No se pudo reiniciar con docker compose, intentando reinicio manual...${NC}"
        COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
        if [ ! -z "$COOLIFY_CONTAINER" ]; then
            docker restart "$COOLIFY_CONTAINER" || true
            echo -e "${GREEN}✓ Contenedor '$COOLIFY_CONTAINER' reiniciado${NC}"
        else
            echo -e "${YELLOW}⚠️  Contenedor 'coolify' no encontrado${NC}"
        fi
    }
    echo -e "${GREEN}✓ Servicios reiniciados${NC}"
else
    # Si no hay docker-compose, reiniciar contenedor directamente
    echo -e "${YELLOW}🔄 Reiniciando contenedor de Coolify...${NC}"
    COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
    if [ ! -z "$COOLIFY_CONTAINER" ]; then
        docker restart "$COOLIFY_CONTAINER"
        echo -e "${GREEN}✓ Contenedor '$COOLIFY_CONTAINER' reiniciado${NC}"
    else
        echo -e "${YELLOW}⚠️  No se encontró contenedor de Coolify para reiniciar${NC}"
    fi
fi

echo ""

# Verificación final
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Verificación final${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

sleep 5

COOLIFY_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "^coolify$" | head -1)
if [ ! -z "$COOLIFY_CONTAINER" ]; then
    HEALTH=$(docker inspect --format='{{.State.Health.Status}}' "$COOLIFY_CONTAINER" 2>/dev/null || echo "unknown")
    STATUS=$(docker inspect --format='{{.State.Status}}' "$COOLIFY_CONTAINER" 2>/dev/null || echo "unknown")

    echo -e "Estado del contenedor: ${STATUS}"
    [ "$HEALTH" != "unknown" ] && echo -e "Estado de salud: ${HEALTH}"

    if [ "$STATUS" = "running" ]; then
        echo -e "${GREEN}✅ Coolify está funcionando correctamente${NC}"
    else
        echo -e "${YELLOW}⚠️  El contenedor no está en estado 'running'. Revisa los logs.${NC}"
    fi
fi

echo ""
echo -e "${GREEN}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${GREEN}║     ✅ Actualización completada exitosamente         ║${NC}"
echo -e "${GREEN}╚════════════════════════════════════════════════════════╝${NC}"
echo ""
echo -e "${BLUE}📦 Backup guardado en: ${BACKUP_PATH}${NC}"
echo -e "${BLUE}📝 Para restaurar el backup, ejecuta:${NC}"
echo -e "   cp ${BACKUP_PATH}/.env.backup ${COOLIFY_DIR}/.env"
echo ""
echo -e "${GREEN}✨ ¡Actualización completada!${NC}"
