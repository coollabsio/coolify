<?php

namespace App\Actions\CoolifyTask;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Server;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

class RunRemoteProcess
{
    public Activity $activity;

    public bool $hide_from_output;

    public bool $ignore_errors;

    public $call_event_on_finish = null;

    public $call_event_data = null;

    protected $time_start;

    protected $current_time;

    protected $last_write_at = 0;

    protected $throttle_interval_ms = 200;

    protected int $counter = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(Activity $activity, bool $hide_from_output = false, bool $ignore_errors = false, $call_event_on_finish = null, $call_event_data = null)
    {
        if ($activity->getExtraProperty('type') !== ActivityTypes::INLINE->value && $activity->getExtraProperty('type') !== ActivityTypes::COMMAND->value) {
            throw new \RuntimeException('Incompatible Activity to run a remote command.');
        }

        $this->activity = $activity;
        $this->hide_from_output = $hide_from_output;
        $this->ignore_errors = $ignore_errors;
        $this->call_event_on_finish = $call_event_on_finish;
        $this->call_event_data = $call_event_data;
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
                flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE
            );
        } catch (\JsonException $exception) {
            return '';
        }

        return collect($decoded)
            ->sortBy(fn ($i) => $i['order'])
            ->map(fn ($i) => $i['output'])
            ->implode('');
    }

    public function __invoke(): ProcessResult
    {
        $this->time_start = hrtime(true);

        $status = ProcessStatus::IN_PROGRESS;
        $timeout = config('constants.ssh.command_timeout');
        $process = Process::timeout($timeout)->start($this->getCommand(), $this->handleOutput(...));
        $this->activity->properties = $this->activity->properties->merge([
            'process_id' => $process->id(),
        ]);

        $processResult = $process->wait();
        // $processResult = Process::timeout($timeout)->run($this->getCommand(), $this->handleOutput(...));
        if ($this->activity->properties->get('status') === ProcessStatus::ERROR->value) {
            $status = ProcessStatus::ERROR;
        } else {
            if ($processResult->exitCode() == 0) {
                $status = ProcessStatus::FINISHED;
            } else {
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
        if ($this->call_event_on_finish) {
            try {
                if ($this->call_event_data) {
                    event(resolve("App\\Events\\$this->call_event_on_finish", [
                        'data' => $this->call_event_data,
                    ]));
                } else {
                    event(resolve("App\\Events\\$this->call_event_on_finish", [
                        'userId' => $this->activity->causer_id,
                    ]));
                }
            } catch (\Throwable $e) {
                Log::error('Error calling event: '.$e->getMessage());
            }
        }
        if ($processResult->exitCode() != 0 && ! $this->ignore_errors) {
            throw new \RuntimeException($processResult->errorOutput(), $processResult->exitCode());
        }

        return $processResult;
    }

    protected function getCommand(): string
    {
        $server_uuid = $this->activity->getExtraProperty('server_uuid');
        $command = $this->activity->getExtraProperty('command');
        $server = Server::whereUuid($server_uuid)->firstOrFail();

        return SshMultiplexingHelper::generateSshCommand($server, $command);
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
        $outputStack = json_decode($this->activity->description, associative: true, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $outputStack[] = [
            'type' => $type,
            'output' => $output,
            'timestamp' => hrtime(true),
            'batch' => ApplicationDeploymentJob::$batch_counter,
            'order' => $this->getLatestCounter(),
        ];

        return json_encode($outputStack, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    protected function getLatestCounter(): int
    {
        $description = json_decode($this->activity->description, associative: true, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
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
