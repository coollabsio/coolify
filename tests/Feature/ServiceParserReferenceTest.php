<?php

/**
 * Golden test for the compose parsers (serviceParser + updateCompose, and applicationParser), latest compose
 * parsing version only.
 *
 * Every case parses a compose file in three steps, the same way the UI does:
 *   1. deploy (create + start parse the compose twice),
 *   2. custom domains: each generated host becomes <name>.example.com, paths are kept (shared hosts stay shared),
 *   3. a domain saved on each application that had none (for example a worker that copies a declaration).
 * The result (domains, environment variable rows, storages, generated compose with proxy labels, or the error)
 * is compared with tests/Fixtures/parser/expected/<case>.yaml.
 *
 * Cases: tests/Fixtures/parser/service-reference.yaml (one service per parser feature) with server and
 * application setting variations, an error-path fixture, and every template in templates/compose (also the
 * `# ignore: true` ones: existing services can still use them).
 *
 * Applications: tests/Fixtures/parser/application-reference.yaml (Docker Compose build pack) in three steps:
 * deploy, custom domains on the General page, and pull request preview deployments.
 *
 * Only the reference expected files are committed. Template expected files are gitignored: when one is missing,
 * the case only checks that the template parses without an error, and it writes the file.
 *
 * Before/after check of a parser change for every template:
 *   ./scripts/parser-snapshot-diff save       (before the change)
 *   ./scripts/parser-snapshot-diff baseline   (after the change; or: ./scripts/parser-snapshot-diff <git-ref>)
 * When a change of the reference output is intended, regenerate the files and review the diff:
 *   UPDATE_PARSER_SNAPSHOTS=1 php artisan test --compact tests/Feature/ServiceParserReferenceTest.php
 */

use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Every volume dispatches a job that reads files over SSH.
    Bus::fake();
    Queue::fake();
    Server::flushIdentityMap();
    InstanceSettings::forceCreate(['id' => 0]);
});

it('parses the compose exactly as recorded', function (string $composePath, ?string $serviceType, array $options, string $expectedName) {
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'reference-server', 'ip' => '10.0.0.1']);
    $server->settings->update($options['server'] ?? []);
    if (isset($options['proxy'])) {
        $server->proxy->set('type', $options['proxy']);
        $server->save();
    }
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'Reference Project']);
    $environment = Environment::where('project_id', $project->id)->where('name', 'production')->first()
        ?? Environment::factory()->create(['project_id' => $project->id, 'name' => 'production']);
    SharedEnvironmentVariable::create([
        'key' => 'SHARED_KEY', 'value' => 'shared-value', 'type' => 'environment',
        'team_id' => $team->id, 'project_id' => $project->id, 'environment_id' => $environment->id,
    ]);

    $service = Service::factory()->create([
        'name' => 'reference',
        'service_type' => $serviceType,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => file_get_contents(base_path($composePath)),
    ]);
    $service->forceFill(['uuid' => 'referenceservice00000001'])->save();

    // A user converted these compose services to the other type before the first parse.
    foreach ($options['migrated'] ?? [] as $name => $type) {
        ($type === 'database' ? ServiceDatabase::class : ServiceApplication::class)::create(['name' => $name, 'service_id' => $service->id, 'is_migrated' => true]);
    }

    $snapshot = [];
    $step = function (string $label, Closure $before) use ($service, &$snapshot): bool {
        try {
            $before();
            $snapshot[$label] = serviceParserReferenceSnapshot($service->fresh(), $service->fresh()->parse());

            return true;
        } catch (Throwable $e) {
            $snapshot[$label] = ['error' => class_basename($e).': '.$e->getMessage()];

            return false;
        }
    };
    $saveDomain = function (ServiceApplication $application, string $fqdn): void {
        $application->fqdn = $fqdn;
        $application->save();
        updateCompose($application->fresh());
    };

    // Step 1: create + deploy (Create.php parses once, StartService parses again), then application settings.
    $deployed = $step('1_deploy', function () use ($service, $options) {
        $service->fresh()->parse();
        foreach ($options['applications'] ?? [] as $name => $settings) {
            $application = $service->applications()->where('name', $name)->firstOrFail();
            $noindex = data_get($settings, 'noindex');
            unset($settings['noindex']);
            $application->fill($settings);
            if ($noindex) {
                $application->setNoindexDomains(explode(',', (string) $application->fqdn));
            }
            $application->save();
        }
    });

    if ($deployed) {
        // Step 2: custom domains saved like the Domains page does (save fqdn, updateCompose, parse).
        $step('2_custom_domains', function () use ($service, $saveDomain) {
            foreach ($service->fresh()->applications()->whereNotNull('fqdn')->where('fqdn', '!=', '')->orderBy('name')->get() as $application) {
                $saveDomain($application, serviceParserReferenceCustomDomains($application->fqdn));
            }
        });

        // Step 3: a domain saved on each application without one; copies must not move the shared variables.
        $step('3_domain_on_applications_without_one', function () use ($service, $saveDomain) {
            foreach ($service->fresh()->applications()->orderBy('name')->get()->filter(fn ($application) => blank($application->fqdn)) as $application) {
                $saveDomain($application, "https://{$application->name}-extra.example.com");
            }
        });
    }

    $actual = Yaml::dump(serviceParserReferenceNormalize($service->fresh(), $snapshot), 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    $expectedPath = base_path("tests/Fixtures/parser/expected/{$expectedName}.yaml");

    if (getenv('UPDATE_PARSER_SNAPSHOTS') || ! file_exists($expectedPath)) {
        @mkdir(dirname($expectedPath), 0755, true);
        file_put_contents($expectedPath, $actual);
    }

    expect($actual)->toBe(file_get_contents($expectedPath));
})->with(fn () => serviceParserReferenceCases());

