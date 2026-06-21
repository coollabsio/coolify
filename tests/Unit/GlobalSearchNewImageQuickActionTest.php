<?php

/**
 * Unit tests to verify that the "new image" quick action properly matches
 * the docker-image type using the quickcommand field.
 *
 * This test verifies the fix for the issue where typing "new image" would
 * not match because the frontend was only checking name and type fields,
 * not the quickcommand field.
 */
it('ensures GlobalSearch blade template checks quickcommand field in matching logic', function () {
    $bladeFile = file_get_contents(__DIR__.'/../../resources/views/livewire/global-search.blade.php');

    // Check that the matching logic includes quickcommand check
    expect($bladeFile)
        ->toContain('item.quickcommand')
        ->toContain('quickcommand.toLowerCase().includes(trimmed)');
});

it('ensures GlobalSearch clears search query when starting resource creation', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that navigateToResourceCreation clears the search query
    expect($globalSearchFile)
        ->toContain('$this->searchQuery = \'\'');
});

it('ensures GlobalSearch uses Livewire redirect method', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that completeResourceCreation uses $this->redirect()
    expect($globalSearchFile)
        ->toContain('$this->redirect(route(\'project.resource.create\'');
});

it('ensures docker-image item has quickcommand with new image', function () {
    $globalSearchFile = file_get_contents(__DIR__.'/../../app/Livewire/GlobalSearch.php');

    // Check that Docker Image has the correct quickcommand
    expect($globalSearchFile)
        ->toContain("'name' => 'Docker Image'")
        ->toContain("'quickcommand' => '(type: new image)'")
        ->toContain("'type' => 'docker-image'");
});
