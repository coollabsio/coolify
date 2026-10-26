<?php

// Checks on the target (B) after the debian-root transfer of extras-e2e.sh. Prints PASS/FAIL lines.

use App\Models\Application;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\IntegrationToken;
use App\Models\ManagedDnsRecord;
use App\Models\ResourceLink;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneMariadb;
use App\Services\ProjectIconStorageService;

$check = function (string $name, bool $ok, string $detail = '') {
    echo ($ok ? 'PASS ' : 'FAIL ').$name.($detail !== '' ? " ({$detail})" : '')."\n";
};

$server = Server::where('uuid', 'development-qemu-debian-root')->first();
$check('server is on B and claimed', data_get($server?->server_metadata, 'transfer.status') === 'claimed');
$check('custom proxy error page', $server?->proxy_error_page === '<h1>fake error page</h1>');

$cloudflare = IntegrationToken::where('name', 'fake-cloudflare')->first();
$infisical = IntegrationToken::where('name', 'fake-infisical')->first();
$check('Cloudflare token moved with its secret', $cloudflare?->token === 'fake-cf-token');
$check('Infisical token moved with its secret', $infisical?->token === 'fake-inf-token');
$check('Cloudflare tunnel uses the moved token', $server?->settings?->cloudflare_tunnel_integration_token_id === $cloudflare?->id && $server?->settings?->cloudflare_tunnel_id === 'fake-tunnel');

$app = Application::where('name', 'whoami-2')->first();
$link = SecretManagerLink::where('resourceable_type', $app?->getMorphClass())->where('resourceable_id', $app?->id)->first();
$check('secret manager link moved to the moved token', $link?->integration_token_id === $infisical?->id && data_get($link?->settings, 'project_id') === 'fake-project');

$record = ManagedDnsRecord::where('name', 'whoami2.example.test')->first();
$check('DNS record + zone moved', $record !== null && $record->zone?->provider_zone_id === 'fake-zone-id' && $record->integration_token_id === $cloudflare?->id);
$check('DNS record references the moved app', $record?->references()->where('resource_type', $app?->getMorphClass())->where('resource_id', $app?->id)->exists() === true);
// Same app.id on both instances: the owner comment does not change, so the record stays owned.
// Different app.id: the target must change the comment at Cloudflare, which fails with a fake token.
$sourceAppId = trim((string) @file_get_contents('/tmp/source-app-id'));
$sameInstanceId = $sourceAppId === (string) config('app.id');
$check('DNS ownership '.($sameInstanceId ? 'kept (same instance ID, no adoption needed)' : 'dropped (fake token cannot adopt)'), $record?->owned === $sameInstanceId);

$ssh = ManagedDnsRecord::where('name', 'ssh-debian.example.test')->first();
$check('tunnel SSH DNS record moved with the server', $ssh?->references()->where('resource_type', $server?->getMorphClass())->where('resource_id', $server?->id)->exists() === true && $ssh->owned === $sameInstanceId);

$githubApp = GithubApp::where('name', 'fake-gh-app')->first();
$runner = GithubRunnerConfig::where('server_id', $server?->id)->first();
$check('GitHub App moved (only the runner config uses it)', $githubApp !== null && $githubApp->organization === 'fake-org');
$check('runner config moved, DISABLED, same App and labels', $runner !== null && $runner->is_enabled === false && $runner->github_app_id === $githubApp?->id && in_array('fake-label', (array) $runner->labels, true));

$db = StandaloneMariadb::where('name', 'mariadb')->first();
$check('resource link between the two moved resources', ResourceLink::where('source_id', $app?->id)->where('target_type', $db?->getMorphClass())->where('target_id', $db?->id)->where('label', 'uses db')->exists());
$check('resource link to the other server is not moved', ! ResourceLink::where('label', 'calls other')->exists());

$environment = $app?->environment;
$check('canvas positions moved', data_get($environment?->canvas_positions, ($app?->uuid).'.x') === 111);
$check('environment without resources on this server is not moved', ! Environment::where('name', 'fake-staging')->exists());
$check('apps on the other server are not moved', ! Application::whereIn('name', ['fake-on-ubuntu', 'fake-staging-on-ubuntu'])->exists());

$check('team shared variable created', SharedEnvironmentVariable::where('type', 'team')->where('key', 'FAKE_TEAM_VAR')->value('value') === 'team-value');
$check('app variable that uses it moved', $app?->environment_variables()->where('key', 'USES_TEAM')->value('value') === '{{team.FAKE_TEAM_VAR}}');
$check('maintenance mode moved', $app?->is_maintenance_enabled === true && $app?->maintenance_page === '<h1>fake maintenance</h1>');

$gitShop = Application::where('name', 'git-shop')->first();
$check('git-shop moved without its system-wide source', $gitShop !== null && $gitShop->source_id === null);
$check('finish list has a git source step for git-shop', collect(data_get($server?->server_metadata, 'transfer.finish.items'))->contains('key', 'git_source:'.$gitShop?->uuid));

$project = $environment?->project;
$icon = $project ? app(ProjectIconStorageService::class)->projectContents($project) : null;
$check('project icon moved', $icon !== null && str_starts_with($icon, "\xFF\xD8\xFF"));
