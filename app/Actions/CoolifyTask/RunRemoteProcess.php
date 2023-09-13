<?php

namespace App\Actions\CoolifyTask;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Jobs\ApplicationDeploymentJob;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

class RunRemoteProcess
{
    public Activity $activity;

    public bool $hide_from_output;

    public bool $is_finished;

    public bool $ignore_errors;

    protected $time_start;

    protected $current_time;

    protected $last_write_at = 0;

    protected $throttle_interval_ms = 500;

    protected int $counter = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(Activity $activity, bool $hide_from_output = false, bool $is_finished = false, bool $ignore_errors = false)
    {

        if ($activity->getExtraProperty('type') !== ActivityTypes::INLINE->value) {
            throw new \RuntimeException('Incompatible Activity to run a remote command.');
        }

        $this->activity = $activity;
        $this->hide_from_output = $hide_from_output;
        $this->is_finished = $is_finished;
        $this->ignore_errors = $ignore_errors;
    }

    public static function decodeOutput(?Activity $activity = null): string
    {
        if (is_null($activity)) {
            return '';
        }

        try {
            $decoded = json_decode(
                data_get($activity, 'description'),
                associative: true,
                flags: JSON_THROW_ON_ERROR
            );
        } catch (\JsonException $exception) {
            return '';
        }

        return collect($decoded)
            ->sortBy(fn ($i) => $i['order'])
            ->map(fn ($i) => $i['output'])
            ->implode("");
    }

    public function __invoke(): ProcessResult
    {
        $this->time_start = hrtime(true);

        $status = ProcessStatus::IN_PROGRESS;
        $processResult = Process::forever()->run($this->getCommand(), $this->handleOutput(...));

        if ($this->activity->properties->get('status') === ProcessStatus::ERROR->value) {
            $status = ProcessStatus::ERROR;
        } else {
            if (($processResult->exitCode() == 0 && $this->is_finished) || $this->activity->properties->get('status') === ProcessStatus::FINISHED->value) {
                $status = ProcessStatus::FINISHED;
            }
            if ($processResult->exitCode() != 0 && !$this->ignore_errors) {
                $status = ProcessStatus::ERROR;
            }
        }

        $this->activity->properties = $this->activity->properties->merge([
            'exitCode' => $processResult->exitCode(),
            'stdout' => $processResult->output(),
            'stderr' => $processResult->errorOutput(),
            'status' => $status->value,
        ]);
        $this->activity->save();
        if ($processResult->exitCode() != 0 && !$this->ignore_errors) {
            throw new \RuntimeException($processResult->errorOutput());
        }

        return $processResult;
    }

    protected function getCommand(): string
    {
        $user = $this->activity->getExtraProperty('user');
        $server_ip = $this->activity->getExtraProperty('server_ip');
        $private_key_location = $this->activity->getExtraProperty('private_key_location');
        $port = $this->activity->getExtraProperty('port');
        $command = $this->activity->getExtraProperty('command');

        return generate_ssh_command($private_key_location, $server_ip, $user, $port, $command);
    }

    protected function handleOutput(string $type, string $output)
    {
        if ($this->hide_from_output) {
            return;
        }
        $this->current_time = $this->elapsedTime();
        $this->activity->description = $this->encodeOutput($type, $output);

        if ($this->isAfterLastThrottle()) {
            // Let's write to database.
            DB::transaction(function () {
                $this->activity->save();
                $this->last_write_at = $this->current_time;
            });
        }
    }

    protected function elapsedTime(): int
    {
        $timeMs = (hrtime(true) - $this->time_start) / 1_000_000;

        return intval($timeMs);
    }

    public function encodeOutput($type, $output)
    {
        $outputStack = json_decode($this->activity->description, associative: true, flags: JSON_THROW_ON_ERROR);

        $outputStack[] = [
            'type' => $type,
            'output' => $output,
            'timestamp' => hrtime(true),
            'batch' => ApplicationDeploymentJob::$batch_counter,
            'order' => $this->getLatestCounter(),
        ];

        return json_encode($outputStack, flags: JSON_THROW_ON_ERROR);
    }

    protected function getLatestCounter(): int
    {
        $description = json_decode($this->activity->description, associative: true, flags: JSON_THROW_ON_ERROR);
        if ($description === null || count($description) === 0) {
            return 1;
        }
        return end($description)['order'] + 1;
    }

    /**
     * Determines if it's time to write again to database.
     *
     * @return bool
     */
    protected function isAfterLastThrottle()
    {
        // If DB was never written, then we immediately decide we have to write.
        if ($this->last_write_at === 0) {
            return true;
        }

        return ($this->current_time - $this->throttle_interval_ms) > $this->last_write_at;
    }
}
