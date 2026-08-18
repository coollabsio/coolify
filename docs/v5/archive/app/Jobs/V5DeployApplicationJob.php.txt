<?php

namespace App\Jobs;

use App\Actions\V5\Application\DeployNginxApplication;
use App\Models\V5\Application as V5Application;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class V5DeployApplicationJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 300;

    /**
     * Job timeout plus a safety margin so a lost lock can never block
     * redeploys of the same application forever.
     */
    public int $uniqueFor = 360;

    public function __construct(public int $applicationId) {}

    public function uniqueId(): string
    {
        return (string) $this->applicationId;
    }

    public function handle(): void
    {
        $application = V5Application::query()->find($this->applicationId);

        if (! $application instanceof V5Application) {
            return;
        }

        DeployNginxApplication::run($application);
    }

    public function failed(?\Throwable $exception): void
    {
        V5Application::query()->find($this->applicationId)?->update([
            'status' => 'failed',
            'status_message' => str($exception?->getMessage() ?? 'The deploy job failed.')->limit(10000)->toString(),
        ]);
    }
}
