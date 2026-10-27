<?php

namespace App\Services;

use App\Enums\ProcessStatus;
use App\Helpers\SshMultiplexingHelper;
use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Process\Exceptions\ProcessTimedOutException;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;
use Symfony\Component\Process\Exception\ProcessTimedOutException as SymfonyProcessTimedOutException;

class DatabaseStartCommandExecutor
{
    public function execute(array $commands, Model $database, Activity $activity): Activity
    {
        $server = $database->destination->server;
        if ($server->isNonRoot()) {
            $commands = parseCommandsByLineForSudo(collect($commands), $server);
        }

        $secrets = method_exists($database, 'resolvedSecretManagerValuesForRedaction')
            ? $database->resolvedSecretManagerValuesForRedaction()
            : [];
        $remoteCommand = SshMultiplexingHelper::generateSshCommand($server, implode("\n", $commands));

        $activity->properties = $activity->properties->merge(['status' => ProcessStatus::IN_PROGRESS->value]);
        $activity->save();

        $timeout = config('constants.ssh.command_timeout');
        // Output is redacted per complete line, so a secret split across two output chunks is still found.
        $pending = ['stdout' => '', 'stderr' => ''];
        try {
            $process = Process::timeout($timeout)
                ->idleTimeout(3600)
                ->start($remoteCommand, function (string $type, string $output) use ($activity, $secrets, &$pending): void {
                    $pending[$type] = ($pending[$type] ?? '').$output;
                    $end = max(strrpos($pending[$type], "\n"), strrpos($pending[$type], "\r"));
                    if ($end === false) {
                        return;
                    }
                    $this->appendOutput($activity, $type, $this->redact(substr($pending[$type], 0, $end + 1), $secrets));
                    $pending[$type] = substr($pending[$type], $end + 1);
                });

            $result = $process->wait();
        } catch (ProcessTimedOutException|SymfonyProcessTimedOutException) {
            // The timeout message contains the full SSH command, which can contain secrets.
            throw new \RuntimeException("The command on the server timed out after {$timeout} seconds.", 124);
        } finally {
            foreach ($pending as $type => $output) {
                $this->appendOutput($activity, $type, $this->redact($output, $secrets));
            }
        }
        $status = $result->successful() ? ProcessStatus::FINISHED : ProcessStatus::ERROR;
        $activity->properties = $activity->properties->merge([
            'status' => $status->value,
            'exitCode' => $result->exitCode(),
        ]);
        $activity->save();

        if (! $result->successful()) {
            throw new \RuntimeException($this->redact($result->errorOutput(), $secrets), $result->exitCode());
        }

        return $activity;
    }

    /**
     * @param  array<array-key, mixed>  $secrets
     */
    private function redact(string $value, array $secrets): string
    {
        return sanitize_utf8_text(resolve(SensitiveDataRedactor::class)->redactText($value, lockedSecrets: $secrets));
    }

    private function appendOutput(Activity $activity, string $type, string $output): void
    {
        if ($output === '') {
            return;
        }

        $entries = json_decode($activity->description ?: '[]', true, flags: JSON_THROW_ON_ERROR);
        $entries[] = [
            'type' => $type,
            'output' => $output,
            'timestamp' => hrtime(true),
            'batch' => 1,
            'order' => count($entries) + 1,
        ];
        $activity->description = json_encode($entries, flags: JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        $activity->save();
    }
}
