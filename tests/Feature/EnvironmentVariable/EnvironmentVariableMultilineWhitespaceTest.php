<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create(['environment_id' => $environment->id]);
});

function createVariable(Application $application, string $value, bool $isMultiline = false): EnvironmentVariable
{
    $env = $application->environment_variables()->updateOrCreate(['key' => 'TEST_VAR'], [
        'value' => $value,
        'is_multiline' => $isMultiline,
        'is_literal' => true,
        'resourceable_id' => $application->id,
        'resourceable_type' => $application->getMorphClass(),
    ]);

    return $env->refresh();
}

it('keeps the trailing newline of a multiline value such as a private key', function () {
    $key = "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXktdjEAAAAA\n-----END OPENSSH PRIVATE KEY-----\n";

    $env = createVariable($this->application, $key, isMultiline: true);

    expect($env->value)->toBe($key)
        ->and($env->get_real_environment_variables_with_server($env->value, $this->application))->toBe($key);
});

it('keeps the edge whitespace of multiline content exactly', function () {
    $value = "  indented: true\nnext: line\n\n";

    expect(createVariable($this->application, $value)->value)->toBe($value);
});

it('still trims single-line values, also when they end with a newline', function () {
    expect(createVariable($this->application, '  value  ')->value)->toBe('value')
        ->and(createVariable($this->application, "value\n")->value)->toBe('value')
        ->and(createVariable($this->application, "\n  value \r\n")->value)->toBe('value');
});
