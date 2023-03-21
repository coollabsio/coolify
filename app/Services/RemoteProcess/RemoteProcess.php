<?php

namespace App\Services\RemoteProcess;

use App\Services\ProcessStatus;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Contracts\Activity;

class RemoteProcess
{
    protected $timeStart;

    protected $currentTime;

    protected $lastWriteAt = 0;

    protected $throttleIntervalMS = 500;

    protected string $stdOutIncremental = '';

    protected string $stdErrIncremental = '';

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Activity $activity,
    ){}

    public function __invoke(): ProcessResult
    {
        $this->timeStart = hrtime(true);

        $processResult = Process::run($this->getCommand(), $this->handleOutput(...));

        $status = match ($processResult->exitCode()) {
            0 => ProcessStatus::FINISHED,
            default => ProcessStatus::ERROR,
        };

        $this->activity->properties = $this->activity->properties->merge([
            'exitCode' => $processResult->exitCode(),
            'stdout' => $processResult->output(),
            'stderr' => $processResult->errorOutput(),
            'status' => $status,
        ]);

        $this->activity->save();

        return $processResult;
    }

    protected function getCommand(): string
    {
        $user = $this->activity->getExtraProperty('user');
        $destination = $this->activity->getExtraProperty('destination');
        $port = $this->activity->getExtraProperty('port');
        $command = $this->activity->getExtraProperty('command');

        $delimiter = 'EOF-COOLIFY-SSH';

        return 'ssh '
            . '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PasswordAuthentication=no '
            . '-o RequestTTY=no '
            // Quiet mode. Causes most warning and diagnostic messages to be suppressed.
            // Errors are still out put. This is to silence for example, that warning
            // Permanently added <host and key type> to the list of known hosts.
            . '-q '
            . "-p {$port} "
            . "{$user}@{$destination} "
            . " 'bash -se' << \\$delimiter" . PHP_EOL
            . $command . PHP_EOL
            . $delimiter;
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
     * Determines if it's time to write again to database.
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
