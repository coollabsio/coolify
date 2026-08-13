<?php

namespace App\Actions\Migration;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

class PackageInstanceBackup
{
    use AsAction;

    /**
     * @return array{dump_path: string, app_key: string, ssh_keys_archive: string, package_dir: string, expected_user_count: int}
     */
    public function handle(?Server $localhost = null): array
    {
        $localhost ??= Server::find(0);
        if (! $localhost) {
            throw new RuntimeException('Coolify host (server id 0) was not found.');
        }

        $packageDir = storage_path('app/instance-migrations/'.uniqid('pkg_', true));
        File::ensureDirectoryExists($packageDir);

        $dumpPath = $packageDir.'/coolify-db.dmp';
        $this->createDatabaseDump($localhost, $dumpPath);

        $appKey = $this->readAppKey();
        file_put_contents($packageDir.'/APP_KEY', $appKey);

        $sshKeysArchive = $packageDir.'/ssh-keys.tar.gz';
        $this->archiveSshKeys($sshKeysArchive);

        return [
            'dump_path' => $dumpPath,
            'app_key' => $appKey,
            'ssh_keys_archive' => $sshKeysArchive,
            'package_dir' => $packageDir,
            'expected_user_count' => User::query()->count(),
        ];
    }

    private function createDatabaseDump(Server $localhost, string $dumpPath): void
    {
        $remoteTmp = '/tmp/coolify-instance-migration-'.uniqid('', true).'.dmp';

        instant_remote_process([
            'docker exec coolify-db sh -c '.escapeshellarg(
                'pg_dump --format=custom --no-acl --no-owner -U "$POSTGRES_USER" --file='.$remoteTmp.' "${POSTGRES_DB:-coolify}"'
            ),
            'docker cp coolify-db:'.escapeshellarg($remoteTmp).' '.escapeshellarg($remoteTmp),
            'docker exec coolify-db rm -f '.escapeshellarg($remoteTmp),
        ], $localhost, timeout: 3600);

        $download = SshMultiplexingHelper::generateScpDownloadCommand($localhost, $remoteTmp, $dumpPath);
        $result = Process::timeout(3600)->run($download);
        if ($result->failed() || ! is_file($dumpPath) || filesize($dumpPath) === 0) {
            throw new RuntimeException('Failed to download Coolify database dump: '.$result->errorOutput());
        }

        instant_remote_process(['rm -f '.escapeshellarg($remoteTmp)], $localhost, false);
    }

    private function readAppKey(): string
    {
        $envPath = '/data/coolify/source/.env';
        if (is_readable($envPath)) {
            $contents = file_get_contents($envPath) ?: '';
            foreach (preg_split('/\R/', $contents) ?: [] as $line) {
                if (str_starts_with(trim($line), 'APP_KEY=')) {
                    return trim(substr(trim($line), strlen('APP_KEY=')));
                }
            }
        }

        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('APP_KEY could not be read from /data/coolify/source/.env or config.');
        }

        return $key;
    }

    private function archiveSshKeys(string $archivePath): void
    {
        $keysDir = storage_path('app/ssh/keys');
        File::ensureDirectoryExists($keysDir);

        $result = Process::timeout(300)->run([
            'tar',
            '-czf',
            $archivePath,
            '-C',
            storage_path('app/ssh'),
            'keys',
        ]);

        if ($result->failed() || ! is_file($archivePath)) {
            throw new RuntimeException('Failed to archive SSH keys: '.$result->errorOutput());
        }
    }
}
