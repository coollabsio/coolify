<?php

use App\Models\ScheduledDatabaseBackup;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
    $this->postgres = createBrowserPostgresql($this->stack, [
        'uuid' => 'db-browser-backup-pg',
        'name' => 'Backup Postgres',
    ]);
    $this->backup = ScheduledDatabaseBackup::create([
        'frequency' => '0 0 * * *',
        'save_s3' => false,
        'dump_all' => false,
        'databases_to_backup' => 'postgres,app',
        'database_type' => $this->postgres->getMorphClass(),
        'database_id' => $this->postgres->id,
        'team_id' => 0,
    ]);
});

it('saves a removed database from the databases to back up', function () {
    loginAndSkipBoarding();

    $base = databaseConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->postgres);

    visit("{$base}/backups/{$this->backup->uuid}")
        ->assertSee('Databases to back up')
        ->click('[aria-label="Remove postgres"]')
        ->click('Save changes')
        ->assertSee('Backup updated successfully.')
        ->screenshot(filename: 'database-backup-remove-database');

    expect($this->backup->fresh()->databases_to_backup)->toBe('app');
});

it('saves an added database to the databases to back up', function () {
    loginAndSkipBoarding();

    $base = databaseConfigurationUrl($this->stack['project'], $this->stack['environment'], $this->postgres);

    visit("{$base}/backups/{$this->backup->uuid}")
        ->assertSee('Databases to back up')
        ->fill('[placeholder="Type a database and press Enter"]', 'analytics')
        ->keys('[placeholder="Type a database and press Enter"]', 'Enter')
        ->assertSee('Backup updated successfully.')
        ->screenshot(filename: 'database-backup-add-database');

    expect($this->backup->fresh()->databases_to_backup)->toBe('postgres,app,analytics');
});
