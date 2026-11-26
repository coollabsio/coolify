<?php

use Database\Seeders\PopulateSshKeysDirectorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('ssh-keys');
    Storage::fake('ssh-mux');
    Process::fake();
});

it('gives the ssh directories to the php-fpm user in development', function () {
    config()->set('app.env', 'local');

    app(PopulateSshKeysDirectorySeeder::class)->run();

    Process::assertRan('chown -R www-data:www-data '.storage_path('app/ssh/keys'));
    Process::assertRan('chown -R www-data:www-data '.storage_path('app/ssh/mux'));
    Process::assertDidntRun(fn ($process) => str_contains($process->command, '9999'));
});

it('gives the ssh directories to uid 9999 in production', function () {
    config()->set('app.env', 'production');

    app(PopulateSshKeysDirectorySeeder::class)->run();

    Process::assertRan('chown -R 9999:root '.storage_path('app/ssh/keys'));
    Process::assertRan('chown -R 9999:root '.storage_path('app/ssh/mux'));
});
