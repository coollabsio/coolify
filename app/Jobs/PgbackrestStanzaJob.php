<?php

namespace App\Jobs;

use App\Models\StandalonePostgresql;
use App\Notifications\Database\PgbackrestStanzaCreated;
use App\Notifications\Database\PgbackrestStanzaFailed;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PgbackrestStanzaJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [30, 60, 120];

    public $timeout = 300;

    public function __construct(
        public StandalonePostgresql $database,
        public string $action = 'create'
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        if (! $this->database->isPgbackrestEnabled()) {
            Log::info('pgBackRest is not enabled for database', ['database_id' => $this->database->id]);

            return;
        }

        $server = $this->database->destination->server;
        $containerName = $this->database->getPgbackrestContainerName();
        $stanzaName = $this->database->getPgbackrestStanzaName();

        $checkContainer = instant_remote_process(
            ["docker ps -q -f name=^/{$containerName}\$"],
            $server,
            false
        );

        if (blank(trim($checkContainer))) {
            Log::warning('pgBackRest container is not running, will retry', [
                'database_id' => $this->database->id,
                'container' => $containerName,
            ]);
            $this->release(60);

            return;
        }

        match ($this->action) {
            'create' => $this->createStanza($server, $containerName, $stanzaName),
            'upgrade' => $this->upgradeStanza($server, $containerName, $stanzaName),
            'check' => $this->checkStanza($server, $containerName, $stanzaName),
            default => throw new \InvalidArgumentException("Invalid stanza action: {$this->action}"),
        };
    }

    private function createStanza($server, string $containerName, string $stanzaName): void
    {
        $checkCommand = "docker exec {$containerName} pgbackrest --stanza={$stanzaName} info 2>&1";
        $checkResult = instant_remote_process([$checkCommand], $server, false);

        if (str_contains($checkResult, 'missing stanza')) {
            Log::info('Creating pgBackRest stanza', [
                'database_id' => $this->database->id,
                'stanza' => $stanzaName,
            ]);

            $createCommand = "docker exec {$containerName} pgbackrest --stanza={$stanzaName} stanza-create";
            instant_remote_process([$createCommand], $server);

            Log::info('pgBackRest stanza created successfully', [
                'database_id' => $this->database->id,
                'stanza' => $stanzaName,
            ]);

            $team = $this->database->team();
            $team?->notify(new PgbackrestStanzaCreated($this->database));
        } else {
            Log::info('pgBackRest stanza already exists', [
                'database_id' => $this->database->id,
                'stanza' => $stanzaName,
            ]);
        }
    }

    private function upgradeStanza($server, string $containerName, string $stanzaName): void
    {
        Log::info('Upgrading pgBackRest stanza', [
            'database_id' => $this->database->id,
            'stanza' => $stanzaName,
        ]);

        $upgradeCommand = "docker exec {$containerName} pgbackrest --stanza={$stanzaName} stanza-upgrade";
        instant_remote_process([$upgradeCommand], $server);

        Log::info('pgBackRest stanza upgraded successfully', [
            'database_id' => $this->database->id,
            'stanza' => $stanzaName,
        ]);
    }

    private function checkStanza($server, string $containerName, string $stanzaName): void
    {
        Log::info('Checking pgBackRest stanza', [
            'database_id' => $this->database->id,
            'stanza' => $stanzaName,
        ]);

        $checkCommand = "docker exec {$containerName} pgbackrest --stanza={$stanzaName} check";
        instant_remote_process([$checkCommand], $server);

        Log::info('pgBackRest stanza check passed', [
            'database_id' => $this->database->id,
            'stanza' => $stanzaName,
        ]);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('pgBackRest stanza job failed', [
            'database_id' => $this->database->id,
            'action' => $this->action,
            'error' => $exception->getMessage(),
        ]);

        $team = $this->database->team();
        $team?->notify(new PgbackrestStanzaFailed($this->database, $exception->getMessage()));
    }
}
