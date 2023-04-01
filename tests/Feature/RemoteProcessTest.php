<?php

use App\Actions\RemoteProcess\RunRemoteProcess;
use App\Actions\RemoteProcess\TidyOutput;
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

    $activity = remoteProcess([
        'pwd',
        'x=1; while  [ $x -le 3 ]; do sleep 0.1 && echo "Welcome $x times" $(( x++ )); done',
    ], $host);


    preg_match(RunRemoteProcess::MARK_REGEX, $activity->description, $matchesInRawContent);
    $out = (new TidyOutput($activity))();
    preg_match(RunRemoteProcess::MARK_REGEX, $out, $matchesInTidyOutput);

    expect($matchesInRawContent)
        ->not()->toBeEmpty()
        ->and($matchesInTidyOutput)
        ->toBeEmpty();

});
