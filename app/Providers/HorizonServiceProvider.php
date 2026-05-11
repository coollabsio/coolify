<?php

namespace App\Providers;

use App\Contracts\CustomJobRepositoryInterface;
use App\Exceptions\DeploymentException;
use App\Models\ApplicationDeploymentQueue;
use App\Models\User;
use App\Repositories\CustomJobRepository;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Events\JobReserved;
use Laravel\Horizon\HorizonApplicationServiceProvider;

class HorizonServiceProvider extends HorizonApplicationServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(JobRepository::class, CustomJobRepository::class);
        $this->app->singleton(CustomJobRepositoryInterface::class, CustomJobRepository::class);
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        parent::boot();
        Event::listen(function (JobReserved $event) {
            $payload = $event->payload->decoded;
            $jobName = $payload['displayName'];
            if ($jobName === 'App\Jobs\ApplicationDeploymentJob') {
                $tags = $payload['tags'];
                $id = $payload['id'];
                $deploymentQueueId = collect($tags)->first(function ($tag) {
                    return str_contains($tag, 'App\Models\ApplicationDeploymentQueue');
                });
                if (blank($deploymentQueueId)) {
                    return;
                }
                $deploymentQueueId = explode(':', $deploymentQueueId)[1];
                $deploymentQueue = ApplicationDeploymentQueue::find($deploymentQueueId);
                $deploymentQueue->update([
                    'horizon_job_id' => $id,
                ]);
            }
        });

        Event::listen(function (JobFailed $event) {
            if (! isCloud()) {
                return;
            }

            $exception = $event->exception;
            if (! ($exception instanceof DeploymentException) && ! ($exception instanceof TimeoutExceededException)) {
                return;
            }

            try {
                $uuid = $event->job->uuid();
                if ($uuid) {
                    app(JobRepository::class)->deleteFailed($uuid);
                }
            } catch (\Throwable $e) {
                // Best-effort scrub; never mask the original failure.
            }
        });
    }

    protected function gate(): void
    {
        Gate::define('viewHorizon', function ($user) {
            $root_user = User::find(0);

            return in_array($user->email, [
                $root_user->email,
            ]);
        });
    }
}
