<?php

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Server::flushIdentityMap();

    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = StandaloneDocker::query()->where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  db:
    image: postgres:16-alpine
    environment:
      POSTGRES_USER: ${SERVICE_USER_DB}
      POSTGRES_PASSWORD: ${SERVICE_PASSWORD_DB}
YAML,
    ]);
});

function composePreviewMagicParse(Application $application, int $pullRequestId = 0): void
{
    $previewId = null;
    if ($pullRequestId !== 0) {
        $previewId = ApplicationPreview::query()->firstOrCreate([
            'application_id' => $application->id,
            'pull_request_id' => $pullRequestId,
        ], [
            'pull_request_html_url' => "https://github.com/example/repository/pull/{$pullRequestId}",
        ])->id;
    }

    $application->fresh()->parse(pull_request_id: $pullRequestId, preview_id: $previewId);
}

/**
 * @return array{production: array<string, ?string>, preview: array<string, ?string>}
 */
function composePreviewMagicValues(Application $application): array
{
    $keys = ['SERVICE_PASSWORD_DB', 'SERVICE_USER_DB'];

    return [
        'production' => $application->environment_variables()->whereIn('key', $keys)->pluck('value', 'key')->sortKeys()->all(),
        'preview' => $application->environment_variables_preview()->whereIn('key', $keys)->pluck('value', 'key')->sortKeys()->all(),
    ];
}

it('generates the magic variables of a first pull request parse only in the preview variables', function () {
    composePreviewMagicParse($this->application, 7);

    $values = composePreviewMagicValues($this->application);
    expect($values['production'])->toBe([])
        ->and(array_keys($values['preview']))->toBe(['SERVICE_PASSWORD_DB', 'SERVICE_USER_DB'])
        ->and($values['preview']['SERVICE_PASSWORD_DB'])->not->toBeEmpty();

    // The production deployment generates its own values and keeps the preview values.
    composePreviewMagicParse($this->application);

    $afterProduction = composePreviewMagicValues($this->application);
    expect($afterProduction['preview'])->toBe($values['preview'])
        ->and($afterProduction['production']['SERVICE_PASSWORD_DB'])->not->toBeEmpty()
        ->and($afterProduction['production']['SERVICE_PASSWORD_DB'])->not->toBe($values['preview']['SERVICE_PASSWORD_DB']);
});

