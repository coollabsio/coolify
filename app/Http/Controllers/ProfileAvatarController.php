<?php

namespace App\Http\Controllers;

use App\Services\AvatarStorageService;
use Illuminate\Http\Response;

class ProfileAvatarController extends Controller
{
    public function __invoke(AvatarStorageService $avatarStorage): Response
    {
        $contents = $avatarStorage->contents(auth()->user());
        abort_if($contents === null, 404);

        return response($contents, 200, [
            'Content-Type' => 'image/jpeg',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }
}
