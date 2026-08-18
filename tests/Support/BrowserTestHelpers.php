<?php

/*
|--------------------------------------------------------------------------
| Shared Browser Test Helpers
|--------------------------------------------------------------------------
|
| Helpers for Pest browser tests under tests/v4/Browser.
| Loaded from tests/Pest.php.
|
*/

use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * InstanceSettings.id is not fillable; always forceCreate id 0 for browser tests.
 */
function seedBrowserInstanceSettings(array $attributes = []): InstanceSettings
{
    return InstanceSettings::forceCreate(array_merge([
        'id' => 0,
        'is_sponsorship_popup_enabled' => false,
        'is_registration_enabled' => true,
    ], $attributes));
}

/**
 * Root user (id 0) with known credentials for browser login.
 */
function createBrowserRootUser(
    string $email = 'test@example.com',
    string $password = 'password',
    string $name = 'Root User',
): User {
    return User::forceCreate([
        'id' => 0,
        'name' => $name,
        'email' => $email,
        'password' => Hash::make($password),
    ]);
}

/**
 * Development-style OpenSSH private key used across browser fixtures.
 */
function browserTestPrivateKeyPem(): string
{
    return <<<'KEY'
-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
KEY;
}

/**
 * Seed instance settings, root user, SSH key, localhost server, destination,
 * project and default environment for resource browser tests.
 *
 * @return array{
 *     user: User,
 *     privateKey: PrivateKey,
 *     server: Server,
 *     destination: StandaloneDocker,
 *     project: Project,
 *     environment: Environment
 * }
 */
function seedBrowserResourceStack(array $overrides = []): array
{
    seedBrowserInstanceSettings($overrides['instanceSettings'] ?? []);

    $user = createBrowserRootUser(
        $overrides['email'] ?? 'test@example.com',
        $overrides['password'] ?? 'password',
        $overrides['name'] ?? 'Root User',
    );

    $privateKey = PrivateKey::create([
        'id' => 1,
        'uuid' => $overrides['privateKeyUuid'] ?? 'ssh-test',
        'team_id' => 0,
        'name' => 'Test Key',
        'description' => 'Test SSH key',
        'private_key' => browserTestPrivateKeyPem(),
    ]);

    $server = Server::create([
        'id' => 0,
        'uuid' => $overrides['serverUuid'] ?? 'localhost',
        'name' => $overrides['serverName'] ?? 'localhost',
        'description' => $overrides['serverDescription'] ?? 'Test docker container in development',
        'ip' => $overrides['serverIp'] ?? 'coolify-testing-host',
        'team_id' => 0,
        'private_key_id' => $privateKey->id,
        'proxy' => [
            'type' => ProxyTypes::TRAEFIK->value,
            'status' => ProxyStatus::EXITED->value,
        ],
    ]);

    if ($server->settings) {
        $server->settings->is_reachable = true;
        $server->settings->is_usable = true;
        $server->settings->save();
    }

    $destination = null;
    StandaloneDocker::withoutEvents(function () use ($server, &$destination, $overrides) {
        $destination = StandaloneDocker::firstOrCreate(
            ['server_id' => $server->id, 'network' => $overrides['network'] ?? 'coolify'],
            [
                'uuid' => $overrides['destinationUuid'] ?? 'docker-destination-1',
                'name' => $overrides['destinationName'] ?? 'coolify',
            ]
        );
    });

    $project = Project::create([
        'uuid' => $overrides['projectUuid'] ?? 'project-browser',
        'name' => $overrides['projectName'] ?? 'Browser Project',
        'description' => $overrides['projectDescription'] ?? 'Browser test project',
        'team_id' => 0,
    ]);

    $environment = $project->environments()->first();

    Team::query()->whereKey(0)->update(['show_boarding' => false]);

    return compact('user', 'privateKey', 'server', 'destination', 'project', 'environment');
}

function createBrowserApplication(array $stack, array $attributes = []): Application
{
    return Application::factory()->create(array_merge([
        'uuid' => $attributes['uuid'] ?? 'app-browser-'.Str::lower(Str::random(8)),
        'name' => $attributes['name'] ?? 'Browser App',
        'description' => $attributes['description'] ?? 'Browser test application',
        'git_repository' => $attributes['git_repository'] ?? 'https://github.com/coollabsio/coolify.git',
        'git_branch' => $attributes['git_branch'] ?? 'main',
        'build_pack' => $attributes['build_pack'] ?? 'nixpacks',
        'ports_exposes' => $attributes['ports_exposes'] ?? '3000',
        'environment_id' => $stack['environment']->id,
        'destination_id' => $stack['destination']->id,
        'destination_type' => $stack['destination']->getMorphClass(),
    ], $attributes));
}

function createBrowserPostgresql(array $stack, array $attributes = []): StandalonePostgresql
{
    return StandalonePostgresql::create(array_merge([
        'uuid' => $attributes['uuid'] ?? 'db-pg-'.Str::lower(Str::random(8)),
        'name' => $attributes['name'] ?? 'Browser Postgres',
        'description' => $attributes['description'] ?? 'Browser test database',
        'postgres_user' => $attributes['postgres_user'] ?? 'postgres',
        'postgres_password' => $attributes['postgres_password'] ?? 'postgres-password',
        'postgres_db' => $attributes['postgres_db'] ?? 'postgres',
        'image' => $attributes['image'] ?? 'postgres:15-alpine',
        'status' => $attributes['status'] ?? 'exited',
        'environment_id' => $stack['environment']->id,
        'destination_id' => $stack['destination']->id,
        'destination_type' => $stack['destination']->getMorphClass(),
    ], $attributes));
}

