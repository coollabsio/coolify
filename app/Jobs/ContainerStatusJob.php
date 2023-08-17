<?php

namespace App\Jobs;

use App\Models\ApplicationPreview;
use App\Notifications\Application\StatusChanged;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ContainerStatusJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $container_name;
    public string|null $pull_request_id;
    public $resource;

    public function __construct($resource, string $container_name, string|null $pull_request_id = null)
    {
        $this->resource = $resource;
        $this->container_name = $container_name;
        $this->pull_request_id = $pull_request_id;
    }

    public function uniqueId(): string
    {
        return $this->container_name;
    }

    public function handle(): void
    {
        try {
            $status = get_container_status(server: $this->resource->destination->server, container_id: $this->container_name, throwError: false);
            if ($this->resource->status === 'running' && $status !== 'running') {
                $this->resource->environment->project->team->notify(new StatusChanged($this->resource));
            }

            if ($this->pull_request_id) {
                $preview = ApplicationPreview::findPreviewByApplicationAndPullId($this->resource->id, $this->pull_request_id);
                $preview->status = $status;
                $preview->save();
            } else {
                $this->resource->status = $status;
                $this->resource->save();
            }
        } catch (\Exception $e) {
            ray($e->getMessage());
        }
    }
}
