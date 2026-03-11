#!/bin/bash

# Script de Exportación Completa de Coolify
# Este script exporta TODO lo necesario para migrar Coolify a un nuevo servidor
# manteniendo configuraciones, cuentas, aplicaciones, bases de datos, etc.

set -e

# Colores
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

# Configuración
TIMESTAMP=$(date +%Y%m%d_%H%M%S)
EXPORT_DIR="/tmp/coolify-export-${TIMESTAMP}"
COOLIFY_BASE="/data/coolify"
COOLIFY_SOURCE="${COOLIFY_BASE}/source"

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Script de Exportación Completa de Coolify          ║${NC}"
echo -e "${BLUE}║   Exportando para migración a nuevo servidor          ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Verificar que estamos en el servidor correcto
if [ ! -d "$COOLIFY_SOURCE" ] && [ ! -d "$COOLIFY_BASE" ]; then
    echo -e "${RED}❌ Error: No se encontró la instalación de Coolify${NC}"
    echo -e "${YELLOW}   Buscando en: $COOLIFY_SOURCE${NC}"
    echo -e "${YELLOW}   Buscando en: $COOLIFY_BASE${NC}"
    exit 1
fi

# Crear directorio de exportación
mkdir -p "$EXPORT_DIR"
echo -e "${GREEN}✓ Directorio de exportación creado: $EXPORT_DIR${NC}"

# Paso 1: Exportar base de datos PostgreSQL
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 1/7: Exportando base de datos PostgreSQL${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

DB_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "coolify-db|postgres" | head -1)
if [ ! -z "$DB_CONTAINER" ]; then
    echo -e "${YELLOW}📦 Contenedor de base de datos encontrado: $DB_CONTAINER${NC}"

    # Obtener credenciales de la base de datos
    DB_USER=$(docker exec "$DB_CONTAINER" env | grep POSTGRES_USER | cut -d '=' -f2 | head -1)
    DB_NAME=$(docker exec "$DB_CONTAINER" env | grep POSTGRES_DB | cut -d '=' -f2 | head -1)

    if [ -z "$DB_USER" ]; then
        DB_USER="coolify"
    fi
    if [ -z "$DB_NAME" ]; then
        DB_NAME="coolify"
    fi

    echo -e "${YELLOW}   Usuario: $DB_USER${NC}"
    echo -e "${YELLOW}   Base de datos: $DB_NAME${NC}"

    # Exportar base de datos completa
    docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" -F c -b -v -f "/tmp/coolify_db_backup.dump" "$DB_NAME" 2>/dev/null || {
        echo -e "${YELLOW}⚠️  Intentando exportación en formato SQL...${NC}"
        docker exec "$DB_CONTAINER" pg_dump -U "$DB_USER" "$DB_NAME" > "$EXPORT_DIR/database_backup.sql" 2>/dev/null || {
            echo -e "${RED}❌ Error al exportar la base de datos${NC}"
            exit 1
        }
        echo -e "${GREEN}✓ Base de datos exportada (SQL): database_backup.sql${NC}"
    }

    # Si se creó el dump, copiarlo fuera del contenedor
    if docker exec "$DB_CONTAINER" test -f "/tmp/coolify_db_backup.dump" 2>/dev/null; then
        docker cp "$DB_CONTAINER:/tmp/coolify_db_backup.dump" "$EXPORT_DIR/database_backup.dump"
        docker exec "$DB_CONTAINER" rm -f "/tmp/coolify_db_backup.dump"
        echo -e "${GREEN}✓ Base de datos exportada (Custom): database_backup.dump${NC}"
    fi
else
    echo -e "${YELLOW}⚠️  No se encontró contenedor de base de datos${NC}"
fi

