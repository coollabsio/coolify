<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ServerTransfer\ServerTransferMigrator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Moves a server to another Coolify instance outside the web request, because a large import can take
 * longer than the nginx request timeout. The job payload is encrypted because it holds the target token.
 */
class MigrateServerToInstanceJob implements ShouldBeEncrypted, ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = ServerTransferMigrator::MIGRATION_TIMEOUT_SECONDS;

    public function __construct(
        public Server $server,
        public string $targetUrl,
        public string $targetToken,
    ) {
        $this->onQueue('high');
    }

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(ServerTransferMigrator $migrator): void
    {
        try {
            $result = $migrator->migrate($this->server, $this->targetUrl, $this->targetToken);
            $migrator->recordMigration($this->server, 'succeeded', [
                'export_id' => $result['export_id'],
                'warnings' => $result['warnings'],
                'checklist' => $result['checklist'],
                'target_server_url' => $result['target_server_url'] ?? null,
            ]);
        } catch (Throwable $e) {
            $migrator->recordMigration($this->server, 'failed', ['error' => $e->getMessage()]);
        }
    }

    /**
     * The worker stopped the job, for example at its timeout. The target can manage the server already,
     * so the migrator asks the target before it records the result.
     */
    public function failed(?Throwable $exception): void
    {
        app(ServerTransferMigrator::class)->recordStoppedMigration(
            $this->server,
            $this->targetUrl,
            $this->targetToken,
            $exception?->getMessage() ?: 'The transfer job stopped before it finished.',
        );
    }
}
