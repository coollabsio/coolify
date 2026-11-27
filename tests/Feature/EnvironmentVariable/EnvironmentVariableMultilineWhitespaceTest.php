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

it('keeps the edge spaces of a single-line value, such as a password (#4734)', function () {
    $env = createVariable($this->application, ' pass word ');

    expect($env->value)->toBe(' pass word ')
        ->and($env->get_real_environment_variables_with_server($env->value, $this->application))->toBe(' pass word ')
        ->and(createVariable($this->application, "\tvalue\t")->value)->toBe("\tvalue\t");
});

it('removes only the edge line breaks of a single-line value', function () {
    expect(createVariable($this->application, "value\n")->value)->toBe('value')
        ->and(createVariable($this->application, "\n  value \r\n")->value)->toBe('  value ');
});

it('writes a value with edge spaces in quotes to the env file, also for a legacy variable', function () {
    $this->application->environment_variables()->create(['key' => 'PASSWORD', 'value' => ' secret']);
    $variable = $this->application->environment_variables()->where('key', 'PASSWORD')->firstOrFail();
    $variable->uses_legacy_escaping = true;
    $variable->save();

    expect($this->application->useExactEscaping($variable->fresh()))->toBeTrue()
        ->and(escapeComposeEnvFileValue($variable->fresh()->value))->toBe('" secret"');
});
