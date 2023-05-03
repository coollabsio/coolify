<?php

namespace App\Http\Livewire;

use App\Enums\ActivityTypes;
use App\Models\Server;
use Livewire\Component;

class ForceUpgrade extends Component
{
    public function upgrade()
    {
        //if (env('APP_ENV') === 'local') {
        if (config('app.env') === 'local') {
            $server = Server::where('ip', 'coolify-testing-host')->first();
            if (!$server) {
                return;
            }
            instantRemoteProcess($server, [
                "sleep 2"
            ]);
            remoteProcess([
                "sleep 10"
            ], $server, ActivityTypes::INLINE->value);
            $this->emit('updateInitiated');
        } else {
            $latestVersion = getLatestVersionOfCoolify();

            $cdn = "https://coolify-cdn.b-cdn.net/files";
            $server = Server::where('ip', 'host.docker.internal')->first();
            if (!$server) {
                return;
            }

            instantRemoteProcess($server, [
                "curl -fsSL $cdn/docker-compose.yml -o /data/coolify/source/docker-compose.yml",
                "curl -fsSL $cdn/docker-compose.prod.yml -o /data/coolify/source/docker-compose.prod.yml",
                "curl -fsSL $cdn/.env.production -o /data/coolify/source/.env.production",
                "curl -fsSL $cdn/upgrade.sh -o /data/coolify/source/upgrade.sh",
            ]);
            instantRemoteProcess($server, [
                "docker compose -f /data/coolify/source/docker-compose.yml -f /data/coolify/source/docker-compose.prod.yml pull",
            ]);

            remoteProcess([
                "bash /data/coolify/source/upgrade.sh $latestVersion"
            ], $server, ActivityTypes::INLINE->value);

            $this->emit('updateInitiated');
        }
    }
}
