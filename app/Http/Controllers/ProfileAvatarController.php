<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\AvatarStorageService;
use Illuminate\Http\Response;

class ProfileAvatarController extends Controller
{
    public function __invoke(AvatarStorageService $avatarStorage, ?int $user_id = null): Response
    {
        $user = auth()->user();

        if ($user_id !== null && $user_id !== $user->id) {
            $user = User::query()
                ->whereKey($user_id)
                ->whereHas('teams', fn ($query) => $query->whereIn('teams.id', auth()->user()->teams()->select('teams.id')))
                ->firstOrFail();
        }

        $contents = $avatarStorage->contents($user);
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }
}
