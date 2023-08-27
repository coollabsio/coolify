<?php

namespace App\Jobs;

use App\Models\ApplicationPreview;
use App\Models\StandalonePostgresql;
use App\Notifications\Application\StatusChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DatabaseContainerStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $containerName;

    public function __construct(
        public StandalonePostgresql $database,
    ) {
        $this->containerName = $database->uuid;
    }

    public function uniqueId(): string
    {
        return $this->containerName;
    }

    public function handle(): void
    {
        try {
            $status = getContainerStatus(
                server: $this->database->destination->server,
                container_id: $this->containerName,
                throwError: false
            );

            if ($this->database->status === 'running' && $status !== 'running') {
                if (data_get($this->database, 'environment.project.team')) {
                    // $this->database->environment->project->team->notify(new StatusChanged($this->database));
                }
            }
            if ($this->database->status !== $status) {
                $this->database->status = $status;
                $this->database->save();
            }
        } catch (\Exception $e) {
            send_internal_notification('DatabaseContainerStatusJob failed with: ' . $e->getMessage());
            ray($e->getMessage());
            throw $e;
        }
    }
}
