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
            ->withProperty('type', 'COOLIFY_PROCESS')
            ->withProperty('user', $this->user)
            ->withProperty('destination', $this->destination)
            ->withProperty('port', $this->port)
            ->withProperty('command', $this->command)
            ->withProperty('status', ProcessStatus::HOLDING)
            ->log("Awaiting to start command...\n\n");
    }

    public function __invoke(): Activity|ProcessResult
    {
        $job = new ExecuteCoolifyProcess($this->activity);

        if (app()->environment('testing')) {
            return $job->handle();
        }

        dispatch($job);

        return $this->activity;
    }


}
