<?php

use App\Models\User;

$email = 'test@example.com';
$user = User::whereEmail($email)->first();
$teams = $user->teams;
foreach ($teams as $team) {
    $servers = $team->servers;
    if ($servers->count() > 0) {
        foreach ($servers as $server) {
            dump($server);
            $server->delete();
        }
    }
    dump($team);
    $team->delete();
}
if ($user) {
    dump($user);
    $user->delete();
}
