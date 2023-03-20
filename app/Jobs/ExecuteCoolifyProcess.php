<?php

namespace App\Jobs;

use Illuminate\Support\Facades\DB;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Process\InvokedProcess;
use Illuminate\Process\ProcessResult;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Contracts\Activity;

class ExecuteCoolifyProcess implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $throttleIntervalMS = 500;

    protected $timeStart;

    protected $currentTime;

    protected $lastWriteAt = 0;

    protected string $stdOutIncremental = '';

    protected string $stdErrIncremental = '';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Activity $activity,
    ){}

    /**
     * Execute the job.
     */
    public function handle(): ?ProcessResult
    {
        ray()->clearAll();
        $this->timeStart = $start = hrtime(true);

        $user = $this->activity->getExtraProperty('user');
        $destination = $this->activity->getExtraProperty('destination');
        $port = $this->activity->getExtraProperty('port');
        $command = $this->activity->getExtraProperty('command');

        $delimiter = 'EOF-COOLIFY-SSH';

        File::chmod(base_path('coolify_id25519'), 0600);

        $sshCommand = 'ssh '
            . '-i ./coolify_id25519 '
            . '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PasswordAuthentication=no '
            . "{$user}@{$destination} "
            . " 'bash -se' << \\$delimiter" . PHP_EOL
            . $command . PHP_EOL
            . $delimiter;

//        $sshCommand = "whoami ; pwd ; ls ";

        $process = Process::start(
            $sshCommand,
            $this->handleOutput(...),
        );


        $res = $process->wait();

        if (app()->environment('testing')) {
            return $res;
        }

      
        // TODO Why is this not persisting?? Immutable property??
        $this->activity->properties->put('pid', $process->id());
        $this->activity->properties->put('exitCode', $res->exitCode());
        $this->activity->properties->put('stdout', $res->output());
        $this->activity->properties->put('stderr', $res->errorOutput());
        $this->activity->save();
        return $res;
    }

    protected function handleOutput(string $type, string $output)
    {
        $this->currentTime = $this->elapsedTime();

        if ($type === 'out') {
            $this->stdOutIncremental .= $output;
        } else {
            $this->stdErrIncremental .= $output;
        }

        $this->activity->description .= $output;

        if ($this->isAfterLastThrottle()) {
            // Let's write to database.
            DB::transaction(function () {
                $this->activity->save();
                $this->lastWriteAt = $this->currentTime;
            });
        }
    }

    /**
     * Decides if it's time to write again to database.
     *
     * @return bool
     */
    protected function isAfterLastThrottle()
    {
        // If DB was never written, then we immediately decide we have to write.
        if ($this->lastWriteAt === 0) {
            return true;
        }

        return ($this->currentTime - $this->throttleIntervalMS) > $this->lastWriteAt;
    }

    protected function elapsedTime(): int
    {
        $timeMs = (hrtime(true) - $this->timeStart) / 1_000_000;

        return intval($timeMs);
    }
}
