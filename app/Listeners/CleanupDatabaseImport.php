<?php

namespace App\Listeners;

use App\Events\DatabaseImportFinished;
use App\Models\Server;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

class CleanupDatabaseImport implements ShouldQueue
{
    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [5, 15, 30];

    public function handle(DatabaseImportFinished $event): void
    {
        $commands = $this->commands($event->data);
        $server = Server::query()->find($event->data['serverId'] ?? null);

        if ($server && $commands !== []) {
            instant_remote_process($commands, $server);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    public function commands(array $data): array
    {
        $commands = [];

        if (filled($data['containerName'] ?? null)) {
            $commands[] = 'docker rm -f '.escapeshellarg($data['containerName']).' 2>/dev/null || true';
        }

        if (isSafeTmpPath($data['serverTmpPath'] ?? null)) {
            $commands[] = 'rm -f '.escapeshellarg($data['serverTmpPath']).' 2>/dev/null || true';
        }

        if (isSafeTmpPath($data['credentialTmpPath'] ?? null)) {
            $commands[] = 'rm -f '.escapeshellarg($data['credentialTmpPath']).' 2>/dev/null || true';
        }

        if (filled($data['container'] ?? null)) {
            foreach (['containerTmpPath', 'scriptPath'] as $key) {
                if (isSafeTmpPath($data[$key] ?? null)) {
                    $commands[] = 'docker exec '.escapeshellarg($data['container']).' rm -f '.escapeshellarg($data[$key]).' 2>/dev/null || true';
                }
            }
        }

        return $commands;
    }

    public function failed(DatabaseImportFinished $event, Throwable $exception): void
    {
        Log::error('Database import cleanup failed', [
            'serverId' => $event->data['serverId'] ?? null,
            'containerName' => $event->data['containerName'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
