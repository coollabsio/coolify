<?php

namespace App\Livewire;

use App\Models\Application;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

class GlobalSearch extends Component
{
    public $searchQuery = '';

    private $previousTrimmedQuery = '';

    public $isModalOpen = false;

    public $searchResults = [];

    public $allSearchableItems = [];

    public $isCreateMode = false;

    public $creatableItems = [];

    public $autoOpenResource = null;

    // Resource selection state
    public $isSelectingResource = false;

    public $selectedResourceType = null;

    public $loadingServers = false;

    public $loadingProjects = false;

    public $loadingEnvironments = false;

    public $availableServers = [];

    public $availableProjects = [];

    public $availableEnvironments = [];

    public $selectedServerId = null;

    public $selectedDestinationUuid = null;

    public $selectedProjectUuid = null;

    public $selectedEnvironmentUuid = null;

    public $availableDestinations = [];

    public $loadingDestinations = false;

    public function mount()
    {
        $this->searchQuery = '';
        $this->isModalOpen = false;
        $this->searchResults = [];
        $this->allSearchableItems = [];
        $this->isCreateMode = false;
        $this->creatableItems = [];
        $this->autoOpenResource = null;
        $this->isSelectingResource = false;
    }

    public function openSearchModal()
    {
        $this->isModalOpen = true;
        $this->loadSearchableItems();
        $this->loadCreatableItems();
        $this->dispatch('search-modal-opened');
    }

    public function closeSearchModal()
    {
        $this->isModalOpen = false;
        $this->searchQuery = '';
        $this->previousTrimmedQuery = '';
        $this->searchResults = [];
    }

    public static function getCacheKey($teamId)
    {
        return 'global_search_items_'.$teamId;
    }

    public static function clearTeamCache($teamId)
    {
        Cache::forget(self::getCacheKey($teamId));
    }

    public function updatedSearchQuery()
    {
        $trimmedQuery = trim($this->searchQuery);

        // If only spaces were added/removed, don't trigger a search
        if ($trimmedQuery === $this->previousTrimmedQuery) {
            return;
        }

        $this->previousTrimmedQuery = $trimmedQuery;

        // If search query is empty, just clear results without processing
        if (empty($trimmedQuery)) {
            $this->searchResults = [];
            $this->isCreateMode = false;
            $this->creatableItems = [];
            $this->autoOpenResource = null;
            $this->isSelectingResource = false;
            $this->cancelResourceSelection();

            return;
        }

        $query = strtolower($trimmedQuery);

        // Reset keyboard navigation index
        $this->dispatch('reset-selected-index');

        // Only enter create mode if query is exactly "new" or starts with "new " (space after)
        if ($query === 'new' || str_starts_with($query, 'new ')) {
            $this->isCreateMode = true;
            $this->loadCreatableItems();

            // Check for sub-commands like "new project", "new server", etc.
            $detectedType = $this->detectSpecificResource($query);
            if ($detectedType) {
                $this->navigateToResource($detectedType);
            } else {
                // If no specific resource detected, reset selection state
                $this->cancelResourceSelection();
            }

            // Also search for existing resources that match the query
            // This allows users to find resources with "new" in their name
            $this->search();
        } else {
            $this->isCreateMode = false;
            $this->creatableItems = [];
            $this->autoOpenResource = null;
            $this->isSelectingResource = false;
            $this->search();
        }
    }

    private function detectSpecificResource(string $query): ?string
    {
        // Map of keywords to resource types - order matters for multi-word matches
        $resourceMap = [
            // Quick Actions
            'new project' => 'project',
            'new server' => 'server',
            'new team' => 'team',
            'new storage' => 'storage',
            'new s3' => 'storage',
            'new private key' => 'private-key',
            'new privatekey' => 'private-key',
            'new key' => 'private-key',
            'new github app' => 'source',
            'new github' => 'source',
            'new source' => 'source',

            // Applications - Git-based
            'new public' => 'public',
            'new public git' => 'public',
            'new public repo' => 'public',
            'new public repository' => 'public',
            'new private github' => 'private-gh-app',
            'new private gh' => 'private-gh-app',
            'new private deploy' => 'private-deploy-key',
            'new deploy key' => 'private-deploy-key',

            // Applications - Docker-based
            'new dockerfile' => 'dockerfile',
            'new docker compose' => 'docker-compose-empty',
            'new compose' => 'docker-compose-empty',
            'new docker image' => 'docker-image',
            'new image' => 'docker-image',

            // Databases
            'new postgresql' => 'postgresql',
            'new postgres' => 'postgresql',
            'new mysql' => 'mysql',
            'new mariadb' => 'mariadb',
            'new redis' => 'redis',
            'new keydb' => 'keydb',
            'new dragonfly' => 'dragonfly',
            'new mongodb' => 'mongodb',
            'new mongo' => 'mongodb',
            'new clickhouse' => 'clickhouse',
        ];

        foreach ($resourceMap as $command => $type) {
            if ($query === $command) {
                // Check if user has permission for this resource type
                if ($this->canCreateResource($type)) {
                    return $type;
                }
            }
        }

        return null;
    }

