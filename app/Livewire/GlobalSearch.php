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

    public $isModalOpen = false;

    public $searchResults = [];

    public $allSearchableItems = [];

    public $isCreateMode = false;

    public $creatableItems = [];

    public $autoOpenResource = null;

    public function mount()
    {
        $this->searchQuery = '';
        $this->isModalOpen = false;
        $this->searchResults = [];
        $this->allSearchableItems = [];
        $this->isCreateMode = false;
        $this->creatableItems = [];
        $this->autoOpenResource = null;
    }

    public function openSearchModal()
    {
        $this->isModalOpen = true;
        $this->loadSearchableItems();
        $this->dispatch('search-modal-opened');
    }

    public function closeSearchModal()
    {
        $this->isModalOpen = false;
        $this->searchQuery = '';
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
        $query = strtolower(trim($this->searchQuery));

        if (str_starts_with($query, 'new')) {
            $this->isCreateMode = true;
            $this->loadCreatableItems();
            $this->searchResults = [];

            // Check for sub-commands like "new project", "new server", etc.
            // Use original query (not trimmed) to ensure exact match without trailing spaces
            $this->autoOpenResource = $this->detectSpecificResource(strtolower($this->searchQuery));
        } else {
            $this->isCreateMode = false;
            $this->creatableItems = [];
            $this->autoOpenResource = null;
            $this->search();
        }
    }

    private function detectSpecificResource(string $query): ?string
    {
        // Map of keywords to resource types - order matters for multi-word matches
        $resourceMap = [
            'new project' => 'project',
            'new server' => 'server',
            'new team' => 'team',
            'new storage' => 'storage',
            'new s3' => 'storage',
            'new private key' => 'private-key',
            'new privatekey' => 'private-key',
            'new key' => 'private-key',
            'new github' => 'source',
            'new source' => 'source',
            'new git' => 'source',
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

        return match ($type) {
            'project', 'source' => $user->can('createAnyResource'),
            'server', 'storage', 'private-key' => $user->isAdmin() || $user->isOwner(),
            'team' => true,
            default => false,
        };
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
                        'search_text' => strtolower($app->name.' '.$app->description.' '.$fqdnsString),
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
                        'search_text' => strtolower($service->name.' '.$service->description.' '.$fqdnsString),
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
                            'search_text' => strtolower($db->name.' postgresql '.$db->description),
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
                            'search_text' => strtolower($db->name.' mysql '.$db->description),
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
                            'search_text' => strtolower($db->name.' mariadb '.$db->description),
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
                            'search_text' => strtolower($db->name.' mongodb '.$db->description),
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
                            'search_text' => strtolower($db->name.' redis '.$db->description),
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
                            'search_text' => strtolower($db->name.' keydb '.$db->description),
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
                            'search_text' => strtolower($db->name.' dragonfly '.$db->description),
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
                            'search_text' => strtolower($db->name.' clickhouse '.$db->description),
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
                        'search_text' => strtolower($server->name.' '.$server->ip.' '.$server->description),
                    ];
                });

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
                        'search_text' => strtolower($project->name.' '.$project->description.' project'),
                    ];
                });

            // Get all environments
            $environments = Environment::query()
                ->whereHas('project', function ($query) {
                    $query->where('team_id', auth()->user()->currentTeam()->id);
                })
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

            // Merge all collections
            $items = $items->merge($applications)
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
        if (strlen($this->searchQuery) < 2) {
            $this->searchResults = [];

            return;
        }

        $query = strtolower($this->searchQuery);

        // Case-insensitive search in the items
        $this->searchResults = collect($this->allSearchableItems)
            ->filter(function ($item) use ($query) {
                return str_contains($item['search_text'], $query);
            })
            ->take(20)
            ->values()
            ->toArray();
    }

    private function loadCreatableItems()
    {
        $items = collect();
        $user = auth()->user();

        // Project - can be created if user has createAnyResource permission
        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'Project',
                'description' => 'Create a new project to organize your resources',
                'type' => 'project',
                'component' => 'project.add-empty',
            ]);
        }

        // Server - can be created if user is admin or owner
        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'Server',
                'description' => 'Add a new server to deploy your applications',
                'type' => 'server',
                'component' => 'server.create',
            ]);
        }

        // Team - can be created by anyone (they become owner of new team)
        $items->push([
            'name' => 'Team',
            'description' => 'Create a new team to collaborate with others',
            'type' => 'team',
            'component' => 'team.create',
        ]);

        // Storage - can be created if user is admin or owner
        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'S3 Storage',
                'description' => 'Add S3 storage for backups and file uploads',
                'type' => 'storage',
                'component' => 'storage.create',
            ]);
        }

        // Private Key - can be created if user is admin or owner
        if ($user->isAdmin() || $user->isOwner()) {
            $items->push([
                'name' => 'Private Key',
                'description' => 'Add an SSH private key for server access',
                'type' => 'private-key',
                'component' => 'security.private-key.create',
            ]);
        }

        // GitHub Source - can be created if user has createAnyResource permission
        if ($user->can('createAnyResource')) {
            $items->push([
                'name' => 'GitHub App',
                'description' => 'Connect a GitHub app for source control',
                'type' => 'source',
                'component' => 'source.github.create',
            ]);
        }

        $this->creatableItems = $items->toArray();
    }

    public function render()
    {
        return view('livewire.global-search');
    }
}