it('parses the application compose exactly as recorded', function (array $options, string $expectedName) {
    // Production container names of compose applications end with the deployment time.
    $this->travelTo(CarbonImmutable::parse('2026-01-02 03:04:05'));
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id, 'name' => 'reference-server', 'ip' => '10.0.0.1']);
    $server->settings->update($options['server'] ?? []);
    if (isset($options['proxy'])) {
        $server->proxy->set('type', $options['proxy']);
        $server->save();
    }
    $destination = StandaloneDocker::where('server_id', $server->id)->first()
        ?? StandaloneDocker::factory()->create(['server_id' => $server->id]);
    $project = Project::factory()->create(['team_id' => $team->id, 'name' => 'Reference Project']);
    $environment = Environment::where('project_id', $project->id)->where('name', 'production')->first()
        ?? Environment::factory()->create(['project_id' => $project->id, 'name' => 'production']);
    SharedEnvironmentVariable::create([
        'key' => 'SHARED_KEY', 'value' => 'shared-value', 'type' => 'environment',
        'team_id' => $team->id, 'project_id' => $project->id, 'environment_id' => $environment->id,
    ]);

    $application = Application::factory()->create([
        'name' => 'reference',
        'build_pack' => 'dockercompose',
        'environment_id' => $environment->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
        'docker_compose_raw' => file_get_contents(base_path('tests/Fixtures/parser/application-reference.yaml')),
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'git_branch' => 'main',
    ]);
    $application->forceFill(['uuid' => 'referenceapplication0001'])->save();
    $application->settings->update($options['settings'] ?? []);
    $commit = 'abcdef0123456789abcdef0123456789abcdef01';

    $snapshot = [];
    $step = function (string $label, Closure $parse) use ($application, &$snapshot): void {
        try {
            $compose = $parse();
            $snapshot[$label] = serviceParserReferenceApplicationSnapshot($application->fresh(), $compose);
        } catch (Throwable $e) {
            $snapshot[$label] = ['error' => class_basename($e).': '.$e->getMessage()];
        }
    };

    // Step 1: first deploy (the parser fills docker_compose_domains from SERVICE_URL_/SERVICE_FQDN_ declarations).
    $step('1_deploy', fn () => $application->fresh()->parse(commit: $commit));

    // Step 2: custom domains saved on the General page: one per service, shared paths kept.
    $step('2_custom_domains', function () use ($application, $commit) {
        $application = $application->fresh();
        $domains = collect(json_decode($application->docker_compose_domains ?: '[]', true))
            ->map(fn (array $entry) => ['domain' => filled($entry['domain'] ?? null) ? serviceParserReferenceCustomDomains($entry['domain']) : null])
            ->put('api.v2', ['domain' => 'https://api-v2.example.com'])
            ->put('api', ['domain' => 'https://www.api.example.com', 'redirect' => 'non-www'])
            ->all();
        $application->docker_compose_domains = json_encode($domains);
        $application->save();

        return $application->fresh()->parse(commit: $commit);
    });

    // Step 3: pull request deployment (preview domains from preview_url_template, preview volume names).
    $step('3_preview', function () use ($application, $commit) {
        $preview = ApplicationPreview::create([
            'uuid' => 'referencepreview00000001',
            'application_id' => $application->id,
            'pull_request_id' => 7,
            'pull_request_html_url' => 'https://github.com/example/repository/pull/7',
        ]);
        // The pull request webhook and ApplicationDeploymentJob generate the preview domains before the parse.
        $preview->generate_preview_fqdn_compose();

        return $application->fresh()->parse(pull_request_id: 7, preview_id: $preview->id, commit: $commit);
    });

    // Step 4: preview without generated preview domains: the parser generates them from the application domains.
    $step('4_preview_without_generated_domains', function () use ($application, $commit) {
        $preview = ApplicationPreview::create([
            'uuid' => 'referencepreview00000002',
            'application_id' => $application->id,
            'pull_request_id' => 8,
            'pull_request_html_url' => 'https://github.com/example/repository/pull/8',
        ]);

        return $application->fresh()->parse(pull_request_id: 8, preview_id: $preview->id, commit: $commit);
    });

    $actual = Yaml::dump(serviceParserReferenceApplicationNormalize($application->fresh(), $snapshot), 12, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    $expectedPath = base_path("tests/Fixtures/parser/expected/{$expectedName}.yaml");

    if (getenv('UPDATE_PARSER_SNAPSHOTS') || ! file_exists($expectedPath)) {
        file_put_contents($expectedPath, $actual);
    }

    expect($actual)->toBe(file_get_contents($expectedPath));
})->with([
    'application' => [[], 'application'],
    'application with exact traefik labels' => [['proxy' => 'TRAEFIK', 'server' => ['generate_exact_labels' => true]], 'application-with-exact-traefik-labels'],
    'application with exact caddy labels' => [['proxy' => 'CADDY', 'server' => ['generate_exact_labels' => true]], 'application-with-exact-caddy-labels'],
    'application with settings' => [[
        'server' => ['is_logdrain_custom_enabled' => true],
        'settings' => ['is_gzip_enabled' => false, 'is_stripprefix_enabled' => false, 'is_container_label_escape_enabled' => false, 'is_log_drain_enabled' => true, 'connect_to_docker_network' => true],
    ], 'application-with-settings'],
]);

