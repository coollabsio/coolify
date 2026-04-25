<?php

use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);

it('stores encrypted http basic auth password longer than 31 characters', function () {
    $longPassword = str_repeat('a', 64);
    $encrypted = Crypt::encryptString($longPassword);

    $columnType = Schema::getColumnType('applications', 'http_basic_auth_password');
    expect($columnType)->toBe('text');
    expect(strlen($encrypted))->toBeGreaterThan(255);
});
