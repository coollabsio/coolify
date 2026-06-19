<?php

namespace App\Http\Middleware\V5;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'v5.app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => $request->user() ? [
                    'id' => $request->user()->id,
                    'name' => $request->user()->name,
                    'email' => $request->user()->email,
                ] : null,
            ],
            'currentTeam' => $request->attributes->get('v5.currentTeam') ? [
                'id' => $request->attributes->get('v5.currentTeam')->id,
            ] : null,
        ];
    }
}
