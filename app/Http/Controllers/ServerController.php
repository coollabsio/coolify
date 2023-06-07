<?php

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use App\Models\Server;

class ServerController extends Controller
{
    public function all()
    {
        return view('server.all', [
            'servers' => Server::ownedByCurrentTeam()->get()
        ]);
    }
    public function create()
    {
        return view('server.create', [
            'private_keys' => PrivateKey::ownedByCurrentTeam()->get(),
        ]);
    }
    public function show()
    {
        return view('server.show', [
            'server' => Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail(),
        ]);
    }
    public function proxy()
    {
        return view('server.proxy', [
            'server' => Server::ownedByCurrentTeam()->whereUuid(request()->server_uuid)->firstOrFail(),
        ]);
    }
}
