<?php

namespace App\Actions\RemoteProcess;

use App\Data\RemoteProcessArgs;
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
        $arguments = new RemoteProcessArgs(
            destination: $this->destination,
            command: $this->command,
            port: $this->port,
            user: $this->user,
        );
        
        $this->activity = activity()
            ->withProperties($arguments->toArray())
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
