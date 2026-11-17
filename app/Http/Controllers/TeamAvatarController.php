<?php

namespace App\Http\Controllers;

use App\Services\TeamAvatarStorageService;
use Illuminate\Http\Response;

class TeamAvatarController extends Controller
{
    public function __invoke(int $team_id, TeamAvatarStorageService $avatarStorage): Response
    {
        $team = auth()->user()->teams()->whereKey($team_id)->firstOrFail();
        $contents = $avatarStorage->teamContents($team);

        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