# Paso 2: Exportar archivos de configuración
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 2/7: Exportando archivos de configuración${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

mkdir -p "$EXPORT_DIR/config"

# Exportar .env
if [ -f "$COOLIFY_SOURCE/.env" ]; then
    cp "$COOLIFY_SOURCE/.env" "$EXPORT_DIR/config/.env"
    echo -e "${GREEN}✓ .env exportado${NC}"
elif [ -f "$COOLIFY_BASE/.env" ]; then
    cp "$COOLIFY_BASE/.env" "$EXPORT_DIR/config/.env"
    echo -e "${GREEN}✓ .env exportado${NC}"
fi

# Exportar .env.production
if [ -f "$COOLIFY_SOURCE/.env.production" ]; then
    cp "$COOLIFY_SOURCE/.env.production" "$EXPORT_DIR/config/.env.production"
    echo -e "${GREEN}✓ .env.production exportado${NC}"
fi

# Exportar docker-compose files
if [ -f "$COOLIFY_SOURCE/docker-compose.yml" ]; then
    cp "$COOLIFY_SOURCE/docker-compose.yml" "$EXPORT_DIR/config/docker-compose.yml"
    echo -e "${GREEN}✓ docker-compose.yml exportado${NC}"
fi

if [ -f "$COOLIFY_SOURCE/docker-compose.prod.yml" ]; then
    cp "$COOLIFY_SOURCE/docker-compose.prod.yml" "$EXPORT_DIR/config/docker-compose.prod.yml"
    echo -e "${GREEN}✓ docker-compose.prod.yml exportado${NC}"
fi

# Paso 3: Exportar volúmenes de datos
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 3/7: Exportando volúmenes de datos${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

mkdir -p "$EXPORT_DIR/data"

# Exportar aplicaciones
if [ -d "$COOLIFY_BASE/applications" ]; then
    echo -e "${YELLOW}📦 Exportando aplicaciones...${NC}"
    tar -czf "$EXPORT_DIR/data/applications.tar.gz" -C "$COOLIFY_BASE" applications/
    echo -e "${GREEN}✓ Aplicaciones exportadas: applications.tar.gz${NC}"
fi

# Exportar bases de datos
if [ -d "$COOLIFY_BASE/databases" ]; then
    echo -e "${YELLOW}📦 Exportando bases de datos...${NC}"
    tar -czf "$EXPORT_DIR/data/databases.tar.gz" -C "$COOLIFY_BASE" databases/
    echo -e "${GREEN}✓ Bases de datos exportadas: databases.tar.gz${NC}"
fi

# Exportar servicios
if [ -d "$COOLIFY_BASE/services" ]; then
    echo -e "${YELLOW}📦 Exportando servicios...${NC}"
    tar -czf "$EXPORT_DIR/data/services.tar.gz" -C "$COOLIFY_BASE" services/
    echo -e "${GREEN}✓ Servicios exportados: services.tar.gz${NC}"
fi

# Exportar backups
if [ -d "$COOLIFY_BASE/backups" ]; then
    echo -e "${YELLOW}📦 Exportando backups...${NC}"
    tar -czf "$EXPORT_DIR/data/backups.tar.gz" -C "$COOLIFY_BASE" backups/
    echo -e "${GREEN}✓ Backups exportados: backups.tar.gz${NC}"
fi

# Exportar claves SSH
if [ -d "$COOLIFY_BASE/ssh" ]; then
    echo -e "${YELLOW}📦 Exportando claves SSH...${NC}"
    tar -czf "$EXPORT_DIR/data/ssh.tar.gz" -C "$COOLIFY_BASE" ssh/
    echo -e "${GREEN}✓ Claves SSH exportadas: ssh.tar.gz${NC}"
fi

# Paso 4: Exportar volúmenes Docker nombrados
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 4/7: Exportando volúmenes Docker nombrados${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

mkdir -p "$EXPORT_DIR/volumes"

# Listar volúmenes de Coolify
VOLUMES=$(docker volume ls --format "{{.Name}}" | grep -E "coolify|postgres|redis" || true)

if [ ! -z "$VOLUMES" ]; then
    for VOLUME in $VOLUMES; do
        echo -e "${YELLOW}📦 Exportando volumen: $VOLUME${NC}"
        docker run --rm -v "$VOLUME":/volume -v "$EXPORT_DIR/volumes":/backup alpine tar czf "/backup/${VOLUME}.tar.gz" -C /volume .
        echo -e "${GREEN}✓ Volumen $VOLUME exportado${NC}"
    done
else
    echo -e "${YELLOW}⚠️  No se encontraron volúmenes Docker nombrados${NC}"
fi

# Paso 5: Crear script de restauración
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 5/7: Creando script de restauración${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

cat > "$EXPORT_DIR/restore-coolify.sh" << 'RESTORE_SCRIPT'
#!/bin/bash

# Script de Restauración de Coolify
# Ejecutar este script en el nuevo servidor después de instalar Coolify

set -e

RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m'

EXPORT_DIR="$(cd "$(dirname "$0")" && pwd)"
COOLIFY_BASE="/data/coolify"
COOLIFY_SOURCE="${COOLIFY_BASE}/source"

echo -e "${BLUE}╔════════════════════════════════════════════════════════╗${NC}"
echo -e "${BLUE}║   Script de Restauración de Coolify                  ║${NC}"
echo -e "${BLUE}╚════════════════════════════════════════════════════════╝${NC}"
echo ""

# Verificar que Coolify está instalado
if [ ! -d "$COOLIFY_SOURCE" ]; then
    echo -e "${RED}❌ Error: Coolify no está instalado en $COOLIFY_SOURCE${NC}"
    echo -e "${YELLOW}   Por favor, instala Coolify primero usando el script oficial${NC}"
    exit 1
fi

# Paso 1: Restaurar configuración
echo -e "${BLUE}Paso 1/5: Restaurando configuración...${NC}"
if [ -f "$EXPORT_DIR/config/.env" ]; then
    cp "$EXPORT_DIR/config/.env" "$COOLIFY_SOURCE/.env"
    echo -e "${GREEN}✓ .env restaurado${NC}"
fi

if [ -f "$EXPORT_DIR/config/.env.production" ]; then
    cp "$EXPORT_DIR/config/.env.production" "$COOLIFY_SOURCE/.env.production"
    echo -e "${GREEN}✓ .env.production restaurado${NC}"
fi

# Paso 2: Restaurar volúmenes de datos
echo ""
echo -e "${BLUE}Paso 2/5: Restaurando volúmenes de datos...${NC}"
mkdir -p "$COOLIFY_BASE"

if [ -f "$EXPORT_DIR/data/applications.tar.gz" ]; then
    tar -xzf "$EXPORT_DIR/data/applications.tar.gz" -C "$COOLIFY_BASE"
    echo -e "${GREEN}✓ Aplicaciones restauradas${NC}"
fi

if [ -f "$EXPORT_DIR/data/databases.tar.gz" ]; then
    tar -xzf "$EXPORT_DIR/data/databases.tar.gz" -C "$COOLIFY_BASE"
    echo -e "${GREEN}✓ Bases de datos restauradas${NC}"
fi

if [ -f "$EXPORT_DIR/data/services.tar.gz" ]; then
    tar -xzf "$EXPORT_DIR/data/services.tar.gz" -C "$COOLIFY_BASE"
    echo -e "${GREEN}✓ Servicios restaurados${NC}"
fi

if [ -f "$EXPORT_DIR/data/backups.tar.gz" ]; then
    tar -xzf "$EXPORT_DIR/data/backups.tar.gz" -C "$COOLIFY_BASE"
    echo -e "${GREEN}✓ Backups restaurados${NC}"
fi

if [ -f "$EXPORT_DIR/data/ssh.tar.gz" ]; then
    tar -xzf "$EXPORT_DIR/data/ssh.tar.gz" -C "$COOLIFY_BASE"
    chmod -R 600 "$COOLIFY_BASE/ssh"/* 2>/dev/null || true
    echo -e "${GREEN}✓ Claves SSH restauradas${NC}"
fi

# Paso 3: Restaurar volúmenes Docker
echo ""
echo -e "${BLUE}Paso 3/5: Restaurando volúmenes Docker...${NC}"
for VOLUME_FILE in "$EXPORT_DIR/volumes"/*.tar.gz; do
    if [ -f "$VOLUME_FILE" ]; then
        VOLUME_NAME=$(basename "$VOLUME_FILE" .tar.gz)
        echo -e "${YELLOW}📦 Restaurando volumen: $VOLUME_NAME${NC}"

        # Crear volumen si no existe
        docker volume create "$VOLUME_NAME" 2>/dev/null || true

        # Restaurar datos
        docker run --rm -v "$VOLUME_NAME":/volume -v "$EXPORT_DIR/volumes":/backup alpine sh -c "cd /volume && tar xzf /backup/$(basename $VOLUME_FILE)"
        echo -e "${GREEN}✓ Volumen $VOLUME_NAME restaurado${NC}"
    fi
done

# Paso 4: Restaurar base de datos
echo ""
echo -e "${BLUE}Paso 4/5: Restaurando base de datos...${NC}"

# Esperar a que el contenedor de base de datos esté listo
DB_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "coolify-db|postgres" | head -1)
if [ -z "$DB_CONTAINER" ]; then
    echo -e "${YELLOW}⚠️  Iniciando contenedores de Coolify...${NC}"
    cd "$COOLIFY_SOURCE"
    docker compose -f docker-compose.yml -f docker-compose.prod.yml up -d
    sleep 10

    DB_CONTAINER=$(docker ps --format "{{.Names}}" | grep -E "coolify-db|postgres" | head -1)
fi

if [ ! -z "$DB_CONTAINER" ]; then
    # Obtener credenciales
    DB_USER=$(docker exec "$DB_CONTAINER" env | grep POSTGRES_USER | cut -d '=' -f2 | head -1)
    DB_NAME=$(docker exec "$DB_CONTAINER" env | grep POSTGRES_DB | cut -d '=' -f2 | head -1)

    if [ -z "$DB_USER" ]; then
        DB_USER="coolify"
    fi
    if [ -z "$DB_NAME" ]; then
        DB_NAME="coolify"
    fi

    # Restaurar base de datos
    if [ -f "$EXPORT_DIR/database_backup.dump" ]; then
        echo -e "${YELLOW}📦 Restaurando desde dump personalizado...${NC}"
        docker cp "$EXPORT_DIR/database_backup.dump" "$DB_CONTAINER:/tmp/restore.dump"
        docker exec "$DB_CONTAINER" pg_restore -U "$DB_USER" -d "$DB_NAME" -c -v "/tmp/restore.dump" || {
            echo -e "${YELLOW}⚠️  Error en restauración, intentando con SQL...${NC}"
            if [ -f "$EXPORT_DIR/database_backup.sql" ]; then
                docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" "$DB_NAME" < "$EXPORT_DIR/database_backup.sql"
            fi
        }
        docker exec "$DB_CONTAINER" rm -f "/tmp/restore.dump"
    elif [ -f "$EXPORT_DIR/database_backup.sql" ]; then
        echo -e "${YELLOW}📦 Restaurando desde SQL...${NC}"
        docker exec -i "$DB_CONTAINER" psql -U "$DB_USER" "$DB_NAME" < "$EXPORT_DIR/database_backup.sql"
    fi

    echo -e "${GREEN}✓ Base de datos restaurada${NC}"
else
    echo -e "${RED}❌ No se pudo encontrar el contenedor de base de datos${NC}"
fi

# Paso 5: Limpiar cachés y reiniciar
echo ""
echo -e "${BLUE}Paso 5/5: Limpiando cachés y reiniciando...${NC}"
cd "$COOLIFY_SOURCE"
docker compose -f docker-compose.yml -f docker-compose.prod.yml restart coolify

echo ""
echo -e "${GREEN}✅ Restauración completada!${NC}"
echo -e "${YELLOW}   Accede a Coolify y verifica que todo esté funcionando correctamente${NC}"
RESTORE_SCRIPT

chmod +x "$EXPORT_DIR/restore-coolify.sh"
echo -e "${GREEN}✓ Script de restauración creado: restore-coolify.sh${NC}"

# Paso 6: Crear README con instrucciones
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 6/7: Creando documentación${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

cat > "$EXPORT_DIR/README.md" << 'README'
# Exportación Completa de Coolify

Este directorio contiene una exportación completa de tu instalación de Coolify, lista para migrar a un nuevo servidor.

## Contenido

- `config/` - Archivos de configuración (.env, docker-compose.yml)
- `data/` - Volúmenes de datos (aplicaciones, bases de datos, servicios, backups, SSH)
- `volumes/` - Volúmenes Docker nombrados (coolify-db, coolify-redis, etc.)
- `database_backup.sql` o `database_backup.dump` - Backup completo de la base de datos PostgreSQL
- `restore-coolify.sh` - Script de restauración automática

## Instrucciones de Migración

### En el Servidor Original (donde se ejecutó la exportación)

1. Comprimir el directorio de exportación:
```bash
cd /tmp
tar -czf coolify-export-YYYYMMDD_HHMMSS.tar.gz coolify-export-YYYYMMDD_HHMMSS/
```

2. Transferir al nuevo servidor:
```bash
# Opción 1: SCP
scp coolify-export-YYYYMMDD_HHMMSS.tar.gz usuario@nuevo-servidor:/tmp/

# Opción 2: rsync
rsync -avz coolify-export-YYYYMMDD_HHMMSS.tar.gz usuario@nuevo-servidor:/tmp/
```

### En el Nuevo Servidor

1. Instalar Coolify usando el script oficial:
```bash
curl -fsSL https://cdn.coollabs.io/coolify/install.sh | bash
```

2. Extraer la exportación:
```bash
cd /tmp
tar -xzf coolify-export-YYYYMMDD_HHMMSS.tar.gz
cd coolify-export-YYYYMMDD_HHMMSS
```

3. Ejecutar el script de restauración:
```bash
sudo bash restore-coolify.sh
```

4. Verificar que todo funciona:
```bash
docker ps | grep coolify
docker logs coolify --tail=50
```

## Notas Importantes

- ⚠️ **Asegúrate de que el nuevo servidor tenga suficiente espacio en disco**
- ⚠️ **Las claves SSH se restauran pero pueden necesitar ajustes de permisos**
- ⚠️ **Revisa las configuraciones de red y dominios en el nuevo servidor**
- ⚠️ **Las aplicaciones desplegadas pueden necesitar reconfiguración de DNS**

## Solución de Problemas

### Error al restaurar la base de datos
```bash
# Verificar que el contenedor de base de datos está corriendo
docker ps | grep postgres

# Ver logs
docker logs coolify-db
```

### Error de permisos en SSH
```bash
chmod -R 600 /data/coolify/ssh/*
chown -R www-data:www-data /data/coolify/ssh
```

### Las aplicaciones no se conectan
- Verifica que los servidores remotos estén configurados correctamente
- Revisa las variables de entorno de las aplicaciones
- Verifica la conectividad de red
README

echo -e "${GREEN}✓ README.md creado${NC}"

# Paso 7: Resumen final
echo ""
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"
echo -e "${BLUE}Paso 7/7: Resumen de exportación${NC}"
echo -e "${BLUE}═══════════════════════════════════════════════════════${NC}"

echo ""
echo -e "${GREEN}✅ Exportación completada exitosamente!${NC}"
echo ""
echo -e "${YELLOW}📦 Directorio de exportación:${NC} $EXPORT_DIR"
echo ""
echo -e "${BLUE}Próximos pasos:${NC}"
echo -e "1. Comprimir el directorio:"
echo -e "   ${GREEN}cd /tmp && tar -czf coolify-export-${TIMESTAMP}.tar.gz coolify-export-${TIMESTAMP}/${NC}"
echo ""
echo -e "2. Transferir al nuevo servidor:"
echo -e "   ${GREEN}scp coolify-export-${TIMESTAMP}.tar.gz usuario@nuevo-servidor:/tmp/${NC}"
echo ""
echo -e "3. En el nuevo servidor, extraer y ejecutar:"
echo -e "   ${GREEN}tar -xzf coolify-export-${TIMESTAMP}.tar.gz${NC}"
echo -e "   ${GREEN}cd coolify-export-${TIMESTAMP}${NC}"
echo -e "   ${GREEN}sudo bash restore-coolify.sh${NC}"
echo ""
echo -e "${YELLOW}📄 Lee el archivo README.md para instrucciones detalladas${NC}"
echo ""
