<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\Environment;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledTask;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\Tag;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('renders every GET page', function () {
    config()->set('constants.coolify.dev_seed_ip', '127.0.0.1');
    config()->set('app.maintenance.store', 'array');
    Process::fake();
    $this->seed(DatabaseSeeder::class);

    $user = User::find(0);
    $team = $user->teams()->first();
    $team->forceFill(['show_boarding' => false])->save();
    Cache::flush();

    $server = Server::find(0);
    $environment = Environment::query()->first();
    $project = $environment->project;
    $application = Application::query()->first();
    $database = StandalonePostgresql::query()->where('id', '!=', 0)->first() ?? StandalonePostgresql::query()->first();
    $destination = StandaloneDocker::query()->first();

    $service = Service::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'server_id' => $server->id,
        'docker_compose_raw' => "services:\n  app:\n    image: nginx\n  db:\n    image: postgres:16\n",
    ]);
    $serviceApp = ServiceApplication::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'app', 'service_id' => $service->id, 'image' => 'nginx']);
    $serviceDb = ServiceDatabase::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'db', 'service_id' => $service->id, 'image' => 'postgres:16']);

    $tag = Tag::forceCreate(['name' => 'crawl', 'team_id' => $team->id]);
    $application->tags()->attach($tag->id);

    $s3 = S3Storage::query()->first();
    $backup = ScheduledDatabaseBackup::forceCreate([
        'uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'frequency' => '0 0 * * *',
        'database_id' => $database->id, 'database_type' => $database->getMorphClass(),
        's3_storage_id' => $s3?->id,
    ]);
    $deployment = ApplicationDeploymentQueue::forceCreate([
        'deployment_uuid' => (string) Str::uuid(), 'application_id' => $application->id,
        'server_id' => $server->id, 'status' => 'finished', 'logs' => '[]',
        'application_name' => $application->name, 'server_name' => $server->name,
    ]);
    $task = ScheduledTask::factory()->create(['application_id' => $application->id, 'team_id' => $team->id]);

    $cloudToken = \App\Models\CloudProviderToken::factory()->create(['team_id' => $team->id]);
    $cloudInit = \App\Models\CloudInitScript::forceCreate(['uuid' => (string) Str::uuid(), 'team_id' => $team->id, 'name' => 'init', 'script' => '#!/bin/sh']);
    $gitea = \App\Models\GiteaApp::forceCreate(['uuid' => (string) Str::uuid(), 'name' => 'gitea', 'api_url' => 'https://gitea.example.com/api/v1', 'html_url' => 'https://gitea.example.com', 'team_id' => $team->id, 'private_key_id' => PrivateKey::query()->value('id')]);

    $params = [
        'token_uuid' => $cloudToken->uuid,
        'cloud_token_uuid' => $cloudToken->uuid,
        'cloud_init_script_uuid' => $cloudInit->uuid,
        'gitea_app_uuid' => $gitea->uuid,
        'project_uuid' => $project->uuid,
        'environment_uuid' => $environment->uuid,
        'application_uuid' => $application->uuid,
        'database_uuid' => $database->uuid,
        'service_uuid' => $service->uuid,
        'stack_service_uuid' => $serviceApp->uuid,
        'server_uuid' => $server->uuid,
        'destination_uuid' => $destination->uuid,
        'backup_uuid' => $backup->uuid,
        'deployment_uuid' => $deployment->deployment_uuid,
        'task_uuid' => $task->uuid,
        'github_app_uuid' => GithubApp::query()->value('uuid'),
        'gitlab_app_uuid' => GitlabApp::query()->value('uuid'),
        'private_key_uuid' => PrivateKey::query()->value('uuid'),
        'storage_uuid' => $s3?->uuid,
        'tagName' => $tag->name,
        'user_id' => $user->id,
        'team_id' => $team->id,
        'type' => 'hetzner',
        'provider' => 'github',
    ];

    $originalRedirector = app('redirect');
    $renderedViews = [];
    \Illuminate\Support\Facades\View::composer('*', function ($view) use (&$renderedViews) {
        $renderedViews[$view->getPath()] = true;
    });
    $failures = [];
    $skipped = [];
    $redirects = [];
    $count = 0;
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods())) {
            continue;
        }
        $uri = $route->uri();
        if (Str::startsWith($uri, ['api', 'canvas/api', '_', 'horizon', 'telescope', 'livewire', 'webhooks', 'auth/', 'email/', 'download/', 'files/', 'sanctum', 'up', 'storage', 'mcp', 'oauth', 'two-factor', 'user/', 'reset-password', 'login', 'register', 'forgot-password', 'logout', 'verify', 'invitations', 'force-password-reset', 'onboarding', 'select-team', 'subscription', 'terminal/'])
            || $uri === '{any}') {
            continue;
        }
        $variants = [$uri];
        if (str_contains($uri, '?}')) {
            $variants[] = rtrim(preg_replace('/\/?\{\w+\?\}/', '', $uri), '/');
        }
        foreach ($variants as $uri) {
            $missing = false;
            $path = preg_replace_callback('/\{(\w+)\??\}/', function ($m) use ($params, &$missing) {
                if (empty($params[$m[1]]) && ($params[$m[1]] ?? null) !== 0) {
                    $missing = $m[1];

                    return '';
                }

                return $params[$m[1]];
            }, $uri);
            if ($missing) {
                $skipped[] = "$uri (no $missing)";

                continue;
            }
            if ($uri === 'project/{project_uuid}/environment/{environment_uuid}/service/{service_uuid}/{stack_service_uuid}/backups' || str_contains($uri, '{stack_service_uuid}/backups') || str_contains($uri, '{stack_service_uuid}/import')) {
                $path = str_replace($serviceApp->uuid, $serviceDb->uuid, $path);
            }
            $count++;
            app()->instance('redirect', $originalRedirector);
            \Livewire\Features\SupportRedirects\SupportRedirects::$redirectorCacheStack = [];
            session(['currentTeam' => $team]);
            $response = $this->actingAs($user)->get('/'.ltrim($path, '/'));
            if ($response->isRedirect()) {
                $redirects[] = '/'.$path.' -> '.$response->headers->get('Location');
            }
            if ($response->getStatusCode() >= 400 && $response->getStatusCode() < 500) {
                fwrite(STDERR, "4XX /$path => ".$response->getStatusCode().' '.Str::limit((string) $response->exception?->getMessage(), 100)."\n");
            }
            if ($response->getStatusCode() >= 500) {
                $e = $response->exception;
                $failures[] = "/".$path." => ".$response->getStatusCode()." ".($e ? get_class($e).": ".Str::limit($e->getMessage(), 120)."\n   ".collect($e->getTrace())->filter(fn ($f) => isset($f["file"]))->take(0)->map(fn ($f) => str_replace(base_path()."/", "", $f["file"]).":".$f["line"])->implode("\n   ") : "");
            }
        }
    }

    file_put_contents('/tmp/rendered-views.txt', implode("\n", array_keys($renderedViews)));
    fwrite(STDERR, "\nCrawled $count pages\nSKIPPED:\n".implode("\n", $skipped)."\nREDIRECTS:\n".implode("\n", $redirects)."\nFAILURES:\n".implode("\n", $failures)."\n");
    expect($failures)->toBe([]);
});
