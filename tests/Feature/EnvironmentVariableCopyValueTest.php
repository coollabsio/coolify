<?php

use App\Livewire\Project\Shared\EnvironmentVariable\Show;
use App\Livewire\Project\Shared\EnvironmentVariable\ShowHardcoded;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::forceCreate(['id' => 0]);

    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->team->members()->attach($this->user, ['role' => 'owner']);
    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);
    $this->application = Application::factory()->create(['environment_id' => $this->environment->id]);

    $this->actingAs($this->user);
    session(['currentTeam' => $this->team]);
});

function createEnvironmentVariable(array $attributes = []): EnvironmentVariable
{
    return EnvironmentVariable::create(array_merge([
        'key' => 'API_KEY',
        'value' => 'secret-value',
        'resourceable_type' => Application::class,
        'resourceable_id' => test()->application->id,
    ], $attributes));
}

function assertCopiedValue(EnvironmentVariable|SharedEnvironmentVariable $env, ?string $expected): void
{
    Livewire::test(Show::class, ['env' => $env, 'type' => 'application'])
        ->call('copyValue')
        ->assertReturned($expected);
}

function assertCopiedComposeValue(string $value, ?string $expected): void
{
    Livewire::test(ShowHardcoded::class, [
        'env' => ['key' => 'MYSQL_USER', 'value' => $value],
        'resourceableType' => Application::class,
        'resourceableId' => test()->application->id,
    ])
        ->call('copyValue')
        ->assertReturned($expected);
}

test('copies the plain value', function () {
    assertCopiedValue(createEnvironmentVariable(), 'secret-value');
});

test('copies the referenced variable value instead of the reference', function (string $reference) {
    createEnvironmentVariable(['key' => 'SERVICE_USER_CLASSICPRESS', 'value' => 'classicpress-user']);

    assertCopiedValue(createEnvironmentVariable(['key' => 'MYSQL_USER', 'value' => $reference]), 'classicpress-user');
})->with(['bare' => '$SERVICE_USER_CLASSICPRESS', 'braced' => '${SERVICE_USER_CLASSICPRESS}']);

test('copies the resolved shared variable value', function () {
    SharedEnvironmentVariable::create([
        'key' => 'MY_SECRET',
        'value' => 'resolved-secret',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    assertCopiedValue(createEnvironmentVariable(['value' => '{{team.MY_SECRET}}']), 'resolved-secret');
});

test('copies embedded, literal and unknown references as stored', function () {
    createEnvironmentVariable(['key' => 'SERVICE_PASSWORD_MYSQL', 'value' => 'generated-password']);

    assertCopiedValue(
        createEnvironmentVariable(['key' => 'DATABASE_URL', 'value' => 'mysql://root:$SERVICE_PASSWORD_MYSQL@db:3306']),
        'mysql://root:$SERVICE_PASSWORD_MYSQL@db:3306',
    );
    assertCopiedValue(
        createEnvironmentVariable(['key' => 'LITERAL', 'value' => '$SERVICE_PASSWORD_MYSQL', 'is_literal' => true]),
        '$SERVICE_PASSWORD_MYSQL',
    );
    assertCopiedValue(createEnvironmentVariable(['key' => 'UNKNOWN', 'value' => '$DOES_NOT_EXIST']), '$DOES_NOT_EXIST');
});

test('copies literal values without .env-style quoting', function () {
    $env = createEnvironmentVariable(['value' => 'pa$$word', 'is_literal' => true]);

    expect($env->real_value)->toBe("'pa\$\$word'");
    assertCopiedValue($env, 'pa$$word');
});

test('copies the value of a shared environment variable row', function () {
    $shared = SharedEnvironmentVariable::create([
        'key' => 'TEAM_WIDE',
        'value' => 'team-wide-value',
        'type' => 'team',
        'team_id' => $this->team->id,
    ]);

    assertCopiedValue($shared, 'team-wide-value');
});

test('members get no copy button and no value', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(Show::class, ['env' => createEnvironmentVariable(), 'type' => 'application'])
        ->assertDontSeeHtml('Copy value')
        ->call('copyValue')
        ->assertReturned(null);
});

test('locked variables get no copy button and no value', function () {
    Livewire::test(Show::class, ['env' => createEnvironmentVariable(['is_shown_once' => true]), 'type' => 'application'])
        ->assertDontSeeHtml('Copy value')
        ->call('copyValue')
        ->assertReturned(null);
});

test('compose-managed rows copy the referenced variable value', function () {
    createEnvironmentVariable(['key' => 'SERVICE_USER_CLASSICPRESS', 'value' => 'classicpress-user']);

    assertCopiedComposeValue('$SERVICE_USER_CLASSICPRESS', 'classicpress-user');
    assertCopiedComposeValue('production', 'production');
});

test('compose-managed rows hide copying from members', function () {
    $member = User::factory()->create();
    $this->team->members()->attach($member, ['role' => 'member']);
    $this->actingAs($member);

    Livewire::test(ShowHardcoded::class, [
        'env' => ['key' => 'MYSQL_USER', 'value' => '$SERVICE_USER_CLASSICPRESS'],
        'resourceableType' => Application::class,
        'resourceableId' => $this->application->id,
    ])
        ->assertDontSeeHtml('Copy value')
        ->call('copyValue')
        ->assertReturned(null);
});
