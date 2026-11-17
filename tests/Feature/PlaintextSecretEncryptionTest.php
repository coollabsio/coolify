<?php

use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\Team;
use App\Models\TeamInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $destination = $server->standaloneDockers()->firstOrFail();
    $environment = Environment::factory()->create([
        'project_id' => Project::factory()->create(['team_id' => $team->id])->id,
    ]);
    $databaseAttributes = [
        'destination_type' => $destination->getMorphClass(),
        'destination_id' => $destination->id,
        'environment_id' => $environment->id,
    ];
    $gitAppAttributes = [
        'html_url' => 'https://example.com',
        'custom_user' => 'git',
        'custom_port' => 22,
        'team_id' => $team->id,
        'is_system_wide' => false,
    ];

    $githubApp = GithubApp::create([
        ...$gitAppAttributes,
        'name' => 'github',
        'api_url' => 'https://api.github.com',
        'client_secret' => 'github-client-secret',
        'webhook_secret' => 'github-webhook-secret',
    ]);
    $gitlabApp = GitlabApp::create([
        ...$gitAppAttributes,
        'name' => 'gitlab',
        'api_url' => 'https://gitlab.com/api/v4',
        'app_secret' => str_repeat('g', 64),
    ]);
    $mariadb = StandaloneMariadb::create([
        ...$databaseAttributes,
        'name' => 'mariadb',
        'mariadb_root_password' => 'mariadb-root-password',
        'mariadb_password' => 'mariadb-password',
    ]);
    $mongodb = StandaloneMongodb::create([
        ...$databaseAttributes,
        'name' => 'mongodb',
        'mongo_initdb_root_password' => 'mongo-root-password',
    ]);
    $server->settings->update(['logdrain_custom_config' => "[OUTPUT]\n    Name http\n    http_Passwd secret"]);
    $invitation = TeamInvitation::create([
        'team_id' => $team->id,
        'uuid' => 'invite-uuid',
        'email' => 'new@example.com',
        'role' => 'member',
        'link' => 'https://coolify.test/auth/link?token=secret-token',
        'via' => 'link',
    ]);

    $this->secrets = [
        ['github_apps', $githubApp, 'client_secret', 'github-client-secret'],
        ['github_apps', $githubApp, 'webhook_secret', 'github-webhook-secret'],
        ['gitlab_apps', $gitlabApp, 'app_secret', str_repeat('g', 64)],
        ['standalone_mariadbs', $mariadb, 'mariadb_root_password', 'mariadb-root-password'],
        ['standalone_mongodbs', $mongodb, 'mongo_initdb_root_password', 'mongo-root-password'],
        ['server_settings', $server->settings, 'logdrain_custom_config', "[OUTPUT]\n    Name http\n    http_Passwd secret"],
        ['team_invitations', $invitation, 'link', 'https://coolify.test/auth/link?token=secret-token'],
    ];
});

function rawSecret(string $table, $model, string $column): ?string
{
    return DB::table($table)->where('id', $model->id)->value($column);
}

it('encrypts secrets at rest when they are saved through the model', function () {
    foreach ($this->secrets as [$table, $model, $column, $plaintext]) {
        expect(Crypt::decryptString(rawSecret($table, $model, $column)))->toBe($plaintext, "{$table}.{$column}")
            ->and($model->fresh()->{$column})->toBe($plaintext, "{$table}.{$column}");
    }
});

it('stores the GitLab app secret in a text column so the ciphertext fits', function () {
    expect(Schema::getColumnType('gitlab_apps', 'app_secret'))->toBe('text');
});

it('migrates plaintext and legacy serialized secrets without encrypting them twice', function () {
    foreach ($this->secrets as [$table, $model, $column, $plaintext]) {
        $legacyValue = $column === 'mongo_initdb_root_password' ? encrypt($plaintext) : $plaintext;
        DB::table($table)->where('id', $model->id)->update([$column => $legacyValue]);
    }
    $emptyGithubApp = GithubApp::create([
        'name' => 'empty',
        'api_url' => 'https://api.github.com',
        'html_url' => 'https://github.com',
        'team_id' => $this->secrets[0][1]->team_id,
        'is_system_wide' => false,
    ]);

    $migration = require database_path('migrations/2026_10_19_083521_encrypt_plaintext_secrets.php');
    $migration->up();
    $migration->up();

    foreach ($this->secrets as [$table, $model, $column, $plaintext]) {
        expect(Crypt::decryptString(rawSecret($table, $model, $column)))->toBe($plaintext, "{$table}.{$column}")
            ->and($model->fresh()->{$column})->toBe($plaintext, "{$table}.{$column}");
    }
    expect(rawSecret('github_apps', $emptyGithubApp, 'client_secret'))->toBeNull()
        ->and(rawSecret('github_apps', $emptyGithubApp, 'webhook_secret'))->toBeNull();
});