/**
 * @return array<string, mixed>
 */
function serviceParserReferenceApplicationSnapshot(Application $application, Collection $compose): array
{
    return [
        'docker_compose_domains' => json_decode($application->docker_compose_domains ?: '[]', true),
        'previews' => $application->previews()->orderBy('pull_request_id')->get()
            ->mapWithKeys(fn (ApplicationPreview $preview) => [$preview->pull_request_id => array_filter([
                'fqdn' => $preview->fqdn,
                'docker_compose_domains' => json_decode($preview->docker_compose_domains ?: '[]', true),
            ])])->all(),
        'volumes' => $application->persistentStorages()->orderBy('name')->get()
            ->map(fn ($volume) => "{$volume->name}:{$volume->mount_path}")->all(),
        'files' => $application->fileStorages()->orderBy('mount_path')->get()
            ->map(fn ($file) => array_filter([
                'fs_path' => $file->fs_path,
                'mount_path' => $file->mount_path,
                'is_directory' => (bool) $file->is_directory,
                'content' => $file->content,
            ], fn ($value) => $value !== null))->all(),
        'environment_variables' => $application->environment_variables()->orderBy('key')->get()->mapWithKeys(fn ($variable) => [
            $variable->key => $variable->is_required ? ['value' => $variable->value, 'required' => true] : $variable->value,
        ])->all(),
        'compose' => $compose->toArray(),
    ];
}

/**
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function serviceParserReferenceApplicationNormalize(Application $application, array $snapshot): array
{
    $replacements = [config('constants.coolify.version') => '<coolify-version>'];
    foreach ($application->environment_variables()->get()->concat($application->environment_variables_preview()->get()) as $variable) {
        if (filled($variable->value) && preg_match('/^SERVICE_(PASSWORD|BASE64|REALBASE64|HEX|USER|LOWERCASEUSER)/', $variable->key)) {
            $replacements[$variable->value] = "<generated:{$variable->key}:".strlen($variable->value).'>';
        }
    }
    uksort($replacements, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

    array_walk_recursive($snapshot, function (&$value) use ($replacements) {
        if (is_string($value)) {
            $value = strtr($value, $replacements);
        }
    });

    return $snapshot;
}

/**
 * @return array<string, array{0: string, 1: ?string, 2: array<string, mixed>, 3: string}>
 */
