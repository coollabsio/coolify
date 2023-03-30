<?php

namespace App\Actions\RemoteProcess;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;

class RunRemoteProcess
{
    public Activity $activity;

    protected $timeStart;

    protected $currentTime;

    protected $lastWriteAt = 0;

    protected $throttleIntervalMS = 500;

    protected string $stdOutIncremental = '';

    protected string $stdErrIncremental = '';

    /**
     * Create a new job instance.
     */
    public function __construct(Activity $activity)
    {

        if ($activity->getExtraProperty('type') !== ActivityTypes::REMOTE_PROCESS->value && $activity->getExtraProperty('type') !== ActivityTypes::DEPLOYMENT->value) {
            throw new \RuntimeException('Incompatible Activity to run a remote command.');
        }

        $this->activity = $activity;
    }

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
        $server_ip = $this->activity->getExtraProperty('server_ip');
        $private_key_location = $this->activity->getExtraProperty('private_key_location');
        $port = $this->activity->getExtraProperty('port');
        $command = $this->activity->getExtraProperty('command');

        return generateSshCommand($private_key_location, $server_ip, $user, $port, $command);
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
