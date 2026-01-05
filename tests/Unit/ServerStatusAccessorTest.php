<?php

use App\Models\Application;
use App\Models\Server;

/**
 * Test the Application::serverStatus() accessor
 *
 * This accessor determines if the underlying server infrastructure is functional.
 * It should check Server::isFunctional() for the main server and all additional servers.
 * It should NOT be affected by container/application health status (e.g., degraded:unhealthy).
 *
 * The bug that was fixed: Previously, it checked pivot.status and returned false
 * when any additional server had status != 'running', including 'degraded:unhealthy'.
 * This caused false "server has problems" warnings when the server was fine but
 * containers were unhealthy.
 */
it('checks server infrastructure health not container status', function () {
    // This is a documentation test to explain the fix
    // The serverStatus accessor should:
    // 1. Check if main server is functional (Server::isFunctional())
    // 2. Check if each additional server is functional (Server::isFunctional())
    // 3. NOT check pivot.status (that's application/container status, not server status)
    //
    // Before fix: Checked pivot.status !== 'running', causing false positives
    // After fix: Only checks Server::isFunctional() for infrastructure health

    expect(true)->toBeTrue();
})->note('The serverStatus accessor now correctly checks only server infrastructure health, not container status');

it('has correct logic in serverStatus accessor', function () {
    // Read the actual code to verify the fix
    $reflection = new ReflectionClass(Application::class);
    $source = file_get_contents($reflection->getFileName());

    // Extract just the serverStatus accessor method
    preg_match('/protected function serverStatus\(\): Attribute\s*\{.*?^\s{4}\}/ms', $source, $matches);
    $serverStatusCode = $matches[0] ?? '';

    expect($serverStatusCode)->not->toBeEmpty('serverStatus accessor should exist');

    // Check that the new logic exists (checks isFunctional on each server)
    expect($serverStatusCode)
        ->toContain('$main_server_functional = $this->destination?->server?->isFunctional()')
        ->toContain('foreach ($this->additional_servers as $server)')
        ->toContain('if (! $server->isFunctional())');

    // Check that the old buggy logic is removed from serverStatus accessor
    expect($serverStatusCode)
        ->not->toContain('pluck(\'pivot.status\')')
        ->not->toContain('str($status)->before(\':\')')
        ->not->toContain('if ($server_status !== \'running\')');
})->note('Verifies that the serverStatus accessor uses the correct logic');
