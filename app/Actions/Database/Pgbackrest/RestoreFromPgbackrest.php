<?php

namespace App\Actions\Database\Pgbackrest;

use App\Models\StandalonePostgresql;
use Lorisleiva\Actions\Concerns\AsAction;

class RestoreFromPgbackrest
{
    use AsAction;

    public function getAvailableBackups(StandalonePostgresql $database): array
    {
        if (! $database->isPgbackrestEnabled()) {
            return ['success' => false, 'message' => 'pgBackRest is not enabled', 'backups' => []];
        }

        if (! isPostgresContainerRunning($database)) {
            return ['success' => false, 'message' => 'PostgreSQL container is not running', 'backups' => []];
        }

        $backups = getPgbackrestBackupList($database)->toArray();

        return ['success' => true, 'backups' => $backups];
    }

    public function validateRestore(StandalonePostgresql $database, ?string $backupLabel = null): array
    {
        if (! $database->isPgbackrestEnabled()) {
            return ['valid' => false, 'message' => 'pgBackRest is not enabled'];
        }

        if ($backupLabel) {
            if (isPostgresContainerRunning($database)) {
                $backup = getPgbackrestBackupByLabel($database, $backupLabel);
                if (! $backup) {
                    return ['valid' => false, 'message' => 'Backup not found in pgBackRest repository'];
                }
            } else {
                $backupExists = $this->verifyBackupExistsWithTempContainer($database, $backupLabel);
                if (! $backupExists) {
                    return ['valid' => false, 'message' => 'Backup not found in pgBackRest repository'];
                }
            }
        }

        return ['valid' => true, 'message' => 'Restore can proceed'];
    }

    private function verifyBackupExistsWithTempContainer(StandalonePostgresql $database, string $backupLabel): bool
    {
        $server = $database->destination->server ?? null;
        if (! $server) {
            return false;
        }

        $containerName = $database->uuid;
        $stanzaName = $database->getPgbackrestStanzaName();
        $image = $database->image;

        $mounts = $this->getVolumeMountsFromInspectOrFallback($database, $server);
        if (empty($mounts['pgbackrest_config']) || empty($mounts['pgbackrest_repo'])) {
            return false;
        }

        $escapedLabel = escapeshellarg($backupLabel);
        $escapedStanza = escapeshellarg($stanzaName);

        $cmd = 'docker run --rm '.
            "-v {$mounts['pgbackrest_config']}:/etc/pgbackrest ".
            "-v {$mounts['pgbackrest_repo']}:/var/lib/pgbackrest ".
            "{$image} sh -c '".
            'apk add --no-cache pgbackrest 2>/dev/null || (apt-get update && apt-get install -y pgbackrest) 2>/dev/null; '.
            "su postgres -c \"pgbackrest --stanza={$escapedStanza} info --output=json\" 2>/dev/null".
            "' 2>&1";

        try {
            $output = instant_remote_process([$cmd], $server, throwError: false);

            if (blank($output)) {
                return false;
            }

            $info = json_decode($output, true);
            if (json_last_error() !== JSON_ERROR_NONE || ! is_array($info)) {
                return false;
            }

            if (empty($info[0]['backup']) || ! is_array($info[0]['backup'])) {
                return false;
            }

            foreach ($info[0]['backup'] as $backup) {
                if (($backup['label'] ?? null) === $backupLabel) {
                    return true;
                }
            }

            return false;
        } catch (\Throwable) {
            return false;
        }
    }

    private function getVolumeMountsFromInspectOrFallback(StandalonePostgresql $database, $server): array
    {
        $containerName = $database->uuid;
        $configDir = database_configuration_dir()."/{$containerName}";

        $inspectCmd = "docker inspect {$containerName} --format '{{range .Mounts}}{{if eq .Destination \"/etc/pgbackrest\"}}PGBACKREST_CONFIG={{.Source}}{{end}}{{if eq .Destination \"/var/lib/pgbackrest\"}}PGBACKREST_REPO={{.Source}}{{end}}{{end}}' 2>/dev/null || true";
        $output = instant_remote_process([$inspectCmd], $server, throwError: false);

        $pgbackrestConfig = '';
        $pgbackrestRepo = '';

        if (preg_match('/PGBACKREST_CONFIG=([^\s]+)/', $output ?? '', $matches)) {
            $pgbackrestConfig = $matches[1];
        }
        if (preg_match('/PGBACKREST_REPO=([^\s]+)/', $output ?? '', $matches)) {
            $pgbackrestRepo = $matches[1];
        }

        if (empty($pgbackrestConfig) || empty($pgbackrestRepo)) {
            $defaultConfig = "{$configDir}/pgbackrest";
            $defaultRepo = "{$configDir}/pgbackrest-repo";

            $checkPathsCmd = "test -d {$defaultRepo} && echo 'EXISTS' || echo 'MISSING'";
            $pathCheck = instant_remote_process([$checkPathsCmd], $server, throwError: false);

            if (trim($pathCheck) === 'EXISTS') {
                $pgbackrestConfig = $pgbackrestConfig ?: $defaultConfig;
                $pgbackrestRepo = $pgbackrestRepo ?: $defaultRepo;
            } else {
                $devConfigDir = "/var/lib/docker/volumes/coolify_dev_coolify_data/_data/databases/{$containerName}";
                $devCheckCmd = "test -d {$devConfigDir}/pgbackrest-repo && echo 'EXISTS' || echo 'MISSING'";
                $devCheck = instant_remote_process([$devCheckCmd], $server, throwError: false);

                if (trim($devCheck) === 'EXISTS') {
                    $pgbackrestConfig = $pgbackrestConfig ?: "{$devConfigDir}/pgbackrest";
                    $pgbackrestRepo = $pgbackrestRepo ?: "{$devConfigDir}/pgbackrest-repo";
                } else {
                    $pgbackrestConfig = $pgbackrestConfig ?: $defaultConfig;
                    $pgbackrestRepo = $pgbackrestRepo ?: $defaultRepo;
                }
            }
        }

        return [
            'pgbackrest_config' => $pgbackrestConfig,
            'pgbackrest_repo' => $pgbackrestRepo,
        ];
    }
}