    private function canCreateResource(string $type): bool
    {
        $user = auth()->user();

        // Quick Actions
        if (in_array($type, ['server', 'storage', 'private-key'])) {
            return $user->isAdmin() || $user->isOwner();
        }

        if ($type === 'team') {
            return true;
        }

        // Applications, Databases, Services, and other resources
        if (in_array($type, [
            'project', 'source',
            // Applications
            'public', 'private-gh-app', 'private-deploy-key',
            'dockerfile', 'docker-compose-empty', 'docker-image',
            // Databases
            'postgresql', 'mysql', 'mariadb', 'redis', 'keydb',
            'dragonfly', 'mongodb', 'clickhouse',
        ]) || str_starts_with($type, 'one-click-service-')) {
            return $user->can('createAnyResource');
        }

        return false;
    }

    private function loadSearchableItems()
    {
        // Try to get from Redis cache first
        $cacheKey = self::getCacheKey(auth()->user()->currentTeam()->id);

        $this->allSearchableItems = Cache::remember($cacheKey, 300, function () {
            ray()->showQueries();
            $items = collect();
            $team = auth()->user()->currentTeam();

            // Get all applications
            $applications = Application::ownedByCurrentTeam()
                ->with(['environment.project'])
                ->get()
                ->map(function ($app) {
                    // Collect all FQDNs from the application
                    $fqdns = collect([]);

                    // For regular applications
                    if ($app->fqdn) {
                        $fqdns = collect(explode(',', $app->fqdn))->map(fn ($fqdn) => trim($fqdn));
                    }

                    // For docker compose based applications
                    if ($app->build_pack === 'dockercompose' && $app->docker_compose_domains) {
                        try {
                            $composeDomains = json_decode($app->docker_compose_domains, true);
                            if (is_array($composeDomains)) {
                                foreach ($composeDomains as $serviceName => $domains) {
                                    if (is_array($domains)) {
                                        $fqdns = $fqdns->merge($domains);
                                    }
                                }
                            }
                        } catch (\Exception $e) {
                            // Ignore JSON parsing errors
                        }
                    }

                    $fqdnsString = $fqdns->implode(' ');

                    return [
                        'id' => $app->id,
                        'name' => $app->name,
                        'type' => 'application',
                        'uuid' => $app->uuid,
                        'description' => $app->description,
                        'link' => $app->link(),
                        'project' => $app->environment->project->name ?? null,
                        'environment' => $app->environment->name ?? null,
                        'fqdns' => $fqdns->take(2)->implode(', '), // Show first 2 FQDNs in UI
                        'search_text' => strtolower($app->name.' '.$app->description.' '.$fqdnsString.' application applications app apps'),
                    ];
                });

            // Get all services
            $services = Service::ownedByCurrentTeam()
                ->with(['environment.project', 'applications'])
                ->get()
                ->map(function ($service) {
                    // Collect all FQDNs from service applications
                    $fqdns = collect([]);
                    foreach ($service->applications as $app) {
                        if ($app->fqdn) {
                            $appFqdns = collect(explode(',', $app->fqdn))->map(fn ($fqdn) => trim($fqdn));
                            $fqdns = $fqdns->merge($appFqdns);
                        }
                    }
                    $fqdnsString = $fqdns->implode(' ');

                    return [
                        'id' => $service->id,
                        'name' => $service->name,
                        'type' => 'service',
                        'uuid' => $service->uuid,
                        'description' => $service->description,
                        'link' => $service->link(),
                        'project' => $service->environment->project->name ?? null,
                        'environment' => $service->environment->name ?? null,
                        'fqdns' => $fqdns->take(2)->implode(', '), // Show first 2 FQDNs in UI
                        'search_text' => strtolower($service->name.' '.$service->description.' '.$fqdnsString.' service services'),
                    ];
                });

            // Get all standalone databases
            $databases = collect();

            // PostgreSQL
            $databases = $databases->merge(
                StandalonePostgresql::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'postgresql',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' postgresql '.$db->description.' database databases db'),
                        ];
                    })
            );

            // MySQL
            $databases = $databases->merge(
                StandaloneMysql::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'mysql',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' mysql '.$db->description.' database databases db'),
                        ];
                    })
            );

            // MariaDB
            $databases = $databases->merge(
                StandaloneMariadb::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'mariadb',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' mariadb '.$db->description.' database databases db'),
                        ];
                    })
            );

            // MongoDB
            $databases = $databases->merge(
                StandaloneMongodb::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'mongodb',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' mongodb '.$db->description.' database databases db'),
                        ];
                    })
            );

            // Redis
            $databases = $databases->merge(
                StandaloneRedis::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'redis',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' redis '.$db->description.' database databases db'),
                        ];
                    })
            );

            // KeyDB
            $databases = $databases->merge(
                StandaloneKeydb::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'keydb',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' keydb '.$db->description.' database databases db'),
                        ];
                    })
            );

            // Dragonfly
            $databases = $databases->merge(
                StandaloneDragonfly::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'dragonfly',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' dragonfly '.$db->description.' database databases db'),
                        ];
                    })
            );

            // Clickhouse
            $databases = $databases->merge(
                StandaloneClickhouse::ownedByCurrentTeam()
                    ->with(['environment.project'])
                    ->get()
                    ->map(function ($db) {
                        return [
                            'id' => $db->id,
                            'name' => $db->name,
                            'type' => 'database',
                            'subtype' => 'clickhouse',
                            'uuid' => $db->uuid,
                            'description' => $db->description,
                            'link' => $db->link(),
                            'project' => $db->environment->project->name ?? null,
                            'environment' => $db->environment->name ?? null,
                            'search_text' => strtolower($db->name.' clickhouse '.$db->description.' database databases db'),
                        ];
                    })
            );

            // Get all servers
            $servers = Server::ownedByCurrentTeam()
                ->get()
                ->map(function ($server) {
                    return [
                        'id' => $server->id,
                        'name' => $server->name,
                        'type' => 'server',
                        'uuid' => $server->uuid,
                        'description' => $server->description,
                        'link' => $server->url(),
                        'project' => null,
                        'environment' => null,
                        'search_text' => strtolower($server->name.' '.$server->ip.' '.$server->description.' server servers'),
                    ];
                });
            ray($servers);
            // Get all projects
            $projects = Project::ownedByCurrentTeam()
                ->withCount(['environments', 'applications', 'services'])
                ->get()
                ->map(function ($project) {
                    $resourceCount = $project->applications_count + $project->services_count;
                    $resourceSummary = $resourceCount > 0
                        ? "{$resourceCount} resource".($resourceCount !== 1 ? 's' : '')
                        : 'No resources';

                    return [
                        'id' => $project->id,
                        'name' => $project->name,
                        'type' => 'project',
                        'uuid' => $project->uuid,
                        'description' => $project->description,
                        'link' => $project->navigateTo(),
                        'project' => null,
                        'environment' => null,
                        'resource_count' => $resourceSummary,
                        'environment_count' => $project->environments_count,
                        'search_text' => strtolower($project->name.' '.$project->description.' project projects'),
                    ];
                });

            // Get all environments
            $environments = Environment::ownedByCurrentTeam()
                ->with('project')
                ->withCount(['applications', 'services'])
                ->get()
                ->map(function ($environment) {
                    $resourceCount = $environment->applications_count + $environment->services_count;
                    $resourceSummary = $resourceCount > 0
                        ? "{$resourceCount} resource".($resourceCount !== 1 ? 's' : '')
                        : 'No resources';

                    // Build description with project context
                    $descriptionParts = [];
                    if ($environment->project) {
                        $descriptionParts[] = "Project: {$environment->project->name}";
                    }
                    if ($environment->description) {
                        $descriptionParts[] = $environment->description;
                    }
                    if (empty($descriptionParts)) {
                        $descriptionParts[] = $resourceSummary;
                    }

                    return [
                        'id' => $environment->id,
                        'name' => $environment->name,
                        'type' => 'environment',
                        'uuid' => $environment->uuid,
                        'description' => implode(' • ', $descriptionParts),
                        'link' => route('project.resource.index', [
                            'project_uuid' => $environment->project->uuid,
                            'environment_uuid' => $environment->uuid,
                        ]),
                        'project' => $environment->project->name ?? null,
                        'environment' => null,
                        'resource_count' => $resourceSummary,
                        'search_text' => strtolower($environment->name.' '.$environment->description.' '.$environment->project->name.' environment'),
                    ];
                });

            // Add navigation routes
            $navigation = collect([
                [
                    'name' => 'Dashboard',
                    'type' => 'navigation',
                    'description' => 'Go to main dashboard',
                    'link' => route('dashboard'),
                    'search_text' => 'dashboard home main overview',
                ],
                [
                    'name' => 'Servers',
                    'type' => 'navigation',
                    'description' => 'View all servers',
                    'link' => route('server.index'),
                    'search_text' => 'servers all list view',
                ],
                [
                    'name' => 'Projects',
                    'type' => 'navigation',
                    'description' => 'View all projects',
                    'link' => route('project.index'),
                    'search_text' => 'projects all list view',
                ],
                [
                    'name' => 'Destinations',
                    'type' => 'navigation',
                    'description' => 'View all destinations',
                    'link' => route('destination.index'),
                    'search_text' => 'destinations docker networks',
                ],
                [
                    'name' => 'Security',
                    'type' => 'navigation',
                    'description' => 'Manage private keys and API tokens',
                    'link' => route('security.private-key.index'),
                    'search_text' => 'security private keys ssh api tokens cloud-init scripts',
                ],
                [
                    'name' => 'Cloud-Init Scripts',
                    'type' => 'navigation',
                    'description' => 'Manage reusable cloud-init scripts',
                    'link' => route('security.cloud-init-scripts'),
                    'search_text' => 'cloud-init scripts cloud init cloudinit initialization startup server setup',
                ],
                [
                    'name' => 'Sources',
                    'type' => 'navigation',
                    'description' => 'Manage GitHub apps and Git sources',
                    'link' => route('source.all'),
                    'search_text' => 'sources github apps git repositories',
                ],
                [
                    'name' => 'Storages',
                    'type' => 'navigation',
                    'description' => 'Manage S3 storage for backups',
                    'link' => route('storage.index'),
                    'search_text' => 'storages s3 backups',
                ],
                [
                    'name' => 'Shared Variables',
                    'type' => 'navigation',
                    'description' => 'View all shared variables',
                    'link' => route('shared-variables.index'),
                    'search_text' => 'shared variables environment all',
                ],
                [
                    'name' => 'Team Shared Variables',
                    'type' => 'navigation',
                    'description' => 'Manage team-wide shared variables',
                    'link' => route('shared-variables.team.index'),
                    'search_text' => 'shared variables team environment',
                ],
                [
                    'name' => 'Project Shared Variables',
                    'type' => 'navigation',
                    'description' => 'Manage project shared variables',
                    'link' => route('shared-variables.project.index'),
                    'search_text' => 'shared variables project environment',
                ],
                [
                    'name' => 'Environment Shared Variables',
                    'type' => 'navigation',
                    'description' => 'Manage environment shared variables',
                    'link' => route('shared-variables.environment.index'),
                    'search_text' => 'shared variables environment',
                ],
                [
                    'name' => 'Tags',
                    'type' => 'navigation',
                    'description' => 'View resources by tags',
                    'link' => route('tags.show'),
                    'search_text' => 'tags labels organize',
                ],
                [
                    'name' => 'Terminal',
                    'type' => 'navigation',
                    'description' => 'Access server terminal',
                    'link' => route('terminal'),
                    'search_text' => 'terminal ssh console shell command line',
                ],
                [
                    'name' => 'Profile',
                    'type' => 'navigation',
                    'description' => 'Manage your profile and preferences',
                    'link' => route('profile'),
                    'search_text' => 'profile account user settings preferences',
                ],
                [
                    'name' => 'Team',
                    'type' => 'navigation',
                    'description' => 'Manage team members and settings',
                    'link' => route('team.index'),
                    'search_text' => 'team settings members users invitations',
                ],
                [
                    'name' => 'Notifications',
                    'type' => 'navigation',
                    'description' => 'Configure email, Discord, Telegram notifications',
                    'link' => route('notifications.email'),
                    'search_text' => 'notifications alerts email discord telegram slack pushover',
                ],
            ]);

            // Add instance settings only for self-hosted and root team
            if (! isCloud() && $team->id === 0) {
                $navigation->push([
                    'name' => 'Settings',
                    'type' => 'navigation',
                    'description' => 'Instance settings and configuration',
                    'link' => route('settings.index'),
                    'search_text' => 'settings configuration instance',
                ]);
            }

            // Merge all collections
            $items = $items->merge($navigation)
                ->merge($applications)
                ->merge($services)
                ->merge($databases)
                ->merge($servers)
                ->merge($projects)
                ->merge($environments);

            return $items->toArray();
        });
    }

    private function search()
    {
        if (strlen($this->searchQuery) < 1) {
            $this->searchResults = [];

            return;
        }

        $query = strtolower($this->searchQuery);

        // Detect resource category queries
        $categoryMapping = [
            'server' => ['server', 'type' => 'server'],
            'servers' => ['server', 'type' => 'server'],
            'app' => ['application', 'type' => 'application'],
            'apps' => ['application', 'type' => 'application'],
            'application' => ['application', 'type' => 'application'],
            'applications' => ['application', 'type' => 'application'],
            'db' => ['database', 'type' => 'standalone-postgresql'],
            'database' => ['database', 'type' => 'standalone-postgresql'],
            'databases' => ['database', 'type' => 'standalone-postgresql'],
            'service' => ['service', 'category' => 'Services'],
            'services' => ['service', 'category' => 'Services'],
            'project' => ['project', 'type' => 'project'],
            'projects' => ['project', 'type' => 'project'],
        ];

        $priorityCreatableItem = null;

        // Check if query matches a resource category
        if (isset($categoryMapping[$query])) {
            $this->loadCreatableItems();
            $mapping = $categoryMapping[$query];

            // Find the matching creatable item
            $priorityCreatableItem = collect($this->creatableItems)
                ->first(function ($item) use ($mapping) {
                    if (isset($mapping['type'])) {
                        return $item['type'] === $mapping['type'];
                    }
                    if (isset($mapping['category'])) {
                        return isset($item['category']) && $item['category'] === $mapping['category'];
                    }

                    return false;
                });

            if ($priorityCreatableItem) {
                $priorityCreatableItem['is_creatable_suggestion'] = true;
            }
        }

        // Search for matching creatable resources to show as suggestions (if no priority item)
        if (! $priorityCreatableItem) {
            $this->loadCreatableItems();

            // Search in regular creatable items (apps, databases, quick actions)
            $creatableSuggestions = collect($this->creatableItems)
                ->filter(function ($item) use ($query) {
                    $searchText = strtolower($item['name'].' '.$item['description'].' '.($item['type'] ?? ''));

                    // Use word boundary matching to avoid substring matches (e.g., "wordpress" shouldn't match "classicpress")
                    return preg_match('/\b'.preg_quote($query, '/').'/i', $searchText);
                })
                ->map(function ($item) use ($query) {
                    // Calculate match priority: name > type > description
                    $name = strtolower($item['name']);
                    $type = strtolower($item['type'] ?? '');
                    $description = strtolower($item['description']);

                    if (preg_match('/\b'.preg_quote($query, '/').'/i', $name)) {
                        $item['match_priority'] = 1;
                    } elseif (preg_match('/\b'.preg_quote($query, '/').'/i', $type)) {
                        $item['match_priority'] = 2;
                    } else {
                        $item['match_priority'] = 3;
                    }

                    $item['is_creatable_suggestion'] = true;

                    return $item;
                });

            // Also search in services (loaded on-demand)
            $serviceSuggestions = collect($this->services)
                ->filter(function ($item) use ($query) {
                    $searchText = strtolower($item['name'].' '.$item['description'].' '.($item['type'] ?? ''));

                    return preg_match('/\b'.preg_quote($query, '/').'/i', $searchText);
                })
                ->map(function ($item) use ($query) {
                    // Calculate match priority: name > type > description
                    $name = strtolower($item['name']);
                    $type = strtolower($item['type'] ?? '');
                    $description = strtolower($item['description']);

                    if (preg_match('/\b'.preg_quote($query, '/').'/i', $name)) {
                        $item['match_priority'] = 1;
                    } elseif (preg_match('/\b'.preg_quote($query, '/').'/i', $type)) {
                        $item['match_priority'] = 2;
                    } else {
                        $item['match_priority'] = 3;
                    }

                    $item['is_creatable_suggestion'] = true;

                    return $item;
                });

            // Merge and sort all suggestions
            $creatableSuggestions = $creatableSuggestions
                ->merge($serviceSuggestions)
                ->sortBy('match_priority')
                ->take(10)
                ->values()
                ->toArray();
        } else {
            $creatableSuggestions = [];
        }

        // Case-insensitive search in existing resources
        $existingResults = collect($this->allSearchableItems)
            ->filter(function ($item) use ($query) {
                // Use word boundary matching to avoid substring matches (e.g., "wordpress" shouldn't match "classicpress")
                return preg_match('/\b'.preg_quote($query, '/').'/i', $item['search_text']);
            })
            ->map(function ($item) use ($query) {
                // Calculate match priority: name > type > description
                $name = strtolower($item['name'] ?? '');
                $type = strtolower($item['type'] ?? '');
                $description = strtolower($item['description'] ?? '');

                if (preg_match('/\b'.preg_quote($query, '/').'/i', $name)) {
                    $item['match_priority'] = 1;
                } elseif (preg_match('/\b'.preg_quote($query, '/').'/i', $type)) {
                    $item['match_priority'] = 2;
                } else {
                    $item['match_priority'] = 3;
                }

                return $item;
            })
            ->sortBy('match_priority')
            ->take(20)
            ->values()
            ->toArray();

        // Merge results: existing resources first, then priority create item, then other creatable suggestions
        $results = [];

        // If we have existing results, show them first
        $results = array_merge($results, $existingResults);

        // Then show the priority "Create New" item (if exists)
        if ($priorityCreatableItem) {
            $results[] = $priorityCreatableItem;
        }

        // Finally show other creatable suggestions
        $results = array_merge($results, $creatableSuggestions);

        $this->searchResults = $results;
    }

    private function loadCreatableItems()
    {
        $items = collect();
        $user = auth()->user();

        // === Quick Actions Category ===

        // Project - can be created if user has createAnyResource permission
        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'Project',
                'description' => 'Create a new project to organize your resources',
                'quickcommand' => '(type: new project)',
                'type' => 'project',
                'category' => 'Quick Actions',
                'component' => 'project.add-empty',
            ]);
        }

        // Server - can be created if user is admin or owner
        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'Server',
                'description' => 'Add a new server to deploy your applications',
                'quickcommand' => '(type: new server)',
                'type' => 'server',
                'category' => 'Quick Actions',
                'component' => 'server.create',
            ]);
        }

        // Team - can be created by anyone (they become owner of new team)
        $items->push([
            'name' => 'Team',
            'description' => 'Create a new team to collaborate with others',
            'quickcommand' => '(type: new team)',
            'type' => 'team',
            'category' => 'Quick Actions',
            'component' => 'team.create',
        ]);

        // Storage - can be created if user is admin or owner
        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'S3 Storage',
                'description' => 'Add S3 storage for backups and file uploads',
                'quickcommand' => '(type: new storage)',
                'type' => 'storage',
                'category' => 'Quick Actions',
                'component' => 'storage.create',
            ]);
        }

        // Private Key - can be created if user is admin or owner
        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'Private Key',
                'description' => 'Add an SSH private key for server access',
                'quickcommand' => '(type: new private key)',
                'type' => 'private-key',
                'category' => 'Quick Actions',
                'component' => 'security.private-key.create',
            ]);
        }

        // GitHub Source - can be created if user has createAnyResource permission
        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'GitHub App',
                'description' => 'Connect a GitHub app for source control',
                'quickcommand' => '(type: new github)',
                'type' => 'source',
                'category' => 'Quick Actions',
                'component' => 'source.github.create',
            ]);
        }

        // === Applications Category ===

        if ($user->can('createAnyResource')) {
            // Git-based applications
            $items->push([
                'name' => 'Public Git Repository',
                'description' => 'Deploy from any public Git repository',
                'quickcommand' => '(type: new public)',
                'type' => 'public',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Private Repository (GitHub App)',
                'description' => 'Deploy private repositories through GitHub Apps',
                'quickcommand' => '(type: new private github)',
                'type' => 'private-gh-app',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Private Repository (Deploy Key)',
                'description' => 'Deploy private repositories with a deploy key',
                'quickcommand' => '(type: new private deploy)',
                'type' => 'private-deploy-key',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            // Docker-based applications
            $items->push([
                'name' => 'Dockerfile',
                'description' => 'Deploy a simple Dockerfile without Git',
                'quickcommand' => '(type: new dockerfile)',
                'type' => 'dockerfile',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Docker Compose',
                'description' => 'Deploy complex applications with Docker Compose',
                'quickcommand' => '(type: new compose)',
                'type' => 'docker-compose-empty',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);

            $items->push([
                'name' => 'Docker Image',
                'description' => 'Deploy an existing Docker image from any registry',
                'quickcommand' => '(type: new image)',
                'type' => 'docker-image',
                'category' => 'Applications',
                'resourceType' => 'application',
            ]);
        }

        // === Databases Category ===

        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'PostgreSQL',
                'description' => 'Robust, advanced open-source database',
                'quickcommand' => '(type: new postgresql)',
                'type' => 'postgresql',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'MySQL',
                'description' => 'Popular open-source relational database',
                'quickcommand' => '(type: new mysql)',
                'type' => 'mysql',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'MariaDB',
                'description' => 'Community-developed fork of MySQL',
                'quickcommand' => '(type: new mariadb)',
                'type' => 'mariadb',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'Redis',
                'description' => 'In-memory data structure store',
                'quickcommand' => '(type: new redis)',
                'type' => 'redis',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'KeyDB',
                'description' => 'High-performance Redis alternative',
                'quickcommand' => '(type: new keydb)',
                'type' => 'keydb',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'Dragonfly',
                'description' => 'Modern in-memory datastore',
                'quickcommand' => '(type: new dragonfly)',
                'type' => 'dragonfly',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'MongoDB',
                'description' => 'Document-oriented NoSQL database',
                'quickcommand' => '(type: new mongodb)',
                'type' => 'mongodb',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);

            $items->push([
                'name' => 'Clickhouse',
                'description' => 'Column-oriented database for analytics',
                'quickcommand' => '(type: new clickhouse)',
                'type' => 'clickhouse',
                'category' => 'Databases',
                'resourceType' => 'database',
            ]);
        }

        // Merge with services
        $items = $items->merge(collect($this->services));

        $this->creatableItems = $items->toArray();
    }

    public function navigateToResource($type)
    {
        // Find the item by type - check regular items first, then services
        $item = collect($this->creatableItems)->firstWhere('type', $type);

        if (! $item) {
            $item = collect($this->services)->firstWhere('type', $type);
        }

        if (! $item) {
            return;
        }

        // If it has a component, it's a modal-based resource
        // Close search modal and open the appropriate creation modal
        if (isset($item['component'])) {
            $this->dispatch('closeSearchModal');
            $this->dispatch('open-create-modal-'.$type);

            return;
        }

        // For applications, databases, and services, navigate to resource creation
        // with smart defaults (auto-select if only 1 server/project/environment)
        if (isset($item['resourceType'])) {
            $this->navigateToResourceCreation($type);
        }
    }

    private function navigateToResourceCreation($type)
    {
        // Start the selection flow
        $this->selectedResourceType = $type;
        $this->isSelectingResource = true;

        // Clear search query to show selection UI instead of creatable items
        $this->searchQuery = '';

        // Reset selections
        $this->selectedServerId = null;
        $this->selectedDestinationUuid = null;
        $this->selectedProjectUuid = null;
        $this->selectedEnvironmentUuid = null;

        // Start loading servers first (in order: servers -> destinations -> projects -> environments)
        $this->loadServers();
    }

    public function loadServers()
    {
        $this->loadingServers = true;
        $servers = Server::isUsable()->get()->sortBy('name');
        $this->availableServers = $servers->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
        ])->toArray();
        $this->loadingServers = false;

        // Auto-select if only one server
        if (count($this->availableServers) === 1) {
            $this->selectServer($this->availableServers[0]['id']);
        }
    }

    public function selectServer($serverId, $shouldProgress = true)
    {
        $this->selectedServerId = $serverId;

        if ($shouldProgress) {
            $this->loadDestinations();
        }
    }

    public function loadDestinations()
    {
        $this->loadingDestinations = true;
        $server = Server::find($this->selectedServerId);

        if (! $server) {
            $this->loadingDestinations = false;

            return $this->dispatch('error', message: 'Server not found');
        }

        $destinations = $server->destinations();

        if ($destinations->isEmpty()) {
            $this->loadingDestinations = false;

            return $this->dispatch('error', message: 'No destinations found on this server');
        }

        $this->availableDestinations = $destinations->map(fn ($d) => [
            'uuid' => $d->uuid,
            'name' => $d->name,
            'network' => $d->network ?? 'default',
        ])->toArray();

        $this->loadingDestinations = false;

        // Auto-select if only one destination
        if (count($this->availableDestinations) === 1) {
            $this->selectDestination($this->availableDestinations[0]['uuid']);
        }
    }

    public function selectDestination($destinationUuid, $shouldProgress = true)
    {
        $this->selectedDestinationUuid = $destinationUuid;

        if ($shouldProgress) {
            $this->loadProjects();
        }
    }

    public function loadProjects()
    {
        $this->loadingProjects = true;
        $user = auth()->user();
        $team = $user->currentTeam();
        $projects = Project::where('team_id', $team->id)->get();

        if ($projects->isEmpty()) {
            $this->loadingProjects = false;

            return $this->dispatch('error', message: 'Please create a project first');
        }

        $this->availableProjects = $projects->map(fn ($p) => [
            'uuid' => $p->uuid,
            'name' => $p->name,
            'description' => $p->description,
        ])->toArray();
        $this->loadingProjects = false;

        // Auto-select if only one project
        if (count($this->availableProjects) === 1) {
            $this->selectProject($this->availableProjects[0]['uuid']);
        }
    }

    public function selectProject($projectUuid, $shouldProgress = true)
    {
        $this->selectedProjectUuid = $projectUuid;

        if ($shouldProgress) {
            $this->loadEnvironments();
        }
    }

    public function loadEnvironments()
    {
        $this->loadingEnvironments = true;
        $project = Project::where('uuid', $this->selectedProjectUuid)->first();

        if (! $project) {
            $this->loadingEnvironments = false;

            return;
        }

        $environments = $project->environments;

        if ($environments->isEmpty()) {
            $this->loadingEnvironments = false;

            return $this->dispatch('error', message: 'No environments found in project');
        }

        $this->availableEnvironments = $environments->map(fn ($e) => [
            'uuid' => $e->uuid,
            'name' => $e->name,
            'description' => $e->description,
        ])->toArray();
        $this->loadingEnvironments = false;

        // Auto-select if only one environment
        if (count($this->availableEnvironments) === 1) {
            $this->selectEnvironment($this->availableEnvironments[0]['uuid']);
        }
    }

    public function selectEnvironment($environmentUuid, $shouldProgress = true)
    {
        $this->selectedEnvironmentUuid = $environmentUuid;

        if ($shouldProgress) {
            $this->completeResourceCreation();
        }
    }

    private function completeResourceCreation()
    {
        // All selections made - navigate to resource creation
        if ($this->selectedProjectUuid && $this->selectedEnvironmentUuid && $this->selectedResourceType && $this->selectedServerId !== null && $this->selectedDestinationUuid) {
            $queryParams = [
                'type' => $this->selectedResourceType,
                'destination' => $this->selectedDestinationUuid,
                'server_id' => $this->selectedServerId,
            ];

            // PostgreSQL requires a database_image parameter
            if ($this->selectedResourceType === 'postgresql') {
                $queryParams['database_image'] = 'postgres:16-alpine';
            }

            $this->redirect(route('project.resource.create', [
                'project_uuid' => $this->selectedProjectUuid,
                'environment_uuid' => $this->selectedEnvironmentUuid,
            ] + $queryParams));
        }
    }

    public function cancelResourceSelection()
    {
        $this->isSelectingResource = false;
        $this->selectedResourceType = null;
        $this->selectedServerId = null;
        $this->selectedDestinationUuid = null;
        $this->selectedProjectUuid = null;
        $this->selectedEnvironmentUuid = null;
        $this->availableServers = [];
        $this->availableDestinations = [];
        $this->availableProjects = [];
        $this->availableEnvironments = [];
        $this->autoOpenResource = null;
    }

    public function getFilteredCreatableItemsProperty()
    {
        $query = strtolower(trim($this->searchQuery));

        // Check if query matches a category keyword
        $categoryKeywords = ['server', 'servers', 'app', 'apps', 'application', 'applications', 'db', 'database', 'databases', 'service', 'services', 'project', 'projects'];
        if (in_array($query, $categoryKeywords)) {
            return $this->filterCreatableItemsByCategory($query);
        }

        // Extract search term - everything after "new "
        if (str_starts_with($query, 'new ')) {
            $searchTerm = trim(substr($query, strlen('new ')));

            if (empty($searchTerm)) {
                return $this->creatableItems;
            }

            // Filter items by name or description
            return collect($this->creatableItems)->filter(function ($item) use ($searchTerm) {
                $searchText = strtolower($item['name'].' '.$item['description'].' '.$item['category']);

                return str_contains($searchText, $searchTerm);
            })->values()->toArray();
        }

        return $this->creatableItems;
    }

    private function filterCreatableItemsByCategory($categoryKeyword)
    {
        // Map keywords to category names
        $categoryMap = [
            'server' => 'Quick Actions',
            'servers' => 'Quick Actions',
            'app' => 'Applications',
            'apps' => 'Applications',
            'application' => 'Applications',
            'applications' => 'Applications',
            'db' => 'Databases',
            'database' => 'Databases',
            'databases' => 'Databases',
            'service' => 'Services',
            'services' => 'Services',
            'project' => 'Applications',
            'projects' => 'Applications',
        ];

        $category = $categoryMap[$categoryKeyword] ?? null;

        if (! $category) {
            return [];
        }

        return collect($this->creatableItems)
            ->filter(fn ($item) => $item['category'] === $category)
            ->values()
            ->toArray();
    }

    public function getSelectedResourceNameProperty()
    {
        if (! $this->selectedResourceType) {
            return null;
        }

        // Load creatable items if not loaded yet
        if (empty($this->creatableItems)) {
            $this->loadCreatableItems();
        }

        // Find the item by type - check regular items first, then services
        $item = collect($this->creatableItems)->firstWhere('type', $this->selectedResourceType);

        if (! $item) {
            $item = collect($this->services)->firstWhere('type', $this->selectedResourceType);
        }

        return $item ? $item['name'] : null;
    }

    public function getServicesProperty()
    {
        // Cache services in a static property to avoid reloading on every access
        static $cachedServices = null;

        if ($cachedServices !== null) {
            return $cachedServices;
        }

        $user = auth()->user();

        if (! $user->can('createAnyResource')) {
            $cachedServices = [];

            return $cachedServices;
        }

        // Load all services
        $allServices = get_service_templates();
        $items = collect();

        foreach ($allServices as $serviceKey => $service) {
            $items->push([
                'name' => str($serviceKey)->headline()->toString(),
                'description' => data_get($service, 'slogan', 'Deploy '.str($serviceKey)->headline()),
                'type' => 'one-click-service-'.$serviceKey,
                'category' => 'Services',
                'resourceType' => 'service',
            ]);
        }

        $cachedServices = $items->toArray();

        return $cachedServices;
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
