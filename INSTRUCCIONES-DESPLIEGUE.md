# 📋 Instrucciones de Despliegue - File Explorer

## Resumen

Este documento explica cómo desplegar la nueva funcionalidad de File Explorer en tu instancia de Coolify existente.

## Archivos Modificados/Creados

### Componentes principales:
- `app/Livewire/Project/Shared/FileExplorer.php` - Componente Livewire principal
- `resources/views/livewire/project/shared/file-explorer.blade.php` - Vista del explorador
- `routes/web.php` - Rutas agregadas (3 rutas + 1 ruta de descarga)
- `resources/views/livewire/project/application/heading.blade.php` - Enlace "Files"
- `resources/views/livewire/project/database/heading.blade.php` - Enlace "Files"
- `resources/views/livewire/project/service/heading.blade.php` - Enlace "Files"
- `tests/Feature/FileExplorerTest.php` - Tests

### Scripts:
- `update-coolify.sh` - Script de actualización automática
- `deploy.sh` - Script para subir cambios a GitHub (opcional)

## Proceso de Despliegue

### Opción 1: Actualización Automática (Recomendado)

1. **Sube el script al servidor:**
   ```bash
   scp update-coolify.sh usuario@tu-servidor:/tmp/
   ```

2. **Conéctate al servidor:**
   ```bash
   ssh usuario@tu-servidor
   ```

3. **Ejecuta el script:**
   ```bash
   sudo bash /tmp/update-coolify.sh
   ```

El script automáticamente:
- ✅ Detecta la ubicación de Coolify
- ✅ Crea backups de seguridad
- ✅ Actualiza el código desde GitHub
- ✅ Instala dependencias
- ✅ Ejecuta migraciones
- ✅ Limpia cachés
- ✅ Reinicia servicios

### Opción 2: Actualización Manual

Si prefieres hacerlo manualmente:

1. **Conéctate al servidor donde está Coolify:**
   ```bash
   ssh usuario@tu-servidor
   ```

2. **Ve al directorio de Coolify:**
   ```bash
   cd /data/coolify/source
   ```

3. **Verifica que es un repositorio Git:**
   ```bash
   git status
   ```

4. **Si NO es un repositorio Git, inicialízalo:**
   ```bash
   git init
   git remote add origin https://github.com/crmhawkins/coolify.git
   git fetch origin v4.x
   git checkout -b v4.x origin/v4.x
   ```

5. **Si YA es un repositorio Git, actualiza:**
   ```bash
   git fetch origin v4.x
   git merge origin/v4.x
   ```

6. **Instala dependencias dentro del contenedor:**
   ```bash
   docker exec coolify composer install --no-interaction --no-dev --optimize-autoloader
   ```

7. **Ejecuta migraciones:**
   ```bash
   docker exec coolify php artisan migrate --force
   ```

8. **Limpia y optimiza:**
   ```bash
   docker exec coolify php artisan optimize:clear
   docker exec coolify php artisan config:cache
   docker exec coolify php artisan route:cache
   ```

9. **Reinicia Coolify:**
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml restart coolify
   ```

## Verificación Post-Despliegue

1. **Verifica que Coolify está funcionando:**
   ```bash
   docker ps | grep coolify
   ```

2. **Revisa los logs si hay problemas:**
   ```bash
   docker logs coolify --tail=50
   ```

3. **Accede a la interfaz web** y verifica:
   - Puedes ver el enlace "Files" en Applications, Databases y Services
   - El explorador de archivos carga correctamente
   - Puedes listar archivos en contenedores

## Estructura de Directorios de Coolify

Coolify se instala típicamente en:
```
/data/coolify/
├── source/          ← Código fuente (aquí está el código)
│   ├── .env
│   ├── docker-compose.yml
│   └── ...
├── applications/    ← Datos de aplicaciones
├── databases/       ← Datos de bases de datos
├── backups/         ← Backups
└── ...
```

El script `update-coolify.sh` detecta automáticamente esta estructura.

## Solución de Problemas

### Error: "Directorio de Coolify no encontrado"
- Verifica que Coolify está instalado en `/data/coolify/source`
- O ajusta manualmente `COOLIFY_DIR` en el script

### Error: "No es un repositorio Git"
- El código fuente debe ser un repositorio Git
- Inicializa Git en el directorio como se muestra arriba

### Error al ejecutar migraciones
- Revisa los logs: `docker logs coolify`
- Verifica la conexión a la base de datos

### El explorador no aparece
- Limpia la caché del navegador
- Verifica que las rutas están registradas: `docker exec coolify php artisan route:list --name=files`

## Rollback (Revertir Cambios)

Si necesitas revertir los cambios:

1. **Restaurar desde backup:**
   ```bash
   cp /data/coolify-backups/backup_YYYYMMDD_HHMMSS/.env.backup /data/coolify/source/.env
   ```

2. **Revertir código Git:**
   ```bash
   cd /data/coolify/source
   git reset --hard HEAD~1  # O el commit anterior
   ```

3. **Reiniciar servicios:**
   ```bash
   docker compose -f docker-compose.yml -f docker-compose.prod.yml restart coolify
   ```

## Soporte

Si encuentras problemas:
1. Revisa los logs: `docker logs coolify`
2. Verifica que todos los servicios están corriendo: `docker ps`
3. Revisa los backups en `/data/coolify-backups/`
