<?php

namespace App\Services;

use App\Jobs\ExecuteCoolifyProcess;
use Illuminate\Process\ProcessResult;
use Spatie\Activitylog\Contracts\Activity;

class CoolifyProcess
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
                'type' => 'COOLIFY_PROCESS',
                'user' => $this->user,
                'destination' => $this->destination,
                'port' => $this->port,
                'command' => $this->command,
                'status' => ProcessStatus::HOLDING,
            ])
            ->log("Awaiting command to start...\n\n");
    }

    public function __invoke(): Activity
    {
        $job = new ExecuteCoolifyProcess($this->activity);

        dispatch($job);

        return $this->activity;
    }


}
