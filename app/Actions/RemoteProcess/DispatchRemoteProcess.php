<?php

namespace App\Actions\RemoteProcess;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Jobs\ExecuteCoolifyProcess;
use Spatie\Activitylog\Contracts\Activity;

class DispatchRemoteProcess
{
    protected Activity $activity;

    // TODO Left 'root' as default user instead of 'coolify' because
    //      there's a task at TODO.md to run docker without sudo
    public function __construct(
        protected string    $destination,
        protected string    $command,
        protected ?int      $port = 22,
        protected ?string   $user = 'root',
    ){
        $this->activity = activity()
            ->withProperties([
                'type' => ActivityTypes::COOLIFY_PROCESS,
                'status' => ProcessStatus::HOLDING,
                'user' => $this->user,
                'destination' => $this->destination,
                'port' => $this->port,
                'command' => $this->command,
            ])
            ->log("Awaiting command to start...\n\n");
    }

    public function __invoke(): Activity
    {
        $job = new ExecuteCoolifyProcess($this->activity);

        dispatch($job);

        $this->activity->refresh();

        return $this->activity;
    }

}
