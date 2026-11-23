<?php

use App\Models\DiscordNotificationSettings;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack([
        'projectUuid' => 'project-resource-persistence',
        'projectName' => 'Resource Persistence',
        'projectDescription' => 'Browser persistence tests',
    ]);

    DiscordNotificationSettings::where('team_id', 0)->update([
        'discord_enabled' => true,
        'discord_webhook_url' => 'https://discord.com/test',
    ]);

    $this->user = $this->stack['user'];
    $this->server = $this->stack['server'];
    $this->project = $this->stack['project'];
    $this->environment = $this->stack['environment'];
    $this->destination = $this->stack['destination'];

    $this->application = createBrowserApplication($this->stack, [
        'uuid' => 'app-resource-persistence',
        'name' => 'App Before Browser Save',
    ]);

    $this->database = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-resource-persistence',
        'name' => 'Database Before Browser Save',
        'description' => 'Initial database description',
    ]);
});

it('saves application name and custom docker run options from general form', function () {
    loginAndSkipBoarding();

    $updatedName = 'App Saved '.(string) new Cuid2;
    $applicationRoute = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/application/{$this->application->uuid}";

    $page = visit($applicationRoute);
    $page->screenshot(filename: 'resource-app-before-save');

    $page->assertSee('General')
        ->fill('name', $updatedName)
        ->fill('description', 'Saved by browser persistence test')
        ->fill('customDockerRunOptions', '--hostname=persist-app --read-only');

    submitLivewireForm($page);

    $page->assertValue('name', $updatedName)
        ->screenshot(filename: 'resource-app-after-save');

    $this->application->refresh();
    expect($this->application->name)->toBe($updatedName)
        ->and($this->application->description)->toBe('Saved by browser persistence test')
        ->and($this->application->custom_docker_run_options)->toBe('--hostname=persist-app --read-only');

    $reloadedPage = visit($applicationRoute);
    $reloadedPage->assertValue('name', $updatedName)
        ->assertValue('customDockerRunOptions', '--hostname=persist-app --read-only')
        ->screenshot(filename: 'resource-app-reloaded');
});

it('saves database name description and docker options from general form', function () {
    loginAndSkipBoarding();

    $updatedDatabaseName = 'Database Saved '.(string) new Cuid2;
    $databaseRoute = "/project/{$this->project->uuid}/environment/{$this->environment->uuid}/database/{$this->database->uuid}";

    $page = visit($databaseRoute);
    $page->screenshot(filename: 'resource-db-before-save');

    $page->assertSee('General')
        ->fill('name', $updatedDatabaseName)
        ->fill('description', 'Updated by browser test')
        ->fill('customDockerRunOptions', '--hostname=persist-db --shm-size=128m');

    submitLivewireForm($page);

    $page->assertValue('name', $updatedDatabaseName)
        ->screenshot(filename: 'resource-db-after-save');

    $this->database->refresh();
    expect($this->database->name)->toBe($updatedDatabaseName)
        ->and($this->database->description)->toBe('Updated by browser test')
        ->and($this->database->custom_docker_run_options)->toBe('--hostname=persist-db --shm-size=128m');

    $compose = convertDockerRunToCompose($this->database->custom_docker_run_options);
    expect(data_get($compose, 'hostname'))->toBe('persist-db')
        ->and(data_get($compose, 'shm_size'))->toBe('128m');

    $reloadedPage = visit($databaseRoute);
    $reloadedPage->assertValue('name', $updatedDatabaseName)
        ->assertValue('description', 'Updated by browser test')
        ->screenshot(filename: 'resource-db-reloaded');
});
