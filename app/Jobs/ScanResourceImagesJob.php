<?php

namespace App\Jobs;

use App\Actions\ImageScan\ScanImage;
use App\Enums\ImageScanStatus;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ImageScan;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Scans the images of a resource one at a time, after it started, so the scan is not in the critical path of a
 * deploy (spec 4.4).
 */
class ScanResourceImagesJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Each image waits at most this long for the scan lock of the server. */
    public const LOCK_WAIT_SECONDS = 600;

    /** Images scanned when the job derives them from the resource. */
    public const MAX_DERIVED_IMAGES = 5;

    public $tries = 1;

    public $timeout;

    /**
     * @param  list<string>  $imageRefs  Empty: the images of the resource (imageRefsFor()).
     */
    public function __construct(public Model $resource, public ?int $deploymentQueueId = null, public array $imageRefs = [])
    {
        // Self-hosted Horizon works through 'high' first, so scans there would delay deployments.
        $this->onQueue(isCloud() ? maintenance_queue() : 'default');
        $images = $imageRefs === [] ? self::MAX_DERIVED_IMAGES : count($imageRefs);
        $this->timeout = $images * (self::LOCK_WAIT_SECONDS + ScanImage::LOCK_TTL);
    }

    public function handle(): void
    {
        $deployment = $this->deployment();
        $server = $this->server($deployment);
        if (! $server instanceof Server) {
            return;
        }

        $imageRefs = $this->imageRefs !== [] ? $this->imageRefs : $this->imageRefsFor($this->resource);

        foreach (array_values(array_unique(array_filter($imageRefs, 'is_string'))) as $imageRef) {
            ScanImage::run($server, $this->resource, $imageRef, $deployment, self::LOCK_WAIT_SECONDS);
        }
    }

    /**
     * The images a resource runs. Applications: the images of their latest completed scans. Services and
     * standalone databases are added in Task 11.
     *
     * @return list<string>
     */
    public function imageRefsFor(Model $resource): array
    {
        if ($resource instanceof Application) {
            return ImageScan::query()
                ->where('resource_type', $resource->getMorphClass())
                ->where('resource_id', $resource->getKey())
                ->where('status', ImageScanStatus::Completed)
                ->orderByDesc('scanned_at')
                ->orderByDesc('id')
                ->pluck('image_ref')
                ->unique()
                ->take(self::MAX_DERIVED_IMAGES)
                ->values()
                ->all();
        }

        return [];
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