it('does not copy a generated production magic secret to the preview variables', function (string $key) {
    EnvironmentVariable::create([
        'key' => $key,
        'value' => 'production-secret',
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($this->application->environment_variables_preview()->where('key', $key)->exists())->toBeFalse();
})->with([
    'SERVICE_PASSWORD_DB',
    'SERVICE_PASSWORD_64_DB',
    'SERVICE_PASSWORDWITHSYMBOLS_DB',
    'SERVICE_USER_DB',
    'SERVICE_LOWERCASEUSER_DB',
    'SERVICE_BASE64_DB',
    'SERVICE_BASE64_64_DB',
    'SERVICE_BASE64_128_DB',
    'SERVICE_REALBASE64_DB',
    'SERVICE_HEX_32_DB',
]);

it('still copies a normal or service url production variable to the preview variables', function (string $key) {
    EnvironmentVariable::create([
        'key' => $key,
        'value' => 'production-value',
        'is_preview' => false,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    expect($this->application->environment_variables_preview()->where('key', $key)->value('value'))->toBe('production-value');
})->with(['DATABASE_HOST', 'SERVICE_URL_DB', 'SERVICE_FQDN_DB', 'SERVICE_PASSWORD_DB_MAIN']);

it('gives a private pull request its own magic values instead of the production values', function () {
    composePreviewMagicParse($this->application);
    $production = composePreviewMagicValues($this->application)['production'];

    composePreviewMagicParse($this->application, 7);

    $values = composePreviewMagicValues($this->application);
    expect($values['production'])->toBe($production)
        ->and(array_keys($values['preview']))->toBe(['SERVICE_PASSWORD_DB', 'SERVICE_USER_DB'])
        ->and($values['preview']['SERVICE_PASSWORD_DB'])->not->toBeEmpty()
        ->and($values['preview']['SERVICE_PASSWORD_DB'])->not->toBe($production['SERVICE_PASSWORD_DB'])
        ->and($values['preview']['SERVICE_USER_DB'])->not->toBe($production['SERVICE_USER_DB']);

    // The next pull request parse keeps the preview values, so the preview database keeps its password.
    composePreviewMagicParse($this->application, 7);

    expect(composePreviewMagicValues($this->application))->toBe($values);
});

it('keeps an existing preview copy of a production magic value for a private pull request', function () {
    composePreviewMagicParse($this->application);
    $production = composePreviewMagicValues($this->application)['production'];
    // A preview copy that an earlier version created; existing preview databases use this password.
    $this->application->environment_variables_preview()->create([
        'key' => 'SERVICE_PASSWORD_DB',
        'value' => $production['SERVICE_PASSWORD_DB'],
        'is_preview' => true,
    ]);

    composePreviewMagicParse($this->application, 7);

    expect(composePreviewMagicValues($this->application)['preview']['SERVICE_PASSWORD_DB'])->toBe($production['SERVICE_PASSWORD_DB']);
});

it('keeps a preview magic variable that the user created', function () {
    EnvironmentVariable::create([
        'key' => 'SERVICE_PASSWORD_DB',
        'value' => 'user-preview-password',
        'is_preview' => true,
        'resourceable_type' => Application::class,
        'resourceable_id' => $this->application->id,
    ]);

    composePreviewMagicParse($this->application, 7);

    expect(composePreviewMagicValues($this->application)['preview']['SERVICE_PASSWORD_DB'])->toBe('user-preview-password');
});

it('gives a public pull request its own magic values instead of the production values', function () {
    composePreviewMagicParse($this->application);
    $production = composePreviewMagicValues($this->application)['production'];
    $this->application->settings->update(['is_pr_deployments_public_enabled' => true]);

    composePreviewMagicParse($this->application, 7);

    $values = composePreviewMagicValues($this->application);
    expect($values['production'])->toBe($production)
        ->and($values['preview']['SERVICE_PASSWORD_DB'])->not->toBeEmpty()
        ->and($values['preview']['SERVICE_PASSWORD_DB'])->not->toBe($production['SERVICE_PASSWORD_DB'])
        ->and($values['preview']['SERVICE_USER_DB'])->not->toBe($production['SERVICE_USER_DB']);

    // The next pull request parse keeps the preview values, so the preview database keeps its password.
    composePreviewMagicParse($this->application, 7);

    expect(composePreviewMagicValues($this->application))->toBe($values);
});

it('keeps a changed preview magic value of a public pull request', function () {
    composePreviewMagicParse($this->application);
    $this->application->settings->update(['is_pr_deployments_public_enabled' => true]);
    $this->application->environment_variables_preview()->create([
        'key' => 'SERVICE_PASSWORD_DB',
        'value' => 'preview-password',
        'is_preview' => true,
    ]);

    composePreviewMagicParse($this->application, 7);

    expect(composePreviewMagicValues($this->application)['preview']['SERVICE_PASSWORD_DB'])->toBe('preview-password');
});

it('replaces an existing preview copy of a production magic value for a public pull request', function () {
    composePreviewMagicParse($this->application);
    $production = composePreviewMagicValues($this->application)['production'];
    $this->application->environment_variables_preview()->create([
        'key' => 'SERVICE_PASSWORD_DB',
        'value' => $production['SERVICE_PASSWORD_DB'],
        'is_preview' => true,
    ]);
    $this->application->settings->update(['is_pr_deployments_public_enabled' => true]);

    composePreviewMagicParse($this->application, 7);

    expect(composePreviewMagicValues($this->application)['preview']['SERVICE_PASSWORD_DB'])
        ->not->toBeEmpty()
        ->not->toBe($production['SERVICE_PASSWORD_DB']);
});
