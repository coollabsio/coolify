# S3 Path Prefix Feature - Entwicklung & PR Anleitung

## Feature-Beschreibung

Dieses Feature ermöglicht es, einen **Path Prefix** für S3-Backups zu konfigurieren. Dies ist nützlich, um mehrere Coolify-Instanzen auf einem einzelnen S3-Bucket zu speichern.

**Beispiel:**
- Instanz 1: `s3://bucket/production/data/coolify/backups/...`
- Instanz 2: `s3://bucket/staging/data/coolify/backups/...`

---

## Teil 1: Entwicklungsumgebung starten (Windows)

### Voraussetzungen

- Docker Desktop installiert und gestartet
- Git
- PowerShell oder CMD

### Schritt 1: Repository klonen (falls noch nicht geschehen)

```powershell
git clone https://github.com/DEIN-USERNAME/coolify.git
cd coolify
```

### Schritt 2: Environment-Datei erstellen

```powershell
copy .env.development.example .env
```

### Schritt 3: .env anpassen

Öffne `.env` und stelle sicher, dass folgende Werte gesetzt sind:

```env
# Coolify Configuration
APP_ENV=local
APP_NAME="Coolify Development"
APP_ID=development
APP_KEY=base64:dGVzdGtleWZvcmRldmVsb3BtZW50MTIzNDU2Nzg5MA==
APP_URL=http://localhost:8080
APP_PORT=8080
APP_DEBUG=true
SSH_MUX_ENABLED=false

# PostgreSQL Database Configuration
DB_CONNECTION=pgsql
DB_DATABASE=coolify
DB_USERNAME=coolify
DB_PASSWORD=password
DB_HOST=coolify-db
DB_PORT=5432

# Redis Configuration
REDIS_HOST=coolify-redis
REDIS_PASSWORD=null
REDIS_PORT=6379

# Pusher/Soketi Configuration
PUSHER_HOST=coolify-realtime
PUSHER_PORT=6001
PUSHER_SCHEME=http
PUSHER_APP_ID=coolify
PUSHER_APP_KEY=coolify
PUSHER_APP_SECRET=coolify

# Vite Configuration
VITE_HOST=localhost
VITE_PORT=5173
```

**Wichtig:**

- `APP_KEY` muss gesetzt sein (sonst "Internal Server Error")
- `DB_HOST=coolify-db` (Container-Name, nicht `host.docker.internal`)

### Schritt 4: Docker-Container starten (Windows-spezifisch!)

**WICHTIG FÜR WINDOWS:** Verwende die Windows-spezifische Docker Compose Datei!

Die Standard `docker-compose.dev.yml` funktioniert nicht korrekt unter Windows, da `vendor/` und `node_modules/` auf das Windows-Dateisystem gemountet werden. Dies verursacht Symlink- und Performance-Probleme.

```powershell
# Windows-Version verwenden (empfohlen)
docker compose -f docker-compose.yml -f docker-compose.dev.windows.yml up -d --build
```

Die `docker-compose.dev.windows.yml` speichert `vendor/` und `node_modules/` in Docker Named Volumes statt auf dem Windows-Dateisystem.

### Schritt 5: Warten bis Container bereit sind

```powershell
# Container-Status prüfen
docker ps

# Logs beobachten (Composer install dauert 2-5 Minuten)
docker logs coolify -f
```

Warte bis du siehst:

```
Generating optimized autoload files
> @php artisan package:discover --ansi
```

### Schritt 6: Datenbank migrieren

```powershell
docker exec coolify php artisan migrate --seed
```

### Schritt 7: Anwendung öffnen

| Service | URL | Login |
|---------|-----|-------|
| **Coolify** | http://localhost:8080 | test@example.com / password |
| MinIO Console | http://localhost:9001 | minioadmin / minioadmin |
| Mailpit | http://localhost:8025 | - |

---

## Teil 2: Feature testen

### S3 Storage mit Path Prefix anlegen

