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
        if (!is_cloud()) {
            return view('server.create', [
                'limit_reached' => false,
                'private_keys' => PrivateKey::ownedByCurrentTeam()->get(),
            ]);
        }
        $servers = auth()->user()->currentTeam()->servers->count();
        $subscription = auth()->user()->currentTeam()?->subscription->type();
        $limits = config('constants.limits.server')[strtolower($subscription)];
        $limit_reached = true ?? $servers >= $limits[$subscription];

        return view('server.create', [
            'limit_reached' => $limit_reached,
            'private_keys' => PrivateKey::ownedByCurrentTeam()->get(),
        ]);
    }
}
