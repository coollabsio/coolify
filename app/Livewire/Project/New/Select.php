<?php

namespace App\Livewire\Project\New;

use App\Models\Project;
use App\Models\Server;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Component;

class Select extends Component
{
    public $current_step = 'type';

    public ?Server $server = null;

    public string $type;

    public string $server_id;

    public string $destination_uuid;

    public Collection|null|Server $allServers;

    public Collection|null|Server $servers;

    public ?Collection $buildServers = null;

    public bool $onlyBuildServerAvailable = false;

    public ?Collection $standaloneDockers;

    public ?Collection $swarmDockers;

    public array $parameters;

    public Collection|array $services = [];

    public Collection|array $allServices = [];

    public bool $isDatabase = false;

    public bool $includeSwarm = true;

    public bool $loadingServices = true;

    public bool $loading = false;

    public $environments = [];

    public ?string $selectedEnvironment = null;

    public string $postgresql_type = 'postgres:16-alpine';

    public ?string $existingPostgresqlUrl = null;

    protected $queryString = [
        'server_id',
        'type' => ['except' => ''],
        'destination_uuid' => ['except' => '', 'as' => 'destination'],
    ];

    public function mount()
    {
        try {
            $this->parameters = get_route_parameters();
            if (isDev()) {
                $this->existingPostgresqlUrl = 'postgres://coolify:password@coolify-db:5432';
            }
            $projectUuid = data_get($this->parameters, 'project_uuid');
            $project = Project::ownedByCurrentTeam()->whereUuid($projectUuid)->firstOrFail();
            $this->environments = $project->environments;
            $this->selectedEnvironment = $this->environments->where('uuid', data_get($this->parameters, 'environment_uuid'))->firstOrFail()->name;

            // Check if we have all required params for PostgreSQL type selection
            // This handles navigation from global search
            $queryType = request()->query('type');
            $queryServerId = request()->query('server_id');
            $queryDestination = request()->query('destination');

            if ($queryType === 'postgresql' && $queryServerId !== null && $queryDestination) {
                $this->type = $queryType;
                $this->server_id = $queryServerId;
                $this->destination_uuid = $queryDestination;
                $this->server = Server::ownedByCurrentTeam()->find($queryServerId);
                $this->current_step = 'select-postgresql-type';
            }
        } catch (\Exception $e) {
            return handleError($e, $this);
        }
    }

    public function render()
    {
        return view('livewire.project.new.select');
    }

