<?php

namespace App\Services;

use App\Enums\ProcessStatus;
use App\Helpers\SshMultiplexingHelper;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Process;
use Spatie\Activitylog\Models\Activity;

class DatabaseStartCommandExecutor
{
    public function execute(array $commands, Model $database, Activity $activity): Activity
    {
        $server = $database->destination->server;
        if ($server->isNonRoot()) {
            $commands = parseCommandsByLineForSudo(collect($commands), $server)->all();
        }

        $secrets = method_exists($database, 'resolvedSecretManagerValuesForRedaction')
            ? $database->resolvedSecretManagerValuesForRedaction()
            : [];
        $remoteCommand = SshMultiplexingHelper::generateSshCommand($server, implode("\n", $commands));

        $activity->properties = $activity->properties->merge(['status' => ProcessStatus::IN_PROGRESS->value]);
        $activity->save();

        $process = Process::timeout(config('constants.ssh.command_timeout'))
            ->idleTimeout(3600)
            ->start($remoteCommand, function (string $type, string $output) use ($activity, $secrets): void {
                $this->appendOutput($activity, $type, $this->redact($output, $secrets));
            });

        $result = $process->wait();
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

    private function redact(string $value, array $secrets): string
    {
        foreach ($secrets as $secret) {
            if (is_string($secret) && $secret !== '') {
                $value = str_replace($secret, REDACTED, $value);
            }
        }

        return sanitize_utf8_text(remove_iip($value));
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