function createBrowserRedis(array $stack, array $attributes = []): StandaloneRedis
{
    // redis_password was moved to environment variables (no column on standalone_redis).
    return StandaloneRedis::forceCreate(array_merge([
        'uuid' => $attributes['uuid'] ?? 'db-redis-'.Str::lower(Str::random(8)),
        'name' => $attributes['name'] ?? 'Browser Redis',
        'description' => $attributes['description'] ?? 'Browser test redis',
        'image' => $attributes['image'] ?? 'redis:7-alpine',
        'status' => $attributes['status'] ?? 'exited',
        'environment_id' => $stack['environment']->id,
        'destination_id' => $stack['destination']->id,
        'destination_type' => $stack['destination']->getMorphClass(),
    ], $attributes));
}

/**
 * @return array{service: Service, serviceApplication: ServiceApplication}
 */
function createBrowserService(array $stack, array $attributes = []): array
{
    $service = Service::factory()->create(array_merge([
        'uuid' => $attributes['uuid'] ?? 'svc-'.Str::lower(Str::random(8)),
        'name' => $attributes['name'] ?? 'Browser Service',
        'description' => $attributes['description'] ?? 'Browser test compose service',
        'environment_id' => $stack['environment']->id,
        'server_id' => $stack['server']->id,
        'destination_id' => $stack['destination']->id,
        'destination_type' => $stack['destination']->getMorphClass(),
        'docker_compose_raw' => $attributes['docker_compose_raw'] ?? "services:\n  web:\n    image: nginx:alpine\n    ports:\n      - '80'\n",
    ], $attributes));

    $serviceApplication = ServiceApplication::forceCreate([
        'uuid' => $attributes['serviceApplicationUuid'] ?? (string) Str::uuid(),
        'name' => $attributes['serviceApplicationName'] ?? 'web',
        'service_id' => $service->id,
        'image' => $attributes['image'] ?? 'nginx:alpine',
    ]);

    return compact('service', 'serviceApplication');
}

function applicationConfigurationUrl(Project $project, $environment, Application $application): string
{
    return "/project/{$project->uuid}/environment/{$environment->uuid}/application/{$application->uuid}";
}

function databaseConfigurationUrl(Project $project, $environment, StandalonePostgresql|StandaloneRedis $database): string
{
    return "/project/{$project->uuid}/environment/{$environment->uuid}/database/{$database->uuid}";
}

function serviceConfigurationUrl(Project $project, $environment, Service $service): string
{
    return "/project/{$project->uuid}/environment/{$environment->uuid}/service/{$service->uuid}";
}

/**
 * Choose an option from a Coolify Alpine listbox (`x-forms.listbox`).
 *
 * Opens the trigger `#{$id}-trigger`, then clicks the option whose label matches
 * within that listbox only (avoids matching same labels on other listboxes, e.g.
 * Build strategy "Static" vs Site type "Static").
 *
 * Uses DOM evaluation for the option click so labels with CSS-special characters
 * like parentheses (e.g. "SPA (single-page application)", "allow (insecure)") work.
 */
function selectListboxOption(mixed $page, string $id, string $optionLabel, float $waitSeconds = 1.5): void
{
    $escapedId = json_encode($id, JSON_THROW_ON_ERROR);
    $escapedLabel = json_encode($optionLabel, JSON_THROW_ON_ERROR);

    $opened = $page->script(<<<JS
        (() => {
            const id = {$escapedId};
            const trigger = document.querySelector('#' + id + '-trigger');
            if (!trigger) {
                return 'missing-trigger';
            }
            trigger.click();
            return 'opened';
        })()
    JS);

    if ($opened !== 'opened') {
        throw new RuntimeException("Listbox trigger #{$id}-trigger not found.");
    }

    $page->wait(0.4);

    $clicked = $page->script(<<<JS
        (() => {
            const id = {$escapedId};
            const label = {$escapedLabel};
            const trigger = document.querySelector('#' + id + '-trigger');
            if (!trigger) {
                return 'missing-trigger';
            }

            // Scope to the listbox root that owns this trigger.
            const root = trigger.closest('.relative') || trigger.parentElement;
            const options = Array.from((root || document).querySelectorAll('[role="option"]'));
            const match = options.find((el) => {
                if (el.offsetParent === null && getComputedStyle(el).display === 'none') {
                    return false;
                }
                const text = (el.textContent || '').replace(/\\s+/g, ' ').trim();
                return text === label;
            });

            if (!match) {
                return 'missing-option:' + options.map((el) => (el.textContent || '').trim()).join('|');
            }

            match.click();
            return 'clicked';
        })()
    JS);

    if ($clicked !== 'clicked') {
        throw new RuntimeException("Listbox option [{$optionLabel}] not selected for #{$id}: {$clicked}");
    }

    $page->wait($waitSeconds);
}

/**
 * Submit the primary Livewire settings form on the page.
 */
function submitLivewireForm(mixed $page, string $submitAction = 'submit'): void
{
    $escaped = addcslashes($submitAction, '"\\');
    $page->script(<<<JS
        (() => {
            const forms = Array.from(document.querySelectorAll('form'));
            const form = forms.find((candidate) => {
                const attrs = candidate.getAttributeNames();
                return attrs.some((name) => name.startsWith('wire:submit') && candidate.getAttribute(name) === "{$escaped}");
            }) || forms.find((candidate) => candidate.getAttributeNames().some((name) => name.startsWith('wire:submit')))
              || document.querySelector('form.application-settings-form');

            if (!form) {
                return;
            }

            // Prefer Livewire's submit hook when available; fall back to native submit.
            if (typeof form.requestSubmit === 'function') {
                form.requestSubmit();
            } else {
                form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
            }
        })()
    JS);
    $page->wait(2);
}
