<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Add;
use Illuminate\Support\Facades\Auth;

it('has availableSharedVariables computed property', function () {
    $component = new Add;

    // Check that the method exists
    expect(method_exists($component, 'availableSharedVariables'))->toBeTrue();
});

it('component has required properties for environment variable autocomplete', function () {
    $component = new Add;

    expect($component)->toHaveProperty('key')
        ->and($component)->toHaveProperty('value')
        ->and($component)->toHaveProperty('is_multiline')
        ->and($component)->toHaveProperty('is_literal')
        ->and($component)->toHaveProperty('is_runtime')
        ->and($component)->toHaveProperty('is_buildtime')
        ->and($component)->toHaveProperty('parameters');
});

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
    ]);
});

it('availableSharedVariables method wraps authorization checks in try-catch blocks', function () {
    // Read the source code to verify the authorization pattern
    $reflectionMethod = new ReflectionMethod(Add::class, 'availableSharedVariables');
    $source = file_get_contents($reflectionMethod->getFileName());

    // Verify that the method contains authorization checks
    expect($source)->toContain('$this->authorize(\'view\', $team)')
        ->and($source)->toContain('$this->authorize(\'view\', $project)')
        ->and($source)->toContain('$this->authorize(\'view\', $environment)')
        // Verify authorization checks are wrapped in try-catch blocks
        ->and($source)->toContain('} catch (\Illuminate\Auth\Access\AuthorizationException $e) {');
});