    public function updatedSelectedEnvironment()
    {
        $environmentUuid = $this->environments->where('name', $this->selectedEnvironment)->first()->uuid;

        return redirect()->route('project.resource.create', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_uuid' => $environmentUuid,
        ]);
    }

    public function loadServices()
    {
        $services = get_service_templates();
        $templateLastUpdatedMap = $this->serviceTemplateLastUpdatedMap($services);

        $services = collect($services)->map(function ($service, $key) use ($templateLastUpdatedMap) {
            $serviceKey = (string) $key;

            return [
                'id' => $serviceKey,
                'name' => str($serviceKey)->headline(),
                'docsSlug' => str($serviceKey)->lower()->value(),
                'templateLastUpdated' => $templateLastUpdatedMap[$serviceKey] ?? null,
            ] + service_logo_urls(data_get($service, 'logo')) + (array) $service;
        })->all();

        // Extract unique categories from services
        $categories = collect($services)
            ->pluck('category')
            ->filter()
            ->unique()
            ->map(function ($category) {
                // Handle multiple categories separated by comma
                if (str_contains($category, ',')) {
                    return collect(explode(',', $category))->map(fn ($cat) => trim($cat));
                }

                return [$category];
            })
            ->flatten()
            ->unique()
            ->map(function ($category) {
                // Format common acronyms to uppercase
                $acronyms = ['ai', 'api', 'ci', 'cd', 'cms', 'crm', 'erp', 'iot', 'vpn', 'vps', 'dns', 'ssl', 'tls', 'ssh', 'ftp', 'http', 'https', 'smtp', 'imap', 'pop3', 'sql', 'nosql', 'json', 'xml', 'yaml', 'csv', 'pdf', 'sms', 'mfa', '2fa', 'oauth', 'saml', 'jwt', 'rest', 'soap', 'grpc', 'graphql', 'websocket', 'webrtc', 'p2p', 'b2b', 'b2c', 'seo', 'sem', 'ppc', 'roi', 'kpi', 'ui', 'ux', 'ide', 'sdk', 'api', 'cli', 'gui', 'cdn', 'ddos', 'dos', 'xss', 'csrf', 'sqli', 'rce', 'lfi', 'rfi', 'ssrf', 'xxe', 'idor', 'owasp', 'gdpr', 'hipaa', 'pci', 'dss', 'iso', 'nist', 'cve', 'cwe', 'cvss'];
                $lower = strtolower($category);

                if (in_array($lower, $acronyms)) {
                    return strtoupper($category);
                }

                return $category;
            })
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();
        $gitBasedApplications = [
            [
                'id' => 'public',
                'name' => 'Public Git Repository',
                'description' => 'Deploy any public Git repository. Coolify builds it from source, no credentials required.',
                'documentation' => 'https://coolify.io/docs/applications/ci-cd',
                'logo' => asset('svgs/resources/public-repo.svg'),
            ],
            [
                'id' => 'private-deploy-key',
                'name' => 'Private Git Repository (with Deploy Key)',
                'description' => 'Deploy a private repository over SSH with a repository-scoped deploy key. Set the URL and branch manually, no automatic deploys.',
                'documentation' => 'https://coolify.io/docs/applications/ci-cd/github/deploy-key',
                'logo' => asset('svgs/resources/deploy-key.svg'),
            ],
            [
                'id' => 'private-gh-app',
                'name' => 'Git Repository (with GitHub App)',
                'description' => 'Deploy public or private GitHub repositories through a GitHub App, with automatic webhooks and pull request previews.',
                'documentation' => 'https://coolify.io/docs/applications/ci-cd/github/setup-app',
                'logo' => asset('svgs/resources/github-app.svg'),
                'logoDark' => asset('svgs/resources/github-app-dark.svg'),
            ],
            [
                'id' => 'private-gitlab-app',
                'name' => 'Git Repository (with GitLab App)',
                'description' => 'Deploy public or private GitLab projects through a GitLab App, with automatic webhooks and merge request previews.',
                'logo' => asset('svgs/resources/gitlab-app.svg'),
            ],
        ];
        $dockerBasedApplications = [
            [
                'id' => 'dockerfile',
                'name' => 'Dockerfile',
                'description' => 'Deploy an application using a Dockerfile, without a Git repository.',
                'documentation' => 'https://coolify.io/docs/applications/build-packs/dockerfile',
                'logo' => asset('svgs/resources/dockerfile.svg'),
            ],
            [
                'id' => 'docker-compose-empty',
                'name' => 'Docker Compose',
                'description' => 'Deploy a multi-container application using a Docker Compose file, without a Git repository.',
                'documentation' => 'https://coolify.io/docs/applications/build-packs/docker-compose',
                'logo' => asset('svgs/resources/docker-compose.svg'),
            ],
            [
                'id' => 'docker-image',
                'name' => 'Docker Image',
                'description' => 'Deploy an application using a prebuilt image from any Docker registry, without a Git repository.',
                'documentation' => 'https://coolify.io/docs/applications',
                'logo' => asset('svgs/resources/docker-image.svg'),
            ],
        ];
        $databases = [
            [
                'id' => 'postgresql',
                'name' => 'PostgreSQL',
                'description' => 'A relational database with strong SQL standards support and extensibility.',
                'logo' => asset('svgs/resources/postgres.svg'),
            ],
            [
                'id' => 'mysql',
                'name' => 'MySQL',
                'description' => 'A relational database for web and general-purpose applications.',
                'logo' => asset('svgs/resources/mysql.svg'),

            ],
            [
                'id' => 'mariadb',
                'name' => 'MariaDB',
                'description' => 'A relational database and drop-in replacement for MySQL.',
                'logo' => asset('svgs/resources/mariadb.svg'),
            ],
            [
                'id' => 'redis',
                'name' => 'Redis',
                'description' => 'An in-memory key-value store used as a database, cache, and message broker.',
                'logo' => asset('svgs/resources/redis.svg'),
            ],
            [
                'id' => 'keydb',
                'name' => 'KeyDB',
                'description' => 'A multithreaded, Redis-compatible in-memory store.',
                'logo' => asset('svgs/resources/keydb.svg'),
                'logoDark' => asset('svgs/resources/keydb-dark.svg'),
            ],
            [
                'id' => 'dragonfly',
                'name' => 'Dragonfly',
                'description' => 'An in-memory datastore compatible with Redis and Memcached.',
                'logo' => asset('svgs/resources/dragonfly.svg'),
                'logoDark' => asset('svgs/resources/dragonfly-dark.svg'),
            ],
            [
                'id' => 'mongodb',
                'name' => 'MongoDB',
                'description' => 'A document-oriented NoSQL database that stores JSON-like documents.',
                'logo' => asset('svgs/resources/mongodb.svg'),
            ],
            [
                'id' => 'clickhouse',
                'name' => 'ClickHouse',
                'description' => 'A column-oriented database for real-time analytics over large datasets.',
                'logo' => asset('svgs/resources/clickhouse.svg'),
            ],

        ];

        return [
            'serviceTemplatesLastUpdated' => $this->serviceTemplatesLastUpdated(),
            'services' => $services,
            'categories' => $categories,
            'gitBasedApplications' => $gitBasedApplications,
            'dockerBasedApplications' => $dockerBasedApplications,
            'databases' => $databases,
        ];
    }

    public function instantSave()
    {
        if ($this->includeSwarm) {
            $this->servers = $this->allServers;
        } else {
            if ($this->allServers instanceof Collection) {
                $this->servers = $this->allServers->where('settings.is_swarm_worker', false)->where('settings.is_swarm_manager', false)->where('settings.is_build_server', false);
            } else {
                $this->servers = $this->allServers;
            }
        }
    }

    private function serviceTemplatesLastUpdated(): ?string
    {
        $fetchedAt = get_service_templates_fetched_at();
        if ($fetchedAt instanceof CarbonImmutable) {
            return $fetchedAt
                ->timezone(config('app.timezone'))
                ->format('M j, Y H:i');
        }

        return $this->formatLastModified($this->serviceTemplatesPath());
    }

    private function serviceTemplateLastUpdatedMap(Collection $services): array
    {
        return $services
            ->mapWithKeys(fn ($service, $serviceName) => [
                (string) $serviceName => $this->serviceTemplateLastUpdatedFromPayload($service)
                    ?? $this->serviceTemplateLastUpdated((string) $serviceName),
            ])
            ->all();
    }

    private function serviceTemplateLastUpdatedFromPayload(mixed $service): ?string
    {
        $timestamp = data_get($service, 'template_last_updated_at');

        if (! is_string($timestamp) || $timestamp === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($timestamp)
                ->timezone(config('app.timezone'))
                ->format('M j, Y H:i');
        } catch (\Throwable) {
            return null;
        }
    }

    private function serviceTemplateLastUpdated(string $serviceName): ?string
    {
        foreach (['yaml', 'yml'] as $extension) {
            $templatePath = base_path("templates/compose/{$serviceName}.{$extension}");

            if (file_exists($templatePath)) {
                return $this->formatLastModified($templatePath);
            }
        }

        return null;
    }

    private function serviceTemplatesPath(): string
    {
        return base_path('templates/'.config('constants.services.file_name'));
    }

    private function formatLastModified(string $path): ?string
    {
        if (! file_exists($path)) {
            return null;
        }

        return CarbonImmutable::createFromTimestamp(filemtime($path))
            ->timezone(config('app.timezone'))
            ->format('M j, Y H:i');
    }

    public function setType(string $type)
    {
        if (! str($type)->startsWith('one-click-service-')) {
            $type = str($type)->lower()->slug()->value();
        }

        if ($this->loading) {
            return;
        }
        $this->loading = true;
        $this->type = $type;
        switch ($type) {
            case 'postgresql':
            case 'mysql':
            case 'mariadb':
            case 'redis':
            case 'keydb':
            case 'dragonfly':
            case 'clickhouse':
            case 'mongodb':
                $this->isDatabase = true;
                $this->includeSwarm = false;
                if ($this->allServers instanceof Collection) {
                    $this->servers = $this->allServers->where('settings.is_swarm_worker', false)->where('settings.is_swarm_manager', false)->where('settings.is_build_server', false);
                } else {
                    $this->servers = $this->allServers;
                }
                break;
        }
        if (str($type)->startsWith('one-click-service') || str($type)->startsWith('docker-compose-empty')) {
            $this->isDatabase = true;
            $this->includeSwarm = false;
            if ($this->allServers instanceof Collection) {
                $this->servers = $this->allServers->where('settings.is_swarm_worker', false)->where('settings.is_swarm_manager', false)->where('settings.is_build_server', false);
            } else {
                $this->servers = $this->allServers;
            }
        }
        if ($type === 'existing-postgresql') {
            $this->current_step = $type;

            return;
        }
        if (count($this->servers) === 1 && $this->buildServers?->isEmpty()) {
            $server = $this->servers->first();
            if ($server instanceof Server) {
                $this->setServer($server);
            }
        }
        if (! is_null($this->server)) {
            $foundServer = $this->servers->where('id', $this->server->id)->first();
            if ($foundServer) {
                return $this->setServer($foundServer);
            }
        }
        $this->current_step = 'servers';
    }

    public function setServer(Server $server)
    {
        $this->server_id = $server->id;
        $this->server = $server;
        $this->standaloneDockers = $server->standaloneDockers;
        $this->swarmDockers = $server->swarmDockers;
        $count = count($this->standaloneDockers) + count($this->swarmDockers);
        if ($count === 1) {
            $docker = $this->standaloneDockers->first() ?? $this->swarmDockers->first();
            if ($docker) {
                $this->setDestination($docker->uuid);

                return $this->whatToDoNext();
            }
        }
        $this->current_step = 'destinations';
    }

    public function setDestination(string $destination_uuid)
    {
        $this->destination_uuid = $destination_uuid;

        return $this->whatToDoNext();
    }

    public function setPostgresqlType(string $type)
    {
        $this->postgresql_type = $type;

        return redirect()->route('project.resource.create', [
            'project_uuid' => $this->parameters['project_uuid'],
            'environment_uuid' => $this->parameters['environment_uuid'],
            'type' => $this->type,
            'destination' => $this->destination_uuid,
            'server_id' => $this->server_id,
            'database_image' => $this->postgresql_type,
        ]);
    }

    public function whatToDoNext()
    {
        if ($this->type === 'postgresql') {
            $this->current_step = 'select-postgresql-type';
        } else {
            return redirect()->route('project.resource.create', [
                'project_uuid' => $this->parameters['project_uuid'],
                'environment_uuid' => $this->parameters['environment_uuid'],
                'type' => $this->type,
                'destination' => $this->destination_uuid,
                'server_id' => $this->server_id,
            ]);
        }
    }

    public function loadServers()
    {
        $this->servers = Server::isUsable()->get()->sortBy('name');
        $this->buildServers = Server::isUsableBuildServer()->get()->sortBy('name');
        $this->allServers = $this->servers->concat($this->buildServers);
        $this->onlyBuildServerAvailable = $this->servers->isEmpty() && $this->buildServers->isNotEmpty();
    }
}
