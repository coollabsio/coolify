<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Service;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);
    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $project->id]);
    $this->application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'build_pack' => 'dockercompose',
    ]);
});

function runRemoveDuplicateEnvironmentVariablesMigration(): void
{
    $migration = require database_path('migrations/2026_11_15_140000_remove_duplicate_environment_variables.php');
    $migration->up();
}

function duplicateApplicationVariable(Application $application, string $key, ?string $value, string $updatedAt, bool $isPreview = false): EnvironmentVariable
{
    $variable = insertDuplicateEnvironmentVariable([
        'key' => $key,
        'value' => $value,
        'is_preview' => $isPreview,
        'resourceable_type' => Application::class,
        'resourceable_id' => $application->id,
    ]);

    if ($value === null) {
        DB::table('environment_variables')->where('id', $variable->id)->update(['value' => null]);
    }
    DB::table('environment_variables')->where('id', $variable->id)->update(['updated_at' => $updatedAt]);

    return $variable;
}

it('keeps the copy with a value when a later Compose parse added an empty copy', function () {
    $restored = duplicateApplicationVariable($this->application, 'POSTGRES_USERNAME', 'myRestoredUsername', '2025-06-01 10:00:00');
    duplicateApplicationVariable($this->application, 'POSTGRES_USERNAME', null, '2025-07-01 10:00:00');
    duplicateApplicationVariable($this->application, 'POSTGRES_USERNAME', '', '2025-07-02 10:00:00');

    runRemoveDuplicateEnvironmentVariablesMigration();

    $variables = $this->application->environment_variables()->where('key', 'POSTGRES_USERNAME')->get();
    expect($variables->pluck('id')->all())->toBe([$restored->id])
        ->and($variables->first()->value)->toBe('myRestoredUsername');
});

it('keeps the most recently changed copy when several copies have a value', function () {
    duplicateApplicationVariable($this->application, 'JOB_ATTEMPTS', '3', '2025-06-01 10:00:00');
    $latest = duplicateApplicationVariable($this->application, 'JOB_ATTEMPTS', '5', '2025-07-01 10:00:00');
    duplicateApplicationVariable($this->application, 'EMPTY_ONLY', null, '2025-06-01 10:00:00');
    $latestEmpty = duplicateApplicationVariable($this->application, 'EMPTY_ONLY', null, '2025-07-01 10:00:00');

    runRemoveDuplicateEnvironmentVariablesMigration();

    expect($this->application->environment_variables()->where('key', 'JOB_ATTEMPTS')->pluck('id')->all())->toBe([$latest->id])
        ->and($this->application->environment_variables()->where('key', 'EMPTY_ONLY')->pluck('id')->all())->toBe([$latestEmpty->id]);
});

it('keeps one variable of each key per resource and preview flag', function () {
    $other = Application::factory()->create(['environment_id' => $this->environment->id]);
    $production = duplicateApplicationVariable($this->application, 'API_KEY', 'production', '2025-06-01 10:00:00');
    $preview = duplicateApplicationVariable($this->application, 'API_KEY', 'preview', '2025-06-01 10:00:00', isPreview: true);
    $otherApplication = duplicateApplicationVariable($other, 'API_KEY', 'other', '2025-06-01 10:00:00');
    $serviceVariable = insertDuplicateEnvironmentVariable([
        'key' => 'API_KEY',
        'value' => 'service',
        'resourceable_type' => Service::class,
        'resourceable_id' => $this->application->id,
    ]);

    runRemoveDuplicateEnvironmentVariablesMigration();

    expect(EnvironmentVariable::query()->where('key', 'API_KEY')->orderBy('id')->pluck('id')->all())
        ->toBe([$production->id, $preview->id, $otherApplication->id, $serviceVariable->id]);
});
