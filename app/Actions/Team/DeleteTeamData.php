<?php

namespace App\Actions\Team;

use App\Actions\Service\DeleteService;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\DiscordNotificationSettings;
use App\Models\DnsProviderZone;
use App\Models\DockerCleanupExecution;
use App\Models\EmailNotificationSettings;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GithubRunnerConfig;
use App\Models\GithubRunnerExecution;
use App\Models\IntegrationToken;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Models\Project;
use App\Models\PushoverNotificationSettings;
use App\Models\ScheduledDatabaseBackup;
use App\Models\ScheduledDatabaseBackupExecution;
use App\Models\ScheduledTask;
use App\Models\ScheduledTaskExecution;
use App\Models\ScheduledVolumeBackup;
use App\Models\ScheduledVolumeBackupExecution;
use App\Models\SecretManagerLink;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
use App\Models\SharedEnvironmentVariable;
use App\Models\SlackNotificationSettings;
use App\Models\SslCertificate;
use App\Models\Subscription;
use App\Models\Team;
use App\Models\TeamInvitation;
use App\Models\TelegramNotificationSettings;
use App\Models\WebhookNotificationSettings;
use App\Services\ProjectIconStorageService;
use Illuminate\Contracts\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Removes every record Coolify keeps for a team: servers, resources (also soft-deleted ones and
 * ones without a destination), projects, keys, sources, storages, tokens, settings, and the
 * subscription. Nothing runs on the team's servers; their containers keep running.
 *
 * purge() keeps the team and its members; handle() also deletes the team. Users are never
 * deleted. Callers decide what happens to members without another team.
 */
class DeleteTeamData
{
    private const NOTIFICATION_SETTINGS_MODELS = [
        EmailNotificationSettings::class,
        DiscordNotificationSettings::class,
        SlackNotificationSettings::class,
        TelegramNotificationSettings::class,
        PushoverNotificationSettings::class,
        WebhookNotificationSettings::class,
    ];

    /**
     * Deletes the team with its records and memberships.
     *
     * @return array{servers: int, resources: int, projects: int}
     */
    public function handle(Team $team): array
    {
        return DB::transaction(function () use ($team): array {
            $counts = $this->purge($team);

            foreach ($team->members()->get() as $member) {
                $member->clearStoredTeamIfMatches($team->id);
                DB::table('sessions')->where('user_id', $member->id)->delete();
            }
            $team->members()->detach();
            foreach (self::NOTIFICATION_SETTINGS_MODELS as $model) {
                $model::query()->where('team_id', $team->id)->delete();
            }

            $team->delete();

            return $counts;
        });
    }

