<?php

namespace App\Actions\CoolifyTask;

use App\Enums\ProcessStatus;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use Illuminate\Process\ProcessResult;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;

const TIMEOUT = 3600;
const IDLE_TIMEOUT = 3600;

class RunRemoteProcessNew
{
    protected Application $application;
    protected $time_start;
    protected $current_time;
    protected $last_write_at = 0;
    protected $throttle_interval_ms = 500;
    protected int $counter = 1;

    public function __construct(
        public ApplicationDeploymentQueue $application_deployment_queue,
        public bool $hide_from_output = false,
        public bool $is_finished = false,
        public bool $ignore_errors = false
    ) {
        $this->application = Application::find($application_deployment_queue->application_id)->get();
    }

    public function __invoke(): ProcessResult
    {
        $this->time_start = hrtime(true);

        $status = ProcessStatus::IN_PROGRESS;

        $processResult = Process::timeout(TIMEOUT)->idleTimeout(IDLE_TIMEOUT)->run($this->getCommand(), $this->handleOutput(...));

        if ($this->application_deployment_queue->properties->get('status') === ProcessStatus::ERROR->value) {
            $status = ProcessStatus::ERROR;
        } else {
            if (($processResult->exitCode() == 0 && $this->is_finished) || $this->application_deployment_queue->properties->get('status') === ProcessStatus::FINISHED->value) {
                $status = ProcessStatus::FINISHED;
            }
            if ($processResult->exitCode() != 0 && !$this->ignore_errors) {
                $status = ProcessStatus::ERROR;
            }
        }

        $this->application_deployment_queue->properties = $this->application_deployment_queue->properties->merge([
            'exitCode' => $processResult->exitCode(),
            'stdout' => $processResult->output(),
            'stderr' => $processResult->errorOutput(),
            'status' => $status->value,
        ]);
        $this->application_deployment_queue->save();

        if ($processResult->exitCode() != 0 && !$this->ignore_errors) {
            throw new \RuntimeException($processResult->errorOutput());
        }

        return $processResult;
    }

    protected function getLatestCounter(): int
    {
        $description = json_decode($this->application_deployment_queue->description, associative: true, flags: JSON_THROW_ON_ERROR);
        if ($description === null || count($description) === 0) {
            return 1;
        }
        return end($description)['order'] + 1;
    }

    protected function getCommand(): string
    {
        $user = data_get($this->application_deployment_queue, 'properties.user');
        $server_ip = data_get($this->application_deployment_queue, 'properties.server_ip');
        $private_key_location = data_get($this->application_deployment_queue, 'properties.private_key_location');
        $port = data_get($this->application_deployment_queue, 'properties.port');
        $command = data_get($this->application_deployment_queue, 'properties.command');

        return generate_ssh_command($private_key_location, $server_ip, $user, $port, $command);
    }

    protected function handleOutput(string $type, string $output)
    {
        if ($this->hide_from_output) {
            return;
        }
        $this->current_time = $this->elapsedTime();
        $this->application_deployment_queue->log = $this->encodeOutput($type, $output);

        if ($this->isAfterLastThrottle()) {
            // Let's write to database.
            DB::transaction(function () {
                $this->application_deployment_queue->save();
                $this->last_write_at = $this->current_time;
            });
        }
    }

    public function encodeOutput($type, $output)
    {
        $outputStack = json_decode($this->application_deployment_queue->description, associative: true, flags: JSON_THROW_ON_ERROR);

        $outputStack[] = [
            'type' => $type,
            'output' => $output,
            'timestamp' => hrtime(true),
            'batch' => ApplicationDeploymentJob::$batch_counter,
            'order' => $this->getLatestCounter(),
        ];

        return json_encode($outputStack, flags: JSON_THROW_ON_ERROR);
    }

    public static function decodeOutput(?ApplicationDeploymentQueue $application_deployment_queue = null): string
    {
        if (is_null($application_deployment_queue)) {
            return '';
        }

        try {
            $decoded = json_decode(
                data_get($application_deployment_queue, 'description'),
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

    protected function elapsedTime(): int
    {
        $timeMs = (hrtime(true) - $this->time_start) / 1_000_000;

        return intval($timeMs);
    }
}
