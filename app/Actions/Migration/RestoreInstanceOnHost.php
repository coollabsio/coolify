<?php

namespace App\Actions\Migration;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class RestoreInstanceOnHost
{
    use AsAction;

    /**
     * @param  array{dump_path: string, app_key: string, ssh_keys_archive: string, expected_user_count?: int}  $package
     */
    public function handle(Server $target, array $package): void
    {
        $dumpPath = $package['dump_path'] ?? '';
        $appKey = $package['app_key'] ?? '';
        $sshKeysArchive = $package['ssh_keys_archive'] ?? '';
        $expectedUserCount = (int) ($package['expected_user_count'] ?? 1);

        if (! is_file($dumpPath) || $appKey === '' || ! is_file($sshKeysArchive)) {
            throw new RuntimeException('Instance backup package is incomplete.');
        }

        // SCP as a non-root user into a sudo-created /tmp/coolify-* dir fails (dir is root-owned).
        // Upload files directly into world-writable /tmp — same pattern as database import.
        $id = uniqid('', true);
        $remoteDump = self::stagingFilePath($id, 'coolify-db.dmp');
        $remoteKeys = self::stagingFilePath($id, 'ssh-keys.tar.gz');

        try {
            $this->upload($target, $dumpPath, $remoteDump);
            $this->upload($target, $sshKeysArchive, $remoteKeys);

            instant_remote_process(self::updateEnvCommands($appKey), $target);

            instant_remote_process(self::restoreDatabaseCommands($remoteDump), $target, timeout: 3600);

            instant_remote_process(self::syncDatabasePasswordCommands(), $target);

            $userCount = trim((string) (instant_remote_process(self::countUsersCommand(), $target, false) ?? ''));
            if (! is_numeric($userCount) || (int) $userCount < max(1, $expectedUserCount)) {
                throw new RuntimeException('Coolify database restore finished but source data was not found in coolify-db.');
            }

            instant_remote_process(self::restoreKeysCommands($remoteKeys), $target, timeout: 300);

            EnsureCoolifyDataDirsTraversable::run($target);

            instant_remote_process(self::restartCoolifyCommands(), $target, timeout: 1800);

            $health = instant_remote_process(self::coolifyRunningCheckCommands(), $target, false);

            if (! str_contains((string) $health, 'coolify')) {
                throw new RuntimeException('Coolify dashboard container did not start after restore.');
            }
        } finally {
            instant_remote_process([
                'rm -f '.escapeshellarg($remoteDump).' '.escapeshellarg($remoteKeys),
            ], $target, false);
        }
    }

    /**
     * Write APP_KEY into root-owned /data/coolify/source/.env without shell `>>`
     * (that redirect runs as the SSH user even when printf is sudo'd).
     *
     * @return list<string>
     */
    public static function updateEnvCommands(string $appKey): array
    {
        $env = '/data/coolify/source/.env';
        $previousScript = 'if grep -q "^APP_PREVIOUS_KEYS=" '.$env.'; then sed -i "s|^APP_PREVIOUS_KEYS=.*|APP_PREVIOUS_KEYS='.$appKey.'|" '.$env.'; else printf "APP_PREVIOUS_KEYS=%s\n" "'.$appKey.'" | tee -a '.$env.' >/dev/null; fi';

        return [
            '[ -f '.$env.' ]',
            'sh -c '.escapeshellarg($previousScript),
            'sed -i "s|^APP_KEY=.*|APP_KEY='.$appKey.'|" '.$env,
        ];
    }

    /**
     * Restart Coolify without `cd` — non-root sudo rewriting skips `cd`, which fails on root-owned /data/coolify.
     *
     * @return list<string>
     */
    public static function restartCoolifyCommands(): array
    {
        $source = '/data/coolify/source';

        return [
            'docker compose --project-directory '.$source
                .' -f '.$source.'/docker-compose.yml'
                .' -f '.$source.'/docker-compose.prod.yml'
                .' up -d --remove-orphans',
        ];
    }

    /**
     * @return list<string>
     */
    public static function coolifyRunningCheckCommands(): array
    {
        return [
            'docker ps --format "{{.Names}}" | grep -E "^coolify$" || true',
        ];
    }

