<?php

namespace App\Http\Controllers;

use App\Models\Project;
use App\Models\Server;
use Inertia\Inertia;

class InertiaController extends Controller
{
    public function dashboard()
    {
        $servers = Server::isUsable()->get();

        $destinations = collect($servers)->flatMap(function ($server) {
            return $server->destinations();
        });
        $destinations = $destinations->map(function ($destination) {
            return [
                'name' => $destination->name,
                'description' => $destination->description,
                'uuid' => $destination->uuid,
                'type' => get_class($destination),
            ];
        });
        $servers = $servers->map(function ($server) {
            return [
                'name' => $server->name,
                'description' => $server->description,
                'uuid' => $server->uuid,
            ];
        });

        return Inertia::render('Dashboard', [
            'projects' => Project::ownedByCurrentTeam()->orderBy('created_at')->get(['name', 'description', 'uuid']),
            // Should not add proxy
            'servers' => $servers,
            'sources' => currentTeam()->sources(),
            'destinations' => $destinations,
        ]);
    }

    public function projects()
    {
        return Inertia::render('Projects', [
            'projects' => Project::ownedByCurrentTeam()->orderBy('created_at')->get(['name', 'description', 'uuid']),
        ]);
    }
}
