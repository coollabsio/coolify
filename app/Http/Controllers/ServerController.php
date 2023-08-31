<?php

namespace App\Http\Controllers;

use App\Models\PrivateKey;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Validation\ValidatesRequests;

class ServerController extends Controller
{
    use AuthorizesRequests, ValidatesRequests;

    public function new_server()
    {
        $privateKeys = PrivateKey::ownedByCurrentTeam()->get();
        if (!isCloud()) {
            return view('server.create', [
                'limit_reached' => false,
                'private_keys' => $privateKeys,
            ]);
        }
        $team = currentTeam();
        $servers = $team->servers->count();
        ['serverLimit' => $serverLimit] = $team->limits;
        $limit_reached = $servers >= $serverLimit;

        return view('server.create', [
            'limit_reached' => $limit_reached,
            'private_keys' => $privateKeys,
        ]);
    }
}
