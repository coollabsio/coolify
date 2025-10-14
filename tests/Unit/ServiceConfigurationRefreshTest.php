<?php

/**
 * Unit tests to verify that Configuration component properly listens to
 * refresh events dispatched when compose file or domain changes.
 *
 * These tests verify the fix for the issue where changes to compose or domain
 * were not visible until manual page refresh.
 */
it('ensures Configuration component listens to refreshServices event', function () {
    $configurationFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/Configuration.php');

    // Check that the Configuration component has refreshServices listener
    expect($configurationFile)
        ->toContain("'refreshServices' => 'refreshServices'")
        ->toContain("'refresh' => 'refreshServices'");
});

it('ensures Configuration component has refreshServices method', function () {
    $configurationFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/Configuration.php');

    // Check that the refreshServices method exists
    expect($configurationFile)
        ->toContain('public function refreshServices()')
        ->toContain('$this->service->refresh()')
        ->toContain('$this->applications = $this->service->applications->sort()')
        ->toContain('$this->databases = $this->service->databases->sort()');
});

it('ensures StackForm dispatches refreshServices event on submit', function () {
    $stackFormFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/StackForm.php');

    // Check that StackForm dispatches refreshServices event
    expect($stackFormFile)
        ->toContain("->dispatch('refreshServices')");
});

it('ensures EditDomain dispatches refreshServices event on submit', function () {
    $editDomainFile = file_get_contents(__DIR__.'/../../app/Livewire/Project/Service/EditDomain.php');

    // Check that EditDomain dispatches refreshServices event
    expect($editDomainFile)
        ->toContain("->dispatch('refreshServices')");
});
