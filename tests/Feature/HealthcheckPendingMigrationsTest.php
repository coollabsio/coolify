<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('reports healthy when every migration ran', function () {
    $this->get('/api/health')->assertOk()->assertSee('OK');
});

it('reports unavailable while a migration is pending', function () {
    DB::table('migrations')->where('migration', '2026_10_25_100000_add_access_grants_to_team_members_and_tokens')->delete();

    $this->get('/api/health')->assertServiceUnavailable();
    $this->get('/api/v1/health')->assertServiceUnavailable();
});

it('reports healthy with pending migrations when this server does not run migrations', function () {
    config(['constants.migration.is_migration_enabled' => false]);
    DB::table('migrations')->where('migration', '2026_10_25_100000_add_access_grants_to_team_members_and_tokens')->delete();

    $this->get('/api/health')->assertOk();
});
