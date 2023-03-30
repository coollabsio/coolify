<?php

use App\Actions\RemoteProcess\DispatchRemoteProcess;
use App\Data\RemoteProcessArgs;
use App\Enums\ActivityTypes;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Contracts\Activity;

if (!function_exists('remoteProcess')) {
    /**
     * Run a Remote Process, which SSH's asynchronously into a machine to run the command(s).
     * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
     *
     */
    function remoteProcess(
        array           $command,
        Server          $server,
        string|null     $deployment_uuid = null,
        Model|null      $model = null,
    ): Activity {
        $command_string = implode("\n", $command);
        // @TODO: Check if the user has access to this server
        // checkTeam($server->team_id);

        $private_key_location = savePrivateKey($server);

        return resolve(DispatchRemoteProcess::class, [
            'remoteProcessArgs' => new RemoteProcessArgs(
                type: $deployment_uuid ? ActivityTypes::DEPLOYMENT->value : ActivityTypes::REMOTE_PROCESS->value,
                model: $model,
                server_ip: $server->ip,
                deployment_uuid: $deployment_uuid,
                private_key_location: $private_key_location,
                command: <<<EOT
                {$command_string}
                EOT,
                port: $server->port,
                user: $server->user,
            ),
        ])();
    }
    // function checkTeam(string $team_id)
    // {
    //     $found_team = auth()->user()->teams->pluck('id')->contains($team_id);
    //     if (!$found_team) {
    //         throw new \RuntimeException('You do not have access to this server.');
    //     }
    // }
}

if (!function_exists('savePrivateKey')) {
    function savePrivateKey(Server $server)
    {
        $temp_file = 'id.rsa_' . 'root' . '@' . $server->ip;
        Storage::disk('local')->put($temp_file, $server->privateKey->private_key, 'private');
        return '/var/www/html/storage/app/' . $temp_file;
    }
}

if (!function_exists('generateSshCommand')) {
    function generateSshCommand(string $private_key_location, string $server_ip, string $user, string $port, $command)
    {
        $delimiter = 'EOF-COOLIFY-SSH';
        Storage::disk('local')->makeDirectory('.ssh');
        $ssh_command = "ssh "
            . "-i {$private_key_location} "
            . '-o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null '
            . '-o PasswordAuthentication=no '
            . '-o RequestTTY=no '
            . '-o LogLevel=ERROR '
            . '-o ControlMaster=auto -o ControlPersist=1m -o ControlPath=/var/www/html/storage/app/.ssh/ssh_mux_%h_%p_%r '
            . "-p {$port} "
            . "{$user}@{$server_ip} "
            . " 'bash -se' << \\$delimiter" . PHP_EOL
            . $command . PHP_EOL
            . $delimiter;
        return $ssh_command;
    }
}
if (!function_exists('formatDockerCmdOutputToJson')) {
    function formatDockerCmdOutputToJson($rawOutput): Collection
    {
        $outputLines = explode(PHP_EOL, $rawOutput);

        return collect($outputLines)
            ->reject(fn ($line) => empty($line))
            ->map(fn ($outputLine) => json_decode($outputLine, true, flags: JSON_THROW_ON_ERROR));
    }
}
if (!function_exists('formatDockerLabelsToJson')) {
    function formatDockerLabelsToJson($rawOutput): Collection
    {
        $outputLines = explode(PHP_EOL, $rawOutput);

        return collect($outputLines)
            ->reject(fn ($line) => empty($line))
            ->map(function ($outputLine) {
                $outputArray = explode(',', $outputLine);
                return collect($outputArray)
                    ->map(function ($outputLine) {
                        return explode('=', $outputLine);
                    })
                    ->mapWithKeys(function ($outputLine) {
                        return [$outputLine[0] => $outputLine[1]];
                    });
            })[0];

    }
}

