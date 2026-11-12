<?php

use App\Models\Project;
use App\Models\StandalonePostgresql;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('app.maintenance.store', 'array');
    $this->stack = seedBrowserResourceStack();
    $this->database = createBrowserPostgresql($this->stack, ['uuid' => 'db-clone-volume-data', 'name' => 'Clone Source DB']);
});

it('asks for a confirmation before a resource clone with volume data', function () {
    loginAndSkipBoarding();

    $page = visit(databaseConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->database).'/resource-operations')
        ->assertSee('Clone volume data')
        ->assertSee('The running resource will not be changed.')
        ->click('input[id^="cloneVolumeData-"]')
        ->wait(1)
        ->assertSee('A running resource is stopped while its volume data is copied.')
        ->click('#clone-resource-section button.button:has-text("Clone resource")')
        ->wait(1)
        ->assertSee('Clone this resource with its volume data?')
        ->assertSee('If the resource is running, it is stopped while its volume data is copied.')
        ->screenshot(filename: 'clone-resource-volume-data-confirmation');

    expect(StandalonePostgresql::query()->count())->toBe(1);
});

it('asks for a confirmation before an environment clone with volume data', function () {
    loginAndSkipBoarding();

    $project = $this->stack['project'];
    $environment = $this->stack['environment'];

    visit("/project/{$project->uuid}/environment/{$environment->uuid}/clone")
        ->assertSee('Clone volume data')
        ->click('input[id^="cloneVolumeData-"]')
        ->wait(1)
        ->click('coolify')
        ->wait(1)
        ->click('Clone to environment')
        ->wait(1)
        ->assertSee('Clone with volume data?')
        ->assertSee('Running source resources are stopped while their volume data is copied.')
        ->screenshot(filename: 'clone-environment-volume-data-confirmation');

    expect(Project::query()->count())->toBe(1)
        ->and($environment->project->environments()->count())->toBe(1);
});
