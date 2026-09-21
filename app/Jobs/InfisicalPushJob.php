<?php

namespace App\Jobs;

use App\Actions\Infisical\CollectTeamSecrets;
use App\Models\InfisicalConnection;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalLock;
use App\Services\Infisical\InfisicalPathCollisionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * One upsert of one Infisical folder, carrying everything Coolify currently
 * holds for it.
 *
 * The unit of work is the FOLDER, not the key: `parse()` writes hundreds of
 * generated rows during a single deploy and a per-key push would add hundreds
 * of round trips to every deployment. ShouldBeUniqueUntilProcessing plus the
 * dispatch delay collapses that burst into one job per folder, and because the
 * job re-reads the folder from Coolify when it runs, a write that lands during
 * the burst is still included.
 *
 * ShouldBeEncrypted because the serialised connection reaches machine-identity
 * credentials, matching CoolifyTask.
 */
class InfisicalPushJob implements ShouldBeEncrypted, ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Long enough to swallow a parse() burst, short enough that a generated
     * credential shows up in Infisical promptly.
     */
    public int $uniqueFor = 120;

    /**
     * NOT named $connection: Illuminate\Bus\Queueable already defines a
     * $connection property (the queue connection name), and a promoted
     * constructor property of that name is a fatal trait-composition conflict.
     */
    public function __construct(
        public InfisicalConnection $infisicalConnection,
        public string $environmentSlug,
        public string $path,
    ) {
        $this->onQueue(crons_queue());
        $this->delay(now()->addSeconds(10));
    }

    public function uniqueId(): string
    {
        return $this->folderKey();
    }

    /**
     * Two overlapping upserts of one folder would race each other's payloads.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('infisical-push-'.$this->folderKey())];
    }

    public function handle(): void
    {
        // The connection may have been disabled or deleted between the write
        // and this job running.
        if (! $this->infisicalConnection->exists || ! $this->infisicalConnection->is_enabled || $this->infisicalConnection->adopted_at === null) {
            return;
        }

        $bucketId = "{$this->environmentSlug}|{$this->path}";

        try {
            $buckets = CollectTeamSecrets::run($this->infisicalConnection->team, [$bucketId]);
            $bucket = $buckets[$bucketId] ?? null;

            if ($bucket === null || $bucket['secrets'] === []) {
                return;
            }

            $client = $this->infisicalConnection->client();
            $projectId = $this->infisicalConnection->infisical_project_id;

            $client->ensureFolderPath($projectId, $bucket['environment'], $bucket['path']);
            $client->upsertSecrets($projectId, $bucket['environment'], $bucket['path'], $bucket['secrets']);

            // Mark provenance. These are system writes. They change only the
            // two provenance columns, so the saved hook's "did key or value
            // move" guard stops them from queueing another push — without that
            // this stamp would re-enter here forever.
            InfisicalLock::asSystem(function () use ($bucket): void {
                foreach ($bucket['rows'] as $row) {
                    $row->forceFill([
                        'is_infisical_managed' => true,
                        'infisical_path' => $bucket['path'],
                    ])->save();
                }
            });
        } catch (InfisicalApiException|InfisicalPathCollisionException $e) {
            // A failed push must never break the deploy or the save that
            // triggered it. Adoption and the scheduled pull surface the same
            // failure on the connection.
            Log::warning('Infisical push failed', [
                'connection_id' => $this->infisicalConnection->id,
                'environment' => $this->environmentSlug,
                'path' => $this->path,
                'message' => $e->getMessage(),
            ]);
        }
    }

    private function folderKey(): string
    {
        return $this->infisicalConnection->id.'|'.$this->environmentSlug.'|'.$this->path;
    }
}
