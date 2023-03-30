<?php

use App\Actions\RemoteProcess\DispatchRemoteProcess;
use App\Data\RemoteProcessArgs;
use App\Enums\ActivityTypes;
use App\Models\Server;
use Illuminate\Database\Eloquent\Model;
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

        $temp_file = 'id.rsa_' . 'root' . '@' . $server->ip;
        Storage::disk('local')->put($temp_file, $server->privateKey->private_key, 'private');
        $private_key_location = '/var/www/html/storage/app/' . $temp_file;

        return resolve(DispatchRemoteProcess::class, [
            'remoteProcessArgs' => new RemoteProcessArgs(
                model: $model,
                server_ip: $server->ip,
                private_key_location: $private_key_location,
                deployment_uuid: $deployment_uuid,
                command: <<<EOT
                {$command_string}
                EOT,
                port: $server->port,
                user: $server->user,
                type: $deployment_uuid ? ActivityTypes::DEPLOYMENT->value : ActivityTypes::REMOTE_PROCESS->value,
            ),
        ])();
    }
}

if (!function_exists('checkTeam')) {
    function checkTeam(string $team_id)
    {
        $found_team = auth()->user()->teams->pluck('id')->contains($team_id);
        if (!$found_team) {
            throw new \RuntimeException('You do not have access to this server.');
        }
    }
}
