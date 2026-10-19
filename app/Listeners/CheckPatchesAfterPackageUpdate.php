<?php

namespace App\Listeners;

use App\Events\ServerPackagesUpdateFinished;
use App\Jobs\ServerPatchCheckJob;
use App\Models\Server;

class CheckPatchesAfterPackageUpdate
{
    public function handle(ServerPackagesUpdateFinished $event): void
    {
        $server = Server::query()->where('uuid', $event->data['server_uuid'] ?? null)->first();
        if ($server === null) {
            return;
        }

        ServerPatchCheckJob::dispatchManual($server);
    }
}
