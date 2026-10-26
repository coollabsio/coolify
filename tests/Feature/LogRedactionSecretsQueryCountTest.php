<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function makeLogRedactionQueryFixture(int $plainVariables, int $sharedVariables): Application
{
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    for ($i = 0; $i < $plainVariables; $i++) {
        EnvironmentVariable::query()->create([
            'key' => "PLAIN_{$i}",
            'value' => "plain-value-number-{$i}",
            'resourceable_type' => $application->getMorphClass(),
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);
    }
    for ($i = 0; $i < $sharedVariables; $i++) {
        SharedEnvironmentVariable::query()->create([
            'key' => "SHARED_{$i}",
            'value' => "shared-value-number-{$i}",
            'type' => 'team',
            'team_id' => $team->id,
            'is_shown_once' => $i % 2 === 0,
        ]);
        EnvironmentVariable::query()->create([
            'key' => "REF_{$i}",
            'value' => "{{team.SHARED_{$i}}}",
            'resourceable_type' => $application->getMorphClass(),
            'resourceable_id' => $application->id,
            'is_preview' => false,
        ]);
    }

    return Application::query()->findOrFail($application->id);
}

function countLogRedactionQueries(Application $application): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    EnvironmentVariable::logRedactionSecretsFor($application, includePreview: true);
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('collects log redaction secrets with a query count that does not grow with plain variables', function () {
    $small = countLogRedactionQueries(makeLogRedactionQueryFixture(plainVariables: 2, sharedVariables: 0));
    $large = countLogRedactionQueries(makeLogRedactionQueryFixture(plainVariables: 40, sharedVariables: 0));

    expect($large)->toBe($small);
});

it('collects log redaction secrets with a query count that does not grow with shared references', function () {
    $small = countLogRedactionQueries(makeLogRedactionQueryFixture(plainVariables: 0, sharedVariables: 2));
    $large = countLogRedactionQueries(makeLogRedactionQueryFixture(plainVariables: 0, sharedVariables: 20));

    expect($large)->toBe($small);
});

it('still classifies plain, shared, and locked shared values correctly', function () {
    $application = makeLogRedactionQueryFixture(plainVariables: 1, sharedVariables: 2);

    $secrets = EnvironmentVariable::logRedactionSecretsFor($application);

    expect($secrets['known'])->toContain('plain-value-number-0', 'shared-value-number-1')
        ->and($secrets['locked'])->toContain('shared-value-number-0')
        ->and($secrets['locked'])->not->toContain('shared-value-number-1');
});

it('does not create a circular relation on the resource', function () {
    $application = makeLogRedactionQueryFixture(plainVariables: 2, sharedVariables: 1);

    EnvironmentVariable::logRedactionSecretsFor($application);

    expect($application->environment_variables->every(fn (EnvironmentVariable $variable): bool => ! $variable->relationLoaded('resourceable')))->toBeTrue()
        ->and(json_encode($application->toArray()))->toBeString();
});

it('indexes application settings by application', function () {
    expect(Schema::hasIndex('application_settings', ['application_id']))->toBeTrue();
});