    /**
     * Copy the dump into coolify-db and restore from a file path.
     * Do not use `docker exec -i ... < dump` — SSH bash -se already owns stdin.
     *
     * @return list<string>
     */
    public static function restoreDatabaseCommands(string $remoteDumpPath): array
    {
        $containerDump = '/tmp/coolify-instance.dmp';

        return [
            'docker stop coolify coolify-realtime 2>/dev/null || true',
            'sleep 3',
            'docker exec coolify-db sh -c '.escapeshellarg('pg_isready -U "$POSTGRES_USER" -d "${POSTGRES_DB:-coolify}"'),
            'docker cp '.escapeshellarg($remoteDumpPath).' coolify-db:'.$containerDump,
            'docker exec coolify-db sh -c '.escapeshellarg(
                'pg_restore --clean --if-exists --no-acl --no-owner -U "$POSTGRES_USER" -d "${POSTGRES_DB:-coolify}" '.$containerDump.'; code=$?; if [ "$code" -ge 2 ]; then exit "$code"; fi'
            ),
            'docker exec coolify-db rm -f '.$containerDump.' || true',
        ];
    }

    /**
     * Keep the Postgres role password in sync with the target .env.
     * pg_restore does not change role passwords, but a later volume copy or a
     * previous install can leave Laravel's DB_PASSWORD unable to authenticate.
     *
     * @return list<string>
     */
    public static function syncDatabasePasswordCommands(): array
    {
        $script = <<<'SH'
set -e
ENV=/data/coolify/source/.env
PASS=$(awk -F= '/^DB_PASSWORD=/{print substr($0, index($0,"=")+1); exit}' "$ENV")
USER=$(awk -F= '/^DB_USERNAME=/{print substr($0, index($0,"=")+1); exit}' "$ENV")
USER=${USER:-coolify}
if [ -z "$PASS" ]; then
  echo "DB_PASSWORD missing from $ENV"
  exit 1
fi
SQL=/tmp/coolify-sync-db-password.sql
printf 'ALTER ROLE %s WITH PASSWORD $coolifypw$%s$coolifypw$;\n' "$USER" "$PASS" | tee "$SQL" >/dev/null
docker cp "$SQL" coolify-db:/tmp/coolify-sync-db-password.sql
docker exec coolify-db sh -c 'psql -U "$POSTGRES_USER" -d postgres -v ON_ERROR_STOP=1 -f /tmp/coolify-sync-db-password.sql'
docker exec coolify-db rm -f /tmp/coolify-sync-db-password.sql || true
rm -f "$SQL" || true
SH;

        return [
            'sh -c '.escapeshellarg($script),
        ];
    }

    /**
     * @return list<string>
     */
    public static function countUsersCommand(): array
    {
        return [
            'docker exec coolify-db sh -c '.escapeshellarg(
                'psql -U "$POSTGRES_USER" -d "${POSTGRES_DB:-coolify}" -tAc "SELECT COUNT(*) FROM users"'
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public static function restoreKeysCommands(string $remoteKeysArchive): array
    {
        // Entire extract runs under one sudo'd sh -c so writes into root-owned /data/coolify/ssh succeed.
        $script = 'mkdir -p /data/coolify/ssh'
            .' && tar -xzf '.escapeshellarg($remoteKeysArchive).' -C /data/coolify/ssh'
            .' && chown -R 9999:root /data/coolify/ssh'
            .' && chmod -R 700 /data/coolify/ssh';

        return [
            'sh -c '.escapeshellarg($script),
        ];
    }

    /**
     * Flat file under /tmp so SCP does not need a pre-created directory.
     */
    public static function stagingFilePath(string $id, string $filename): string
    {
        return '/tmp/coolify-instance-migration-'.$id.'-'.$filename;
    }

    /**
     * All remote command builders used during restore (for non-root permission audits).
     *
     * @return list<string>
     */
    public static function allRemoteCommands(string $appKey = 'base64:test', string $dump = '/tmp/d.dmp', string $keys = '/tmp/k.tgz'): array
    {
        return array_merge(
            self::updateEnvCommands($appKey),
            self::restoreDatabaseCommands($dump),
            self::syncDatabasePasswordCommands(),
            self::countUsersCommand(),
            self::restoreKeysCommands($keys),
            EnsureCoolifyDataDirsTraversable::commands(),
            self::restartCoolifyCommands(),
            self::coolifyRunningCheckCommands(),
        );
    }

    private function upload(Server $server, string $localPath, string $remotePath): void
    {
        $command = SshMultiplexingHelper::generateScpCommand($server, $localPath, $remotePath);
        $result = Process::timeout(3600)->run($command);
        if ($result->failed()) {
            throw new RuntimeException('Failed to upload '.$localPath.': '.$result->errorOutput());
        }
    }
}
