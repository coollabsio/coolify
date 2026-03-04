# Script de Actualización de Coolify

Este script actualiza tu instancia de Coolify desde GitHub sin perder datos.

## Características

- ✅ **Backup automático** de configuración y base de datos
- ✅ **Actualización segura** desde GitHub
- ✅ **Preservación de datos** - no se pierde ninguna información
- ✅ **Migraciones automáticas** de base de datos
- ✅ **Limpieza de cachés** y optimización
- ✅ **Reinicio automático** de servicios

## Uso

### ⚠️ IMPORTANTE: Dónde ejecutar el script

**El script debe ejecutarse EN EL SERVIDOR donde está instalado Coolify**, no en tu máquina local.

### Paso 1: Subir el script al servidor

```bash
# Desde tu máquina local
scp update-coolify.sh usuario@tu-servidor:/tmp/
```

### Paso 2: Conectarte al servidor

```bash
ssh usuario@tu-servidor
```

### Paso 3: Ejecutar el script

```bash
# Mover el script a una ubicación permanente (opcional)
sudo mv /tmp/update-coolify.sh /usr/local/bin/update-coolify.sh
sudo chmod +x /usr/local/bin/update-coolify.sh

# Ejecutar el script
sudo /usr/local/bin/update-coolify.sh
```

O simplemente:

```bash
sudo bash /tmp/update-coolify.sh
```

### ⚠️ Nota sobre permisos

El script detecta automáticamente la ubicación de Coolify (`/data/coolify/source`), pero necesita permisos de root o sudo para:
- Acceder a archivos de configuración
- Hacer backup de la base de datos
- Reiniciar contenedores Docker

## Configuración

El script **detecta automáticamente** la ubicación de Coolify. Por defecto busca en:
- `/data/coolify/source` (ubicación estándar del código fuente)
- `/data/coolify` (ubicación alternativa)

Si tu instalación está en otra ubicación, puedes ajustar estas variables al inicio del script:

```bash
REPO_URL="https://github.com/crmhawkins/coolify.git"  # Tu repositorio
BRANCH="v4.x"                                          # Rama a actualizar
```

**No necesitas ajustar COOLIFY_DIR** - el script lo detecta automáticamente.

## Qué hace el script

1. **Crea backup** de:
   - Archivo `.env`
   - Archivo `.env.production`
   - Base de datos PostgreSQL
   - Directorio de datos

2. **Actualiza código** desde GitHub:
   - Obtiene últimos cambios
   - Aplica actualizaciones
   - Maneja conflictos automáticamente

3. **Instala dependencias**:
   - Composer (PHP)
   - NPM (Node.js)

4. **Ejecuta migraciones** de base de datos

5. **Limpia y optimiza**:
   - Limpia cachés de Laravel
   - Regenera cachés optimizados

6. **Reinicia servicios**:
   - Reinicia contenedor de Coolify
   - Verifica que todo funcione

## Restaurar backup

Si algo sale mal, puedes restaurar el backup:

```bash
# Restaurar .env
cp /data/coolify-backups/backup_YYYYMMDD_HHMMSS/.env.backup /data/coolify/.env

# Restaurar base de datos
docker exec coolify-db psql -U coolify coolify < /data/coolify-backups/backup_YYYYMMDD_HHMMSS/database_backup_YYYYMMDD_HHMMSS.sql
```

## Notas importantes

- ⚠️ El script requiere acceso a Docker y Git
- ⚠️ Se recomienda ejecutar durante ventanas de mantenimiento
- ⚠️ Los backups se guardan en `/data/coolify-backups/`
- ⚠️ El script no modifica tus aplicaciones desplegadas

## Solución de problemas

### Error: "Directorio de Coolify no encontrado"
Ajusta la variable `COOLIFY_DIR` en el script con la ruta correcta.

### Error: "No se puede obtener cambios desde GitHub"
Verifica tu conexión a internet y que el repositorio sea accesible.

### Error al ejecutar migraciones
Revisa los logs del contenedor:
```bash
docker logs coolify
```

## Soporte

Si encuentras problemas, revisa los logs:
```bash
docker logs coolify
docker compose -f docker-compose.prod.yml logs
```
