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

    public function mount()
    {
        $this->searchQuery = '';
        $this->isModalOpen = false;
        $this->searchResults = [];
        $this->allSearchableItems = [];
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
        $this->search();
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

    public function render()
    {
        return view('livewire.global-search');
    }
}
