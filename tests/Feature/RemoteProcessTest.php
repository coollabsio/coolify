<?php

use App\Actions\CoolifyTask\RunRemoteProcess;
use App\Models\Server;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
uses(DatabaseMigrations::class);

beforeEach(function () {
    $this->seed(DatabaseSeeder::class);
});

it('outputs correctly', function () {

    $host = Server::where('name', 'testing-local-docker-container')->first();

    $activity = remote_process([
        'pwd',
        'x=1; while  [ $x -le 3 ]; do sleep 0.1 && echo "Welcome $x times" $(( x++ )); done',
    ], $host);


    $tidyOutput = RunRemoteProcess::decodeOutput($activity);

    expect($tidyOutput)
        ->toContain('Welcome 1 times')
        ->toContain('Welcome 3 times')
        ->not()->toBeJson();
});
