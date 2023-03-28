<?php

use App\Actions\RemoteProcess\DispatchRemoteProcess;
use App\Data\RemoteProcessArgs;
use App\Models\Server;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Contracts\Activity;

if (!function_exists('remoteProcess')) {
    /**
     * Run a Remote Process, which SSH's asynchronously into a machine to run the command(s).
     * @TODO Change 'root' to 'coolify' when it's able to run Docker commands without sudo
     *
     */
    function remoteProcess(
        string    $command,
        string    $destination
    ): Activity {
        $found_server = checkServer($destination);
        checkTeam($found_server->team_id);

        $temp_file = 'id.rsa_' . 'root' . '@' . $found_server->ip;
        Storage::disk('local')->put($temp_file, $found_server->privateKey->private_key, 'private');
        $private_key_location = '/var/www/html/storage/app/' . $temp_file;

        return resolve(DispatchRemoteProcess::class, [
            'remoteProcessArgs' => new RemoteProcessArgs(
                destination: $found_server->ip,
                private_key_location: $private_key_location,
                command: <<<EOT
                {$command}
                EOT,
                port: $found_server->port,
                user: $found_server->user,
            ),
        ])();
    }
    function checkServer(string $destination)
    {
        // @TODO: Use UUID instead of name
        $found_server = Server::where('name', $destination)->first();
        if (!$found_server) {
            throw new \RuntimeException('Server not found.');
        };
        return $found_server;
    }
    function checkTeam(string $team_id)
    {
        $found_team = auth()->user()->teams->pluck('id')->contains($team_id);
        if (!$found_team) {
            throw new \RuntimeException('You do not have access to this server.');
        }
    }
}