    /**
     * Deletes the records of the team but keeps the team, its memberships, and notification
     * settings reset to the defaults of a new team.
     *
     * @return array{servers: int, resources: int, projects: int}
     */
    public function purge(Team $team): array
    {
        if ($team->id === 0) {
            throw new LogicException('The root team (id 0) is never deleted or purged.');
        }

        return DB::transaction(function () use ($team): array {
            $projects = Project::query()->where('team_id', $team->id)->get();
            $environmentIds = Environment::query()->whereIn('project_id', $projects->modelKeys())->pluck('id');
            $servers = Server::withTrashed()->where('team_id', $team->id)->get();
            $resources = $this->resources($environmentIds);
            $applicationIds = $resources->whereInstanceOf(Application::class)->modelKeys();
            $serviceIds = $resources->whereInstanceOf(Service::class)->modelKeys();
            $owners = $resources
                ->concat(ServiceApplication::withTrashed()->whereIn('service_id', $serviceIds)->get())
                ->concat(ServiceDatabase::withTrashed()->whereIn('service_id', $serviceIds)->get())
                ->concat(ApplicationPreview::withTrashed()->whereIn('application_id', $applicationIds)->get());

            $this->forgetGithubRunners($team, $servers->modelKeys());

            $backupIds = ScheduledDatabaseBackup::query()
                ->where('team_id', $team->id)
                ->orWhere(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'database'))
                ->pluck('id');
            ScheduledDatabaseBackupExecution::query()->whereIn('scheduled_database_backup_id', $backupIds)->delete();
            ScheduledDatabaseBackup::query()->whereKey($backupIds)->delete();

            $taskIds = ScheduledTask::query()
                ->where('team_id', $team->id)
                ->orWhereIn('application_id', $applicationIds)
                ->orWhereIn('service_id', $serviceIds)
                ->pluck('id');
            ScheduledTaskExecution::query()->whereIn('scheduled_task_id', $taskIds)->delete();
            ScheduledTask::query()->whereKey($taskIds)->delete();

            // Previews are removed with a query: their model hook removes volumes on the server.
            ApplicationPreview::withTrashed()->whereIn('application_id', $applicationIds)->forceDelete();
            ApplicationDeploymentQueue::query()
                ->whereIn('application_id', array_map('strval', $applicationIds))
                ->orWhereIn('server_id', $servers->modelKeys())
                ->delete();

            // Without references, deleting a resource does not queue the removal of its DNS records at the team's provider.
            ManagedDnsRecordReference::query()->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'resource'))->delete();
            // Volume rows go first, so their backup schedule guards do not block the resource deletion.
            LocalPersistentVolume::query()->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'resource'))->delete();
            LocalFileVolume::query()->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'resource'))->delete();

            foreach ($resources as $resource) {
                $resource instanceof Service
                    ? app(DeleteService::class)->deleteLocal($resource)
                    : $resource->forceDelete();
            }
            ServiceApplication::withTrashed()->whereIn('service_id', $serviceIds)->forceDelete();
            ServiceDatabase::withTrashed()->whereIn('service_id', $serviceIds)->forceDelete();

            EnvironmentVariable::query()->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'resourceable'))->delete();
            SecretManagerLink::query()->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'resourceable'))->delete();
            SslCertificate::query()->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'resource'))->delete();
            DB::table('taggables')->where(fn (QueryBuilder $query) => $this->whereOwnedBy($query, $owners, 'taggable'))->delete();

            DockerCleanupExecution::query()->whereIn('server_id', $servers->modelKeys())->delete();
            foreach ($servers as $server) {
                $server->forceDelete();
            }

            $iconStorage = app(ProjectIconStorageService::class);
            foreach ($projects as $project) {
                $iconStorage->deleteProject($project);
                $project->delete();
            }

            // Production has cascading foreign keys for most of these; the test schema does not.
            $integrationTokenIds = IntegrationToken::query()->where('team_id', $team->id)->pluck('id');
            SecretManagerLink::query()->whereIn('integration_token_id', $integrationTokenIds)->delete();
            ManagedDnsRecord::query()->where('team_id', $team->id)->delete();
            DnsProviderZone::query()->whereIn('integration_token_id', $integrationTokenIds)->delete();
            IntegrationToken::query()->whereKey($integrationTokenIds)->delete();
            $volumeBackupIds = ScheduledVolumeBackup::query()->where('team_id', $team->id)->pluck('id');
            ScheduledVolumeBackupExecution::query()->whereIn('scheduled_volume_backup_id', $volumeBackupIds)->delete();
            ScheduledVolumeBackup::query()->whereKey($volumeBackupIds)->delete();
            foreach ([CloudProviderToken::class, CloudInitScript::class, SharedEnvironmentVariable::class, TeamInvitation::class, Subscription::class, ...self::NOTIFICATION_SETTINGS_MODELS] as $model) {
                $model::query()->where('team_id', $team->id)->delete();
            }
            $team->deleteOwnedRecords();
            $team->createDefaultNotificationSettings();

            // The cached team of a member still holds the removed subscription.
            foreach ($team->members()->pluck('users.id') as $memberId) {
                Cache::forget("user:{$memberId}:team:{$team->id}");
            }

            return [
                'servers' => $servers->count(),
                'resources' => $resources->count(),
                'projects' => $projects->count(),
            ];
        });
    }

    /**
     * @param  Collection<int, int>  $environmentIds
     * @return EloquentCollection<int, Model>
     */
    private function resources(Collection $environmentIds): Collection
    {
        return new EloquentCollection(collect([Application::class, Service::class, ...STANDALONE_DATABASE_MODELS])
            ->flatMap(fn (string $model) => $model::withTrashed()->whereIn('environment_id', $environmentIds)->get())
            ->all());
    }

    /**
     * Matches rows of a polymorphic relation that belong to one of the owners.
     *
     * @param  Collection<int, Model>  $owners
     */
    private function whereOwnedBy(QueryBuilder $query, Collection $owners, string $morph): void
    {
        $query->whereRaw('1 = 0');

        foreach ($owners->groupBy(fn (Model $owner) => $owner->getMorphClass()) as $type => $group) {
            $query->orWhere(fn (QueryBuilder $query) => $query
                ->where("{$morph}_type", $type)
                ->whereIn("{$morph}_id", $group->map->getKey()->all()));
        }
    }

    /**
     * Removes the runner records of the team's servers and GitHub Apps, so that busy runners do not
     * block deleting them. The runners keep running on the servers; Coolify only stops tracking them.
     *
     * @param  array<int, int>  $serverIds
     */
    private function forgetGithubRunners(Team $team, array $serverIds): void
    {
        $githubAppIds = GithubApp::query()->where('team_id', $team->id)->where('is_system_wide', false)->pluck('id');
        $belongsToTeam = fn ($query) => $query->whereIn('github_app_id', $githubAppIds)->orWhereIn('server_id', $serverIds);

        GithubRunnerExecution::query()->where($belongsToTeam)->delete();
        GithubRunnerConfig::query()->where($belongsToTeam)->delete();
    }
}
