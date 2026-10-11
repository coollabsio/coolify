<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use Illuminate\Support\Facades\Auth;

it('returns empty arrays when currentTeam returns null', function () {
    // Mock Auth facade to return null for user
    Auth::shouldReceive('user')
        ->andReturn(null);

    $component = new Add;
    $component->parameters = [];

    $result = $component->availableSharedVariables();

    expect($result)->toBe([
        'team' => [],
        'project' => [],
        'environment' => [],
        'server' => [],
    ]);
});
