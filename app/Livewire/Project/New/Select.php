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
            $default_logo = 'svgs/default.webp';
            $logo = data_get($service, 'logo');

            if (is_string($logo) && str_starts_with($logo, 'svg/')) {
                $normalizedLogo = 'svgs/'.str($logo)->after('svg/');
                if (file_exists(public_path($normalizedLogo))) {
                    $logo = $normalizedLogo;
                }
            }

            $hasLogo = is_string($logo)
                && basename($logo) !== basename($default_logo)
                && file_exists(public_path($logo));

            if (! $hasLogo) {
                $logo = $default_logo;
            }

            $local_logo_path = public_path($logo);
            $serviceKey = (string) $key;
            $logoIsUrl = str_starts_with($logo, 'http://') || str_starts_with($logo, 'https://') || str_starts_with($logo, '//');
            $logoUrl = $logoIsUrl || str_starts_with($logo, '/') ? $logo : '/'.$logo;

            return [
                'id' => $serviceKey,
                'name' => str($serviceKey)->headline(),
                'docsSlug' => str($serviceKey)->lower()->value(),
                'has_logo' => $hasLogo,
                'logo' => asset($logo),
                'logo_github_url' => file_exists($local_logo_path)
                    ? 'https://raw.githubusercontent.com/coollabsio/coolify/refs/heads/main/public/'.$logo
                    : $logoUrl,
                'templateLastUpdated' => $templateLastUpdatedMap[$serviceKey] ?? null,
            ] + (array) $service;
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
            [
                'id' => 'cassandra',
                'name' => 'Cassandra',
                'description' => 'Cassandra is a distributed wide-column NoSQL database designed to handle large amounts of data across many commodity servers.',
                'logo' => '<div class="w-[4.5rem] h-[4.5rem] p-2 transition-all duration-200 bg-black/10 dark:bg-white/10 flex items-center justify-center"><svg width="56" height="56" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48"><defs><clipPath id="a"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="b"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="c"><path d="M96.008 715.93h88.29v-62.176h-88.29z"/></clipPath><clipPath id="d"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="e"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="f"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="g"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="h"><path d="M121.2 708.38h45.899v-45.859H121.2z"/></clipPath><clipPath id="i"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="j"><path d="M40.403 726.19h212.4v-61.818h-212.4z"/></clipPath><clipPath id="k"><path d="M0 792h612V0H0z"/></clipPath><clipPath id="l"><path d="M39.52 688.64H238.9v-73.818H39.52z"/></clipPath></defs><g clip-path="url(#a)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><path fill="#bbe6fb" d="M210.88 690.48c1.584-18.452-27.455-36.014-64.859-39.223s-69.01 9.151-70.592 27.602c-1.584 18.455 27.455 36.016 64.859 39.225 37.404 3.208 69.01-9.149 70.592-27.604"/></g><g clip-path="url(#b)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><g clip-path="url(#c)" opacity=".35"><path fill="#fff" d="M141.38 715.92c-14.268.232-30.964-5.433-43.387-10.738a35.9 35.9 0 0 1-1.989-11.797c0-21.888 19.764-39.634 44.145-39.634s44.145 17.746 44.145 39.634c0 6.927-1.984 13.435-5.463 19.101-9.939 1.545-23.609 3.209-37.451 3.434"/></g></g><g clip-path="url(#d)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><path fill="#fff" d="M140.15 715.93c-7.899.482-21.514-3.639-32.867-7.75a33.7 33.7 0 0 1-2.683-13.201c0-19.178 17.388-34.725 35.782-34.725 18.273 0 34.44 15.572 35.782 34.725.436 6.237-1.711 12.114-4.692 17.181-11.77 2.073-24.261 3.339-31.322 3.77"/></g><g fill="#373535" clip-path="url(#e)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><path d="M119.88 697.49c.969 2.146 2.437 3.197 3.859 4.996-.158.426-.504 1.819-.504 2.302a3.907 3.907 0 0 0 3.906 3.906 3.9 3.9 0 0 0 1.44-.278c6.465 4.927 14.976 7.075 23.529 5.163a30 30 0 0 0 2.299-.623c-8.453 1.172-17.981-1.822-24.462-7.053.198-.467.747-1.802.747-2.339 0-2.157-1.396-2.682-3.553-2.682-.49 0-.958.094-1.391.259-1.464-1.966-3.258-5.711-4.136-8.052 3.584-3.206 6.822-4.368 11.042-5.945-.011.201.145.387.145.592 0 6.503 5.725 11.788 12.229 11.788 5.828 0 10.654-4.238 11.596-9.798 2.908 1.85 5.72 3.268 7.863 6.01-.5.61-1.039 2.337-1.039 3.187a3.545 3.545 0 0 0 3.545 3.544c.277 0 .543-.04.802-.1a31 31 0 0 1 2.434 7.05c-10.17 7.529-29.847 6.502-29.847 6.502s-15.658.817-26.258-4.349c.707-5.111 2.746-9.97 5.754-14.08"/><path d="M168.49 700.43a6.6 6.6 0 0 0 1.42-1.771c.951-1.71-.957-3.275-2.914-3.275a3.5 3.5 0 0 0-.582.059c-2.205-3.446-6.067-7.865-9.498-10.089 5.261-.862 10.222-2.969 14.17-6.225 2.875 5.151 5.08 12.589 5.08 18.907 0 4.809-2.123 8.334-5.328 10.92-.168-2.576-1.543-6.179-2.348-8.526m-42.71-32.63c1.753 4.841 6.065 8.592 10.144 11.892-.597.817-1.492 2.84-1.865 3.798a28.55 28.55 0 0 0-12.791 8.094c-.025-.109-.056-.215-.082-.324a25.9 25.9 0 0 1-.441-8.584 5.13 5.13 0 0 0 4.185-5.042c0-1.489-1.305-3.647-2.318-4.586 1.101-2.376 1.852-3.522 3.168-5.248"/><path d="M125.48 663.74c-2.091 2.079-3.537 6.226-4.894 8.83a5 5 0 0 0-.78-.066c-2.836 0-5.807 2.38-5.135 5.134.372 1.524 1.424 2.521 3.137 3.353-.39 3.157-.496 7.695.237 10.977.21.939.655 1.379.95 2.273-3.129 4.579-5.151 10.589-5.151 16.552 0 .218.011.433.016.649-5.288-2.652-9.253-6.83-9.253-13.407 0-14.548 8.379-28.819 20.846-34.413zm30.65 20.11c-1.611-4.582-5.967-7.873-11.1-7.873-2.746 0-5.265.947-7.267 2.521-4.127-3.214-7.871-8.86-9.774-13.758.854-.919 1.449-1.675 2.407-2.49 2.887-.752 6.863 0 9.988 0 12.57 0 23.703 5.592 30.086 15.398-4.244 2.939-9.25 5.736-14.34 6.202"/></g><g fill="#1287b1" clip-path="url(#f)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><path d="M119.56 695.79a28.5 28.5 0 0 0 3.605 5.931c-.158.425-.25.884-.25 1.367a3.907 3.907 0 0 0 3.906 3.906 3.9 3.9 0 0 0 1.44-.278c6.465 4.927 14.976 7.075 23.529 5.163a30 30 0 0 0 2.299-.623c-8.453 1.172-17.187-1.419-23.668-6.651a3.906 3.906 0 0 0-3.6-5.423c-.49 0-.958.094-1.391.259a26.3 26.3 0 0 1-3.539-6.48c3.078-3.317 6.856-5.94 11.075-7.517-.01.201-.031.4-.031.605 0 6.503 5.271 11.775 11.775 11.775 5.828 0 10.654-4.238 11.596-9.798 2.908 1.85 5.492 4.226 7.634 6.968a3.5 3.5 0 0 0-.81 2.229 3.545 3.545 0 0 0 3.545 3.544c.277 0 .543-.04.802-.1a31 31 0 0 1 2.434 7.05c-10.17 7.529-29.847 6.502-29.847 6.502s-15.658.817-26.258-4.349c.707-5.111 2.746-9.97 5.754-14.08"/><path d="M169.04 699.85a3.52 3.52 0 0 0 1.18-2.621 3.546 3.546 0 0 0-3.545-3.545 3.5 3.5 0 0 0-.582.059 28.7 28.7 0 0 0-8.559-8.608 28.66 28.66 0 0 0 13.793-6.201 35.6 35.6 0 0 1 4.518 17.402c0 4.809-2.123 8.334-5.328 10.92a28.5 28.5 0 0 0-1.477-7.406m-42.71-33.21a28.57 28.57 0 0 0 8.878 12.484 11.8 11.8 0 0 0-1.462 2.669 28.54 28.54 0 0 0-12.791 8.094c-.025-.109-.057-.215-.082-.324a25.8 25.8 0 0 1-.441-8.584 5.13 5.13 0 0 0 4.185-5.042 5.12 5.12 0 0 0-1.652-3.767 30.8 30.8 0 0 1 3.365-5.53"/><path d="M125.46 663.8a28.6 28.6 0 0 0-5.202 7.07 5 5 0 0 0-.78-.065 5.13 5.13 0 0 0-2.238 9.75 28.5 28.5 0 0 0 1.238 12.463 28.4 28.4 0 0 0-4.962 16.076c0 .218.01.433.015.648-5.288-2.651-9.253-6.83-9.253-13.406 0-14.549 8.688-27.06 21.155-32.654zm30.35 18.35c-1.611-4.582-5.967-7.873-11.1-7.873-2.746 0-5.265.947-7.267 2.521-4.127-3.214-7.242-7.595-9.144-12.494a32 32 0 0 1 2.723-2.599 35.8 35.8 0 0 1 9.042-1.155c12.57 0 23.621 6.49 30.004 16.295-4.244 2.94-9.168 4.839-14.258 5.305"/></g><g clip-path="url(#g)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><g clip-path="url(#h)"><path fill="#fff" d="m156.22 685.19 10.879 2.595-10.92.557 8.887 6.792-10.084-3.615 6.853 9.497-9.465-6.291 3.309 11.117-6.5-9.163-.148 11.579-4.277-10.314-3.566 10.437.193-12.295-6.163 11.021 3.335-11.702-9.997 7.27 7.831-9.84-12.411 4.564 9.795-7.247-12.56-.386 12.842-3.314-12.853-2.779 12.687-.92-10.699-6.851 11.017 3.994-7.644-9.681 9.659 7.79-3.478-12.991 7.457 10.572-1.045-12.486 4.233 11.319 3.603-11.897.876 11.933 5.348-10.181-3.16 11.645 9.793-7.586-6.322 9.672 10.744-4.186-8.215 8.073 11.016-.866z"/></g></g><g clip-path="url(#i)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><g clip-path="url(#j)" opacity=".35"><path fill="#373535" d="M40.403 664.37c33.74 33.739 60.687 44.155 85.143 48.91 3.236.629 3.848 7.7 3.848 7.7s.453-5.208 2.718-5.887c2.264-.68 5.207 8.152 5.207 8.152s-2.717-7.926 0-8.379 7.699 7.699 7.699 7.699-2.037-7.019-.678-7.472c1.357-.453 8.15 10.189 8.15 10.189s-4.076-7.019-.226-7.699c3.851-.679 9.467 4.791 9.467 4.791s-4.416-5.005-2.448-5.696c8.379-2.945 15.159 7.945 15.159 7.945s-1.571-4.775-5.647-9.983c8.83-2.264 15.389 11.039 15.389 11.039l-6.559-13.303c3.397-1.813 16.985 13.812 16.985 13.812s-7.02-12.228-11.096-14.718c2.264-1.812 10.416 5.434 10.416 5.434s-6.567-8.151-4.076-8.604c3.623-2.944 16.982 15.171 16.982 15.171s-5.207-10.642-12.906-19.021c6.435-3.219 22.418 17.436 22.418 17.436s-.453-6.567-12.002-16.983c8.605 1.132 19.701 17.436 19.701 17.436s-4.076-12.228-13.814-20.832c8.449.879 21.964 21.738 21.964 21.738s-5.207-14.492-15.849-22.871c11.775-2.604 28.758 14.945 28.758 14.945s-6.68-12.455-15.399-17.549c9.738-3.736 23.098 11.662 23.098 11.662s-13.36-20.607-34.645-19.701c-6.984.297-28.109 21.188-73.368 19.474-59.78-2.265-72.46-27.626-104.39-44.835"/></g><path fill="#373535" d="M41.786 666.93c33.74 33.739 60.686 44.154 85.142 48.91 3.237.629 3.849 7.699 3.849 7.699s.452-5.209 2.718-5.887c2.264-.679 5.207 8.151 5.207 8.151s-2.717-7.926 0-8.378 7.699 7.699 7.699 7.699-2.037-7.019-.68-7.472c1.359-.453 8.152 10.19 8.152 10.19s-4.076-7.02-.226-7.699 9.467 4.79 9.467 4.79-4.416-5.005-2.448-5.696c8.379-2.944 15.157 7.945 15.157 7.945s-1.571-4.775-5.645-9.983c8.83-2.265 15.389 11.04 15.389 11.04l-6.559-13.305c3.397-1.811 16.983 13.812 16.983 13.812s-7.018-12.226-11.094-14.717c2.264-1.812 10.416 5.434 10.416 5.434s-6.567-8.152-4.076-8.604c3.623-2.945 16.982 15.171 16.982 15.171s-5.209-10.643-12.906-19.021c6.435-3.22 22.418 17.436 22.418 17.436s-.453-6.568-12.002-16.984c8.605 1.133 19.701 17.437 19.701 17.437s-4.076-12.228-13.814-20.833c8.449.879 21.964 21.738 21.964 21.738s-5.207-14.492-15.849-22.87c11.775-2.604 28.758 14.944 28.758 14.944s-6.68-12.453-15.399-17.548c9.738-3.736 23.098 11.662 23.098 11.662s-13.36-20.607-34.647-19.701c-6.982.298-28.107 21.189-73.367 19.474-59.779-2.264-72.46-27.625-104.39-44.834"/></g><g clip-path="url(#k)" transform="matrix(.2229 0 0 -.2229 -7.157 176.242)"><g clip-path="url(#l)" opacity=".35"><path fill="#373535" d="M39.52 660.68c17.832-8.945 34.137 1.358 54.686-4.433 15.623-4.404 34.645-9.833 60.458-6.096 25.814 3.735 47.893 14.944 58.424 34.985 3.283 8.943 16.642-2.039 16.642-2.039s-9.736 4.076-9.509 2.151c.226-1.924 14.605-8.604 14.605-8.604s-13.021 4.076-12.228 1.019 16.302-15.285 16.302-15.285-17.548 13.36-19.019 11.549c-1.473-1.812 7.472-9.172 7.472-9.172s-14.832 9.172-20.041 6.467c-3.746-1.943 15.399-14.506 15.399-14.506s-12.455 9.512-15.399 7.021 14.04-22.871 14.04-22.871-19.249 20.833-21.172 19.814c-1.926-1.019 5.32-10.983 5.32-10.983s-9.51 10.417-12.113 8.605c-2.604-1.812 13.586-28.871 13.586-28.871s-17.549 27.738-24.795 23.098c11.379-24.966 7.133-28.533 7.133-28.533s-1.452 25.47-15.625 24.796c-7.133-.34 3.396-19.021 3.396-19.021s-9.691 17.062-16.145 16.722c11.895-22.511 7.655-31.667 7.655-31.667s1.967 19.226-14.166 29.925c6.113-5.433-3.836-29.925-3.836-29.925s8.752 36.091-6.455 29.21c-2.403-1.085-.17-18.002-.17-18.002s-3.057 19.362-7.641 18.342c-2.673-.593-16.984-26.833-16.984-26.833s11.719 28.362 8.153 27.173c-2.598-.867-7.473-12.568-7.473-12.568s2.377 11.549 0 12.228-15.625-12.228-15.625-12.228 9.851 11.549 8.152 13.927c-2.574 3.603-5.591 3.772-9.171 2.377-5.209-2.03-12.227-11.548-12.227-11.548s6.996 9.637 5.773 13.247c-1.963 5.8-22.077-11.209-22.077-11.209s11.888 11.209 9.171 13.587c-2.717 2.377-17.471 1.642-22.078 1.655-13.586.042-18.294 3.229-22.418 6.496"/></g><path fill="#373535" d="M38.841 662.72c17.832-8.945 34.136 1.358 54.685-4.434 15.623-4.402 34.646-9.832 60.46-6.095 25.814 3.736 47.891 14.945 58.422 34.984 3.283 8.944 16.642-2.037 16.642-2.037s-9.736 4.075-9.509 2.15c.226-1.924 14.605-8.604 14.605-8.604s-13.021 4.075-12.228 1.018c.793-3.056 16.304-15.284 16.304-15.284s-17.55 13.361-19.021 11.548c-1.471-1.811 7.473-9.17 7.473-9.17s-14.833 9.17-20.041 6.467c-3.747-1.944 15.398-14.506 15.398-14.506s-12.455 9.511-15.398 7.02c-2.944-2.492 14.041-22.871 14.041-22.871s-19.25 20.833-21.174 19.814c-1.924-1.02 5.322-10.982 5.322-10.982s-9.512 10.416-12.115 8.604c-2.604-1.811 13.586-28.871 13.586-28.871s-17.549 27.739-24.795 23.097c11.379-24.965 7.133-28.532 7.133-28.532s-1.452 25.47-15.625 24.795c-7.133-.34 3.396-19.02 3.396-19.02s-9.691 17.063-16.144 16.723c11.896-22.512 7.654-31.668 7.654-31.668s1.967 19.227-14.166 29.926c6.113-5.434-3.836-29.926-3.836-29.926s8.754 36.091-6.453 29.21c-2.403-1.086-.17-18.002-.17-18.002s-3.059 19.361-7.642 18.342c-2.674-.593-16.985-26.833-16.985-26.833s11.719 28.362 8.153 27.172c-2.598-.865-7.473-12.566-7.473-12.566s2.378 11.548 0 12.227c-2.377.679-15.624-12.227-15.624-12.227s9.851 11.548 8.151 13.926c-2.574 3.603-5.591 3.771-9.17 2.376-5.21-2.029-12.228-11.547-12.228-11.547s6.996 9.638 5.774 13.247c-1.964 5.799-22.077-11.209-22.077-11.209s11.888 11.209 9.17 13.586-17.471 1.642-22.078 1.656c-13.586.043-18.293 3.229-22.417 6.496"/></g></svg></div>',
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
            case 'cassandra':
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
