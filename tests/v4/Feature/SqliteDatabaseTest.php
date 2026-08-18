<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('uses sqlite for testing', function () {
    expect(DB::connection()->getDriverName())->toBe('sqlite');
});

it('runs migrations successfully', function () {
    expect(Schema::hasTable('users'))->toBeTrue();
    expect(Schema::hasTable('teams'))->toBeTrue();
    expect(Schema::hasTable('servers'))->toBeTrue();
    expect(Schema::hasTable('applications'))->toBeTrue();
});
