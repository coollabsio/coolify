<?php

namespace App\Jobs;

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanErrorCode;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scans the images of a resource after it started, so the scan is not in the critical path of a deploy (spec 4.4).
 */
class ScanResourceImagesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = ScanImage::LOCK_TTL;

    public $tries = 3;

    private const LOCK_BUSY_RETRY_SECONDS = 300;

    public function __construct(public Model $resource, public ?int $deploymentQueueId = null, public ?string $imageRef = null)
    {
        $this->onQueue(maintenance_queue());
    }

    public function handle(): void
    {
        if (blank($this->imageRef)) {
            return;
        }

        $deployment = $this->deployment();
        $server = $this->server($deployment);
        if (! $server instanceof Server) {
            return;
        }

        $scan = ScanImage::run($server, $this->resource, $this->imageRef, $deployment);

        if ($scan->error_code === ImageScanErrorCode::LockBusy && $this->attempts() < $this->tries) {
            $this->release(self::LOCK_BUSY_RETRY_SECONDS);
        }
    }

    private function deployment(): ?ApplicationDeploymentQueue
    {
        if ($this->deploymentQueueId === null || ! $this->resource instanceof Application) {
            return null;
        }

        return ApplicationDeploymentQueue::query()
            ->whereKey($this->deploymentQueueId)
            ->where('application_id', (string) $this->resource->getKey())
            ->first();
    }

    /**
     * The server of the deployment (an additional server runs its own copy of the image), else the main server.
     */
    private function server(?ApplicationDeploymentQueue $deployment): ?Server
    {
        $server = $deployment?->server ?? data_get($this->resource, 'destination.server');

        return $server instanceof Server ? $server : null;
    }
}
