<?php

namespace App\Actions\RemoteProcess;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

class RunRemoteProcess
{
    public Activity $activity;

    public bool $hideFromOutput;

    public bool $setStatus;

    protected $timeStart;

    protected $currentTime;

    protected $lastWriteAt = 0;

    protected $throttleIntervalMS = 500;

    protected int $counter = 1;

    public const MARK_START = "|--";
    public const MARK_END = "--|";
    public const SEPARATOR = '|';
    public const MARK_REGEX = "/(\|--\d+\|\d+\|(?:out|err)--\|)/";

    /**
     * Create a new job instance.
     */
    public function __construct(Activity $activity, bool $hideFromOutput = false, bool $setStatus = false)
    {

        if ($activity->getExtraProperty('type') !== ActivityTypes::REMOTE_PROCESS->value && $activity->getExtraProperty('type') !== ActivityTypes::DEPLOYMENT->value) {
            throw new \RuntimeException('Incompatible Activity to run a remote command.');
        }

        $this->activity = $activity;
        $this->hideFromOutput = $hideFromOutput;
        $this->setStatus = $setStatus;
    }

    public function __invoke(): ProcessResult
    {
        $this->activity->properties = $this->activity->properties->merge([
            'status' => ProcessStatus::IN_PROGRESS,
        ]);
        $this->timeStart = hrtime(true);

        $processResult = Process::run($this->getCommand(), $this->handleOutput(...));

        $status = $processResult->exitCode() != 0 ? ProcessStatus::ERROR : ($this->setStatus ? ProcessStatus::FINISHED : null);

        $this->activity->properties = $this->activity->properties->merge([
            'exitCode' => $processResult->exitCode(),
            'stdout' => $this->hideFromOutput || $processResult->output(),
            'stderr' => $processResult->errorOutput(),
        ]);
        if (isset($status)) {
            $this->activity->properties = $this->activity->properties->merge([
                'status' => $status->value,
            ]);
        }

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
        if ($this->hideFromOutput) {
            return;
        }

        $this->currentTime = $this->elapsedTime();

        $this->activity->description .= $this->encodeOutput($type, $output);

        if ($this->isAfterLastThrottle()) {
            // Let's write to database.
            DB::transaction(function () {
                $this->activity->save();
                $this->lastWriteAt = $this->currentTime;
            });
        }
    }

    public function encodeOutput($type, $output)
    {
        return
            static::MARK_START . $this->counter++ .
            static::SEPARATOR . $this->elapsedTime() .
            static::SEPARATOR . $type .
            static::MARK_END .
            $output;
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