function serviceParserReferenceCases(): array
{
    $reference = 'tests/Fixtures/parser/service-reference.yaml';
    $cases = [
        'reference' => [$reference, null, []],
        'reference with exact traefik labels' => [$reference, null, ['proxy' => 'TRAEFIK', 'server' => ['generate_exact_labels' => true]]],
        'reference with exact caddy labels' => [$reference, null, ['proxy' => 'CADDY', 'server' => ['generate_exact_labels' => true, 'is_traffic_analytics_enabled' => true]]],
        'reference with application settings' => [$reference, null, [
            'server' => ['is_logdrain_custom_enabled' => true],
            'applications' => [
                'web' => ['is_gzip_enabled' => false, 'is_force_https_enabled' => false, 'redirect' => 'www', 'noindex' => true, 'is_log_drain_enabled' => true],
                'api' => ['is_stripprefix_enabled' => false],
                'www' => ['redirect' => 'non-www'],
            ],
        ]],
        'reference with migrated services' => [$reference, null, ['migrated' => ['db' => 'application', 'sidecar' => 'database']]],
        'unsafe volume path' => ['tests/Fixtures/parser/service-unsafe.yaml', null, []],
        'unsafe service name' => ['tests/Fixtures/parser/service-unsafe-name.yaml', null, []],
        'invalid yaml' => ['tests/Fixtures/parser/service-invalid-yaml.yaml', null, []],
    ];

    foreach (glob(dirname(__DIR__, 2).'/templates/compose/*.y*ml') as $template) {
        $name = pathinfo($template, PATHINFO_FILENAME);
        $cases["template {$name}"] = ['templates/compose/'.basename($template), $name, []];
    }

    return collect($cases)->mapWithKeys(fn (array $case, string $name) => [
        $name => [...$case, str_starts_with($name, 'template ') ? 'templates/'.Str::after($name, 'template ') : Str::slug($name)],
    ])->all();
}

/**
 * Replace each generated host with <name>.example.com and keep the port and path, so services that share a host still do.
 */
function serviceParserReferenceCustomDomains(string $fqdns): string
{
    return collect(explode(',', $fqdns))->map(function (string $fqdn) {
        $host = parse_url(trim($fqdn), PHP_URL_HOST);
        $name = Str::before(Str::before($host, '-referenceservice00000001'), '-referenceapplication0001');
        $name = Str::before($name, '.');

        $port = parse_url(trim($fqdn), PHP_URL_PORT);

        return 'https://'.$name.'.example.com'.($port ? ":{$port}" : '').(parse_url(trim($fqdn), PHP_URL_PATH) ?? '');
    })->implode(',');
}

/**
 * @return array<string, mixed>
 */
function serviceParserReferenceSnapshot(Service $service, Collection $compose): array
{
    $storages = fn ($resource) => array_filter([
        'volumes' => $resource->persistentStorages()->orderBy('name')->get()
            ->map(fn ($volume) => "{$volume->name}:{$volume->mount_path}")->all(),
        'files' => $resource->fileStorages()->orderBy('mount_path')->get()
            ->map(fn ($file) => array_filter([
                'fs_path' => $file->fs_path,
                'mount_path' => $file->mount_path,
                'is_directory' => (bool) $file->is_directory,
                'content' => $file->content,
            ], fn ($value) => $value !== null))->all(),
    ]);

    return [
        'applications' => $service->applications()->orderBy('name')->get()->mapWithKeys(fn ($application) => [
            $application->name => array_filter([
                'fqdn' => $application->fqdn,
                'required_port' => $application->getRequiredPort(),
                'exclude_from_status' => (bool) $application->exclude_from_status,
                'ports' => $application->ports,
                ...$storages($application),
            ], fn ($value) => $value !== null && $value !== []),
        ])->all(),
        'databases' => $service->databases()->orderBy('name')->get()->mapWithKeys(fn ($database) => [
            $database->name => array_filter([
                'exclude_from_status' => (bool) $database->exclude_from_status,
                ...$storages($database),
            ], fn ($value) => $value !== null && $value !== []),
        ])->all(),
        'environment_variables' => $service->environment_variables()->orderBy('key')->get()->mapWithKeys(fn ($variable) => [
            $variable->key => $variable->is_required ? ['value' => $variable->value, 'required' => true] : $variable->value,
        ])->all(),
        'compose' => $compose->toArray(),
    ];
}

/**
 * Replace values that change on every run: generated secrets, uuids of generated rows, Coolify version.
 *
 * @param  array<string, mixed>  $snapshot
 * @return array<string, mixed>
 */
function serviceParserReferenceNormalize(Service $service, array $snapshot): array
{
    $replacements = [config('constants.coolify.version') => '<coolify-version>'];
    foreach ($service->applications()->get()->concat($service->databases()->get()) as $resource) {
        $replacements[$resource->uuid] = "<uuid:{$resource->name}>";
    }
    foreach ($service->environment_variables()->get() as $variable) {
        if (filled($variable->value) && preg_match('/^SERVICE_(PASSWORD|BASE64|REALBASE64|HEX|USER|LOWERCASEUSER|SUPABASEANON|SUPABASESERVICE)/', $variable->key)) {
            $replacements[$variable->value] = "<generated:{$variable->key}:".strlen($variable->value).'>';
        }
    }
    uksort($replacements, fn ($a, $b) => strlen((string) $b) <=> strlen((string) $a));

    array_walk_recursive($snapshot, function (&$value) use ($replacements) {
        if (is_string($value)) {
            $value = strtr($value, $replacements);
        }
    });

    return $snapshot;
}
