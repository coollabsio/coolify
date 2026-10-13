<?php

namespace App\Actions\CoolifyTask;

use App\Enums\ActivityTypes;
use App\Enums\ProcessStatus;
use App\Helpers\SshMultiplexingHelper;
use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Services\Security\SensitiveDataRedactor;
use App\Support\DatabaseImport\DatabaseImportCleanup;
use App\Support\RemoteProcessCommand;
use App\Support\ResourceStartActivity;
use App\Traits\BroadcastsToTeam;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;
use Throwable;

class RunRemoteProcess
{
    /**
     * A private key block that has no END line yet.
     */
    private const UNFINISHED_PRIVATE_KEY_PATTERN = '/-----BEGIN [A-Z ]*PRIVATE KEY-----(?![\s\S]*-----END [A-Z ]*PRIVATE KEY-----)[\s\S]*/';

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
     * Output of each stream that is not redacted yet, because its line or private key block is not complete.
     *
     * @var array<string, string>
     */
    private array $pendingOutput = [];

    /**
     * Environment values of the activity's resource to hide in its output, resolved once.
     * False when resolving them failed, so the output is fully redacted.
     *
     * @var array{known: list<string>, locked: list<string>}|false|null
     */
    private array|false|null $logSecrets = null;

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
        try {
            $process = Process::timeout($timeout)->start($this->getCommand(), $this->handleOutput(...));
            // Start from the stored properties and save now, so later output writes only change the log and
            // never write back an old copy over a status that Coolify set meanwhile (for example a stopped import).
            $stored = Activity::query()->whereKey($this->activity->getKey())->first()?->properties ?? $this->activity->properties;
            $this->activity->properties = $stored->merge([
                'process_id' => $process->id(),
            ]);
            $this->activity->save();

            $processResult = $process->wait();
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            // The timeout message contains the full SSH command, which can contain secrets.
            throw new \RuntimeException("The command on the server timed out after {$timeout} seconds.", 124);
        } finally {
            $this->flushPendingOutput();
        }

        // A database import whose SSH command ended without the restore result keeps blocking the
        // database until its restore is stopped. The stop event also cleans up, so the normal
        // finish event must not run first.
        if (array_key_exists($processResult->exitCode(), DatabaseImportCleanup::TRANSPORT_FAILURE_REASONS)
            && $this->activity->properties->get('operation') === ResourceStartActivity::DATABASE_IMPORT_OPERATION
            && blank(Activity::query()->whereKey($this->activity->getKey())->first()?->properties?->get(DatabaseImportCleanup::STOP_REQUESTED_PROPERTY))
            && DatabaseImportCleanup::stopAfterTransportFailure($this->activity, $processResult->exitCode())) {
            $this->activity->properties = $this->activity->properties->merge([
                'exitCode' => $processResult->exitCode(),
                'stdout' => $this->redact($processResult->output()),
                'stderr' => $this->redact($processResult->errorOutput()),
            ]);
            $this->activity->save();

            if (! $this->ignore_errors) {
                throw new \RuntimeException($this->redact($processResult->errorOutput()), $processResult->exitCode());
            }

            return $processResult;
        }

        if ($this->activity->properties->get('status') === ProcessStatus::ERROR->value) {
            $status = ProcessStatus::ERROR;
        } else {
            if ($processResult->exitCode() == 0) {
                $status = ProcessStatus::FINISHED;
            } else {
                $status = ProcessStatus::ERROR;
            }
        }

        $properties = [
            'exitCode' => $processResult->exitCode(),
            'stdout' => $this->redact($processResult->output()),
            'stderr' => $this->redact($processResult->errorOutput()),
            'status' => $status->value,
        ];

        // Coolify can stop a database import while this job runs (restart or stale import). Keep the
        // stop status and message, because this job only has its own older copy of the properties.
        $stored = Activity::query()->whereKey($this->activity->getKey())->first()?->properties;
        if ($stored !== null && filled($stored->get(DatabaseImportCleanup::STOP_REQUESTED_PROPERTY))) {
            $properties = [
                ...$properties,
                ...$stored->only([DatabaseImportCleanup::STOP_REQUESTED_PROPERTY, 'error'])->all(),
                'status' => ProcessStatus::ERROR->value,
            ];
        }

