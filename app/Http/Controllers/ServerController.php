<?php

namespace App\Http\Controllers;

use App\Models\Server;
use Illuminate\Http\Request;

class ServerController extends Controller
{
    public function show(Server $server)
    {
        return view('server.show', [
            'server' => $server,
        ]);
    }
}
