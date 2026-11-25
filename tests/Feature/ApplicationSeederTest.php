<?php

use App\Models\Application;
use App\Models\GitlabApp;
use App\Models\Project;
use App\Models\Server;
use Database\Seeders\ApplicationSeeder;
use Database\Seeders\GithubAppSeeder;
use Database\Seeders\GitlabAppSeeder;
use Database\Seeders\PrivateKeySeeder;
use Database\Seeders\ProjectSeeder;
use Database\Seeders\ServerSeeder;
use Database\Seeders\ServerSettingSeeder;
use Database\Seeders\StandaloneDockerSeeder;
use Database\Seeders\TeamSeeder;
use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function seedApplicationSeederPrerequisites(): void
{
    test()->seed([
        UserSeeder::class,
        TeamSeeder::class,
        PrivateKeySeeder::class,
        ServerSeeder::class,
        ProjectSeeder::class,
        StandaloneDockerSeeder::class,
        GithubAppSeeder::class,
        GitlabAppSeeder::class,
    ]);
}

beforeEach(function () {
    config()->set('constants.coolify.dev_seed_ip', '127.0.0.1');
});

it('seeds every git example as railpack in the default project', function () {
    seedApplicationSeederPrerequisites();
    $this->seed(ApplicationSeeder::class);

    $railpackUuids = collect(ApplicationSeeder::railpackExamples())->pluck('uuid')->sort()->values()->all();

    expect(Application::query()->where('build_pack', 'railpack')->pluck('uuid')->sort()->values()->all())->toBe($railpackUuids)
        ->and(Application::query()->whereNotIn('build_pack', ['railpack', 'dockerfile', 'dockercompose'])->exists())->toBeFalse()
        ->and(Project::query()->count())->toBe(1)
        ->and(Application::query()->pluck('environment_id')->unique()->all())->toBe([1])
        ->and(Application::query()->whereNotNull('git_branch')->pluck('git_branch')->unique()->all())->toBe(['main']);
});

it('seeds railpack examples with the new example repository layout', function () {
    seedApplicationSeederPrerequisites();
    $this->seed(ApplicationSeeder::class);

    $nodejs = Application::query()->where('uuid', 'nodejs')->sole();
    $astroServer = Application::query()->where('uuid', 'astro-server')->sole();
    $vueSpa = Application::query()->where('uuid', 'vue-spa')->with('settings')->sole();
    $gitlabPublic = Application::query()->where('uuid', 'gitlab-public-example')->with('settings')->sole();
    $compose = Application::query()->where('uuid', 'docker-compose')->sole();

    expect($nodejs->git_repository)->toBe(ApplicationSeeder::EXAMPLES_REPOSITORY)
        ->and($nodejs->base_directory)->toBe('/javascript/runtimes/nodejs')
        ->and($nodejs->fqdn)->toBe('http://nodejs.127.0.0.1.sslip.io')
        ->and($astroServer->start_command)->toBe('HOST=0.0.0.0 node dist/server/entry.mjs')
        ->and($vueSpa->publish_directory)->toBe('/dist')
        ->and($vueSpa->settings->is_static)->toBeTrue()
        ->and($vueSpa->settings->is_spa)->toBeTrue()
        ->and($gitlabPublic->source_type)->toBe(GitlabApp::class)
        ->and($gitlabPublic->repository_project_id)->toBeNull()
        ->and($gitlabPublic->settings->is_static)->toBeTrue()
        ->and($compose->base_directory)->toBe('/internal/compose');
});

it('uses DEV_SEED_IP for the server wildcard domain and application domains', function () {
    config()->set('constants.coolify.dev_seed_ip', '100.64.1.2');

    seedApplicationSeederPrerequisites();
    $this->seed([ServerSettingSeeder::class, ApplicationSeeder::class]);

    expect(Server::query()->find(0)->settings->wildcard_domain)->toBe('http://100.64.1.2.sslip.io')
        ->and(Application::query()->where('uuid', 'nodejs')->value('fqdn'))->toBe('http://nodejs.100.64.1.2.sslip.io')
        ->and(Application::query()->where('uuid', 'dockerfile')->value('fqdn'))->toBe('http://dockerfile.100.64.1.2.sslip.io')
        ->and(Application::query()->whereNotNull('fqdn')->where('fqdn', 'not like', '%.100.64.1.2.sslip.io')->exists())->toBeFalse();
});

it('rejects a DEV_SEED_IP that is not an IPv4 address', function (string $value) {
    config()->set('constants.coolify.dev_seed_ip', $value);

    ServerSettingSeeder::sslipHost();
})->with(['hostname' => 'devserver.ts.net', 'ipv6' => 'fd7a:115c:a1e0::1', 'injection' => '1.2.3.4; rm -rf /'])
    ->throws(InvalidArgumentException::class);