        $this->activity->properties = $this->activity->properties->merge($properties);
        $this->activity->save();
        if ($this->call_event_on_finish) {
            try {
                self::dispatchFinishEvent($this->activity, $this->call_event_on_finish, $this->call_event_data);
            } catch (Throwable $e) {
                Log::error('Error calling event: '.$e->getMessage());
            }
        }
        if ($processResult->exitCode() != 0 && ! $this->ignore_errors) {
            throw new \RuntimeException($this->redact($processResult->errorOutput()), $processResult->exitCode());
        }

        return $processResult;
    }

    /**
     * Dispatches the event that a remote process asked for when it finishes.
     *
     * Without explicit event data, a team event goes to the team of the server that ran the
     * process. The causer is a user, so its id is only used for events on a user channel.
     */
    public static function dispatchFinishEvent(Activity $activity, string $eventName, mixed $eventData = null): void
    {
        $eventClass = "App\\Events\\{$eventName}";

        if (is_null($eventData)) {
            $eventData = in_array(BroadcastsToTeam::class, class_uses_recursive($eventClass), true)
                ? $activity->getExtraProperty('team_id')
                : $activity->causer_id;
        }

        event(new $eventClass($eventData));
    }

    protected function getCommand(): string
    {
        $server_uuid = $this->activity->getExtraProperty('server_uuid');
        $command = RemoteProcessCommand::read($this->activity) ?? throw new \RuntimeException('The command of this task was already removed.');
        $server = Server::whereUuid($server_uuid)->firstOrFail();

        return SshMultiplexingHelper::generateSshCommand($server, $command);
    }

    protected function handleOutput(string $type, string $output)
    {
        if ($this->hide_from_output) {
            return;
        }
        $this->pendingOutput[$type] = ($this->pendingOutput[$type] ?? '').$output;
        $output = self::takeCompleteOutput($this->pendingOutput[$type]);
        if ($output === '') {
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

    /**
     * Write the output that is still buffered when the process ends, also after a timeout or failure.
     */
    private function flushPendingOutput(): void
    {
        $written = false;
        foreach ($this->pendingOutput as $type => $pending) {
            $output = self::takeCompleteOutput($this->pendingOutput[$type], final: true);
            if ($output !== '') {
                $this->activity->description = $this->encodeOutput($type, $output);
                $written = true;
            }
        }
        if ($written) {
            $this->activity->save();
        }
    }

    /**
     * Take the complete lines from the buffered output of a stream, so a secret split across output
     * chunks is redacted as a whole. A private key block stays buffered until its END line. With
     * $final, all buffered output is taken, and an unfinished private key block is hidden.
     */
    public static function takeCompleteOutput(string &$pending, bool $final = false): string
    {
        if ($final) {
            $output = preg_replace(self::UNFINISHED_PRIVATE_KEY_PATTERN, REDACTED, $pending) ?? REDACTED;
            $pending = '';

            return $output;
        }

        $end = max(strrpos($pending, "\n"), strrpos($pending, "\r"));
        if ($end === false) {
            return '';
        }
        $output = substr($pending, 0, $end + 1);
        if (preg_match(self::UNFINISHED_PRIVATE_KEY_PATTERN, $output, $match, PREG_OFFSET_CAPTURE) === 1) {
            $output = substr($output, 0, $match[0][1]);
        }
        $pending = substr($pending, strlen($output));

        return $output;
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
            'output' => $this->redact($output),
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

    private function redact(string $value): string
    {
        $this->logSecrets ??= $this->resolveLogSecrets();
        if ($this->logSecrets === false) {
            return REDACTED;
        }

        return resolve(SensitiveDataRedactor::class)->redactText($value, $this->logSecrets['known'], $this->logSecrets['locked']);
    }

    /**
     * The environment values of the application or service this activity runs for, following
     * the resource's log redaction setting.
     *
     * @return array{known: list<string>, locked: list<string>}|false
     */
    private function resolveLogSecrets(): array|false
    {
        try {
            return EnvironmentVariable::logRedactionSecretsFor($this->logResource());
        } catch (Throwable) {
            return false;
        }
    }

    private function logResource(): Application|Service|null
    {
        $subject = $this->activity->subject_type !== null ? $this->activity->subject : null;
        if ($subject instanceof ServiceApplication || $subject instanceof ServiceDatabase) {
            return $subject->service;
        }
        if ($subject instanceof Application || $subject instanceof Service) {
            return $subject;
        }

        $typeUuid = $this->activity->getExtraProperty('type_uuid');
        if (! is_string($typeUuid) || $typeUuid === '') {
            return null;
        }

        return Service::query()->where('uuid', $typeUuid)->first()
            ?? Application::query()->where('uuid', $typeUuid)->first();
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