1. Öffne http://localhost:8080
2. Login: `test@example.com` / `password`
3. Gehe zu **Settings** → **S3 Storages** → **Add**
4. Fülle die Felder aus:
   - **Name:** `MinIO Test`
   - **Endpoint:** `http://coolify-minio:9000`
   - **Bucket:** `local`
   - **Region:** `us-east-1`
   - **Access Key:** `minioadmin`
   - **Secret Key:** `minioadmin`
   - **Path Prefix:** `instance-1` *(Das neue Feature!)*
5. Klicke **Save** und dann **Validate Connection**

### Backup testen (optional)

1. Erstelle eine Datenbank (z.B. PostgreSQL)
2. Konfiguriere ein Backup mit dem S3 Storage
3. Führe das Backup aus
4. Prüfe in MinIO Console (http://localhost:9001), ob der Pfad korrekt ist:
   - Erwartet: `local/instance-1/data/coolify/backups/databases/...`

---

## Teil 3: Tests ausführen

### Unit Tests
```powershell
# Alle Unit Tests
docker exec coolify ./vendor/bin/pest tests/Unit

# Nur S3 Storage Tests
docker exec coolify ./vendor/bin/pest tests/Unit/S3StorageTest.php
```

### Code Formatierung (Laravel Pint)
```powershell
docker exec coolify ./vendor/bin/pint
```

### PHPStan (Static Analysis)
```powershell
docker exec coolify ./vendor/bin/phpstan
```

---

## Teil 4: Pull Request erstellen

### Schritt 1: Branch erstellen
```powershell
git checkout -b feature/s3-path-prefix
```

### Schritt 2: Änderungen committen
```powershell
git add .
git commit -m "feat(s3): add path prefix support for S3 storage backups

Add optional path prefix configuration for S3 storage to support
multiple Coolify instances on a single S3 bucket.

Changes:
- Add 'path' column to s3_storages table (migration)
- Add path input field to S3 storage form
- Apply path prefix when uploading backups to S3
- Apply path prefix when deleting backups from S3
- Add unit tests for path attribute normalization"
```

### Schritt 3: Push zu deinem Fork
```powershell
git push -u origin feature/s3-path-prefix
```

### Schritt 4: Pull Request auf GitHub erstellen

1. Gehe zu https://github.com/coollabsio/coolify
2. Klicke auf **Pull requests** → **New pull request**
3. Wähle **base: next** (WICHTIG: nicht main!)
4. Wähle deinen Fork und Branch als **compare**

---

## Teil 4a: PR Template (Offizielles Coolify Format)

**WICHTIG:** Coolify verwendet ein einfaches PR-Template. Lösche die Checklist-Sektion vor dem Absenden!

### PR Title
```
feat(s3): add path prefix support for S3 storage backups
```

### PR Body (kopiere diesen Text):

```markdown
## Changes
- Add optional "Path Prefix" field to S3 storage configuration
- Add `path` column to `s3_storages` table via migration
- Apply path prefix when uploading backups to S3 in `DatabaseBackupJob`
- Apply path prefix when deleting backups from S3 in `deleteBackupsS3()` helper
- Add unit tests for path attribute normalization
- Allows storing backups from multiple Coolify instances in a single S3 bucket

### Modified Files:
- `database/migrations/2025_12_26_000001_add_path_to_s3_storages_table.php` - New migration
- `app/Livewire/Storage/Form.php` - Add path property, validation, and sync
- `resources/views/livewire/storage/form.blade.php` - Add path input field with helper text
- `app/Jobs/DatabaseBackupJob.php` - Apply path prefix when uploading to S3
- `bootstrap/helpers/databases.php` - Apply path prefix when deleting from S3
- `tests/Unit/S3StorageTest.php` - Add tests for path normalization

## Issues
- Implements feature request for S3 path prefix support (no existing issue)
```

---

## Teil 4b: Erforderliche Test-Ausgaben

Vor dem PR müssen folgende Tests erfolgreich durchlaufen:

### 1. Unit Tests ausführen
```powershell
docker exec coolify ./vendor/bin/pest tests/Unit/S3StorageTest.php
```

**Erwartete Ausgabe:**
```
   PASS  Tests\Unit\S3StorageTest
  ✓ S3Storage model has correct cast definitions
  ✓ S3Storage isUsable method returns is_usable attribute value
  ✓ S3Storage awsUrl method constructs correct URL format
  ✓ S3Storage model is guarded correctly
  ✓ S3Storage path attribute normalizes path correctly
  ✓ S3Storage path attribute handles various path formats

  Tests:    6 passed (15 assertions)
  Duration: 0.XXs
```

### 2. Alle Unit Tests ausführen
```powershell
docker exec coolify ./vendor/bin/pest tests/Unit
```

**Erwartete Ausgabe (alle Tests müssen PASS sein):**
```
   PASS  Tests\Unit\...
   ...
  Tests:    XX passed
  Duration: X.XXs
```

### 3. Laravel Pint (Code Style) ausführen
```powershell
docker exec coolify ./vendor/bin/pint --test
```

**Erwartete Ausgabe (keine Änderungen nötig):**
```
  PASS  No style issues found.
```

Falls Änderungen nötig sind:
```powershell
docker exec coolify ./vendor/bin/pint
git add .
git commit --amend --no-edit
```

### 4. PHPStan (Static Analysis) ausführen
```powershell
docker exec coolify ./vendor/bin/phpstan analyse
```

**Erwartete Ausgabe:**
```
 [OK] No errors
```

---

## Teil 4c: Vollständige Test-Dokumentation für PR

Füge diese Test-Ergebnisse als Kommentar zum PR hinzu:

```markdown
## Test Results

### Unit Tests
\`\`\`
$ ./vendor/bin/pest tests/Unit/S3StorageTest.php

   PASS  Tests\Unit\S3StorageTest
  ✓ S3Storage model has correct cast definitions
  ✓ S3Storage isUsable method returns is_usable attribute value
  ✓ S3Storage awsUrl method constructs correct URL format
  ✓ S3Storage model is guarded correctly
  ✓ S3Storage path attribute normalizes path correctly
  ✓ S3Storage path attribute handles various path formats

  Tests:    6 passed (15 assertions)
\`\`\`

### Code Style (Pint)
\`\`\`
$ ./vendor/bin/pint --test
  PASS  No style issues found.
\`\`\`

### Manual Testing
- [x] Created S3 storage with path prefix "instance-1"
- [x] Validated connection successfully
- [x] Created PostgreSQL database backup
- [x] Verified backup stored at: `bucket/instance-1/data/coolify/backups/databases/...`
- [x] Verified backup deletion works correctly with path prefix
```

---

## Teil 5: Aufräumen

### Container stoppen
```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down
```

### Container und Volumes komplett entfernen
```powershell
docker compose -f docker-compose.yml -f docker-compose.dev.yml down -v
```

---

## Geänderte Dateien (Übersicht)

| Datei | Änderung |
|-------|----------|
| `database/migrations/2025_12_26_000001_add_path_to_s3_storages_table.php` | Neue Migration für `path` Spalte |
| `app/Livewire/Storage/Form.php` | `$path` Property, Validierung, syncData |
| `resources/views/livewire/storage/form.blade.php` | Path Prefix Input-Feld |
| `app/Jobs/DatabaseBackupJob.php` | Path Prefix beim S3-Upload anwenden |
| `bootstrap/helpers/databases.php` | Path Prefix beim S3-Delete anwenden |
| `tests/Unit/S3StorageTest.php` | Tests für Path-Normalisierung |

---

## Troubleshooting

### "Internal Server Error"
- Prüfe ob `APP_KEY` in `.env` gesetzt ist
- Prüfe ob `DB_HOST=coolify-db` (nicht `host.docker.internal`)

### Container startet nicht
```powershell
docker logs coolify
```

### Datenbank-Verbindungsfehler
```powershell
docker exec coolify php artisan config:clear
docker exec coolify php artisan cache:clear
```

### Composer install hängt
- Warte 2-5 Minuten, der erste Build dauert länger
- Prüfe mit `docker logs coolify -f`
