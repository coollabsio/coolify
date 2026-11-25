<?php

namespace App\Services\ProjectConfig;

use App\Enums\AccessLevel;
use App\Enums\ConfigRevisionStatus;
use App\Jobs\ApplyEnvironmentConfigJob;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\EnvironmentConfigRevision;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\User;
use App\Policies\ResourceCreatePolicy;
use App\Rules\ValidGitRepositoryUrl;
use App\Services\Access\AccessResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Creates links and changes their state. Authorizes with the team of the link, not the session team.
 */
class ConfigLinks
{
    public function __construct(private readonly ConfigRevisions $revisions) {}

    /**
     * @param  array{project: string, environment: string, mode: string, server_uuid?: ?string, destination_uuid?: ?string}  $data
     *
     * @throws AuthorizationException
     * @throws RuntimeException with code 409 when the environment already has a link
     */
    public function create(User $user, int $teamId, array $data): EnvironmentConfigLink
    {
        if (! app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, $teamId)) {
            throw new AuthorizationException('Linking coolify.json needs write access to the whole team.');
        }

        return DB::transaction(function () use ($user, $teamId, $data) {
            $environment = $this->findOrCreateEnvironment($user, $teamId, $data['project'], $data['environment']);
            if (! app(ResourceCreatePolicy::class)->createAny($user, $environment)) {
                throw new AuthorizationException('You cannot create resources in this environment.');
            }
            if ($environment->configLink()->exists()) {
                throw new RuntimeException('This environment already has a coolify.json link.', 409);
            }

            $attributes = [
                'environment_id' => $environment->id,
                'mode' => $data['mode'],
                'auto_apply' => $data['auto_apply'] ?? true,
                'created_by' => $user->id,
            ];
            $attributes += $data['mode'] === EnvironmentConfigLink::MODE_GIT
                ? $this->gitAttributes($user, $teamId, $data)
                : $this->placementAttributes($user, $teamId, $data);

            return EnvironmentConfigLink::create($attributes);
        });
    }

    /**
     * A git link that is not saved, to read and plan a file one time (a template or a link preview).
     *
     * @param  array{repository: string, branch: string, file_path?: ?string, source_type: string, source_uuid?: ?string, server_uuid: string, destination_uuid?: ?string}  $data
     *
     * @throws AuthorizationException when a git source or deploy key is used without write access to the whole team
     * @throws ValidationException
     */
    public function unsavedGitLink(User $user, Environment $environment, array $data): EnvironmentConfigLink
    {
        $teamId = (int) $environment->project->team_id;
        if ($data['source_type'] !== 'public' && ! app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, $teamId)) {
            throw new AuthorizationException('You need write access on the whole team to use team credentials, such as private keys and git sources.');
        }
        $link = (new EnvironmentConfigLink)->forceFill([
            'environment_id' => $environment->id,
            'mode' => EnvironmentConfigLink::MODE_GIT,
            ...$this->gitAttributes($user, $teamId, $data),
        ]);
        $link->setRelation('environment', $environment);

        return $link;
    }

    /**
     * Source, repository, and server of a git link. Every record must belong to the team.
     *
     * @throws ValidationException
     */
    public function gitAttributes(User $user, int $teamId, array $data): array
    {
        Validator::make($data, [
            'repository' => ['required', 'string', 'max:500', new ValidGitRepositoryUrl(allowRepositoryPath: true)],
            'file_path' => ['nullable', 'string', 'max:255', 'regex:#^[A-Za-z0-9._/-]+\z#', 'not_regex:#(^|/)\.\.(/|\z)#'],
        ])->validate();
        if (blank($data['server_uuid'] ?? null)) {
            throw ValidationException::withMessages(['server_uuid' => ['A git link needs a server to read the repository.']]);
        }

        $attributes = [
            'repository' => $data['repository'],
            'branch' => $data['branch'],
            'file_path' => $data['file_path'] ?? 'coolify.json',
            'webhook_secret' => Str::random(40),
        ] + $this->placementAttributes($user, $teamId, $data);
        $kind = $data['source_type'];
        if ($kind === 'public') {
            return $attributes;
        }
        if ($kind === 'deploy_key') {
            $key = PrivateKey::query()->where('team_id', $teamId)->where('uuid', $data['source_uuid'] ?? '')->first()
                ?? throw ValidationException::withMessages(['source_uuid' => ['No private key with this UUID is in this team.']]);

            return $attributes + ['private_key_id' => $key->id];
        }

        $class = ['github_app' => GithubApp::class, 'gitlab_app' => GitlabApp::class, 'gitea_app' => GiteaApp::class][$kind];
        $source = $class::query()
            ->where('uuid', $data['source_uuid'] ?? '')
            ->when($kind === 'gitlab_app', fn ($query) => $query->where('is_public', false)->whereNotNull('access_token'))
            ->where(fn ($query) => $query->where('team_id', $teamId)->orWhere('is_system_wide', true))
            ->first() ?? throw ValidationException::withMessages(['source_uuid' => ['No git source with this UUID is available to this team.']]);

        return $attributes + ['source_type' => $source::class, 'source_id' => $source->id];
    }

    /**
     * The server of the link reads the file, and resources that name no server go to it. The destination is
     * needed only when that server has more than one. Both are optional for an api link.
     *
     * @return array{read_server_id?: int, destination_type?: string, destination_id?: int}
     *
     * @throws ValidationException
     */
    public function placementAttributes(User $user, int $teamId, array $data): array
    {
        if (blank($data['server_uuid'] ?? null)) {
            return [];
        }
        $server = Server::query()->where('team_id', $teamId)->where('uuid', $data['server_uuid'])->first();
        if ($server === null || $server->isTransferredAway() || ! Gate::forUser($user)->allows('placeResource', $server)) {
            throw ValidationException::withMessages(['server_uuid' => ['Use a server of this team that you can place resources on.']]);
        }
        $attributes = ['read_server_id' => $server->id];
        if (filled($data['destination_uuid'] ?? null)) {
            $destination = $server->destinations()->first(fn ($destination) => $destination->uuid === $data['destination_uuid'])
                ?? throw ValidationException::withMessages(['destination_uuid' => ['Use a destination of the selected server.']]);
            $attributes += ['destination_type' => $destination->getMorphClass(), 'destination_id' => $destination->id];
        }

        return $attributes;
    }

    private function findOrCreateEnvironment(User $user, int $teamId, string $projectName, string $environmentName): Environment
    {
        $project = Project::query()->where('team_id', $teamId)->where('name', $projectName)->first();
        if ($project === null) {
            if (! app(AccessResolver::class)->allowsTeamWide($user, AccessLevel::Write, $teamId)) {
                throw new AuthorizationException('You cannot create projects in this team.');
            }
            $project = Project::create(['name' => $projectName, 'team_id' => $teamId]);
        }

        $environment = $project->environments()->where('name', $environmentName)->first();
        if ($environment === null) {
            if (! Gate::forUser($user)->allows('create', [Environment::class, $project])) {
                throw new AuthorizationException('You cannot create environments in this project.');
            }
            $environment = $project->environments()->create(['name' => $environmentName]);
        }

        return $environment;
    }

    /**
     * Plans a file in api mode and applies it when the plan is safe.
     */
    public function applyFile(EnvironmentConfigLink $link, string $raw, User $user): EnvironmentConfigRevision
    {
        $revision = $this->revisions->record($link, $raw, 'api', creator: $user);
        $this->dispatchWhenPlanned($revision);

        return $revision->fresh();
    }

    public function dispatchWhenPlanned(EnvironmentConfigRevision $revision): void
    {
        if ($revision->status === ConfigRevisionStatus::Failed) {
            $this->revisions->fail($revision, (string) $revision->error);
        } elseif ($revision->status === ConfigRevisionStatus::Planned && ! $revision->link->isSuspended()) {
            ApplyEnvironmentConfigJob::dispatch($revision);
        }
    }

    /**
     * @param  list<string>  $deleteData
     */
    public function approve(EnvironmentConfigRevision $revision, User $user, array $deleteData): EnvironmentConfigRevision
    {
        $revision->update(['approved_by' => $user->id, 'approved_at' => now(), 'delete_data' => array_values($deleteData)]);
        ApplyEnvironmentConfigJob::dispatch($revision->fresh());

        return $revision->fresh();
    }

    /**
     * Removes the link. The resources stay and can be edited in Coolify again; their keys stay for a later link.
     * The file no longer gates deploys, so held deploys of revisions that did not apply run now.
     */
    public function disconnect(EnvironmentConfigLink $link, User $user): void
    {
        $this->authorizeTeamAdmin($link, $user);
        auditLog('system.project_config.disconnected', ['team_id' => $link->teamId(), 'environment_uuid' => $link->environment->uuid, 'actor_id' => $user->id]);
        $commits = [];
        $waiting = $link->revisions()
            ->whereIn('status', [ConfigRevisionStatus::Planned->value, ConfigRevisionStatus::WaitingApproval->value])
            ->orderBy('id')
            ->get();
        foreach ($waiting as $revision) {
            foreach ($revision->held_deployments ?? [] as $held) {
                $commits[$held['application_uuid']] = $held['commit_sha'] ?? null;
            }
        }
        if ($link->pending_pushed_at !== null) {
            foreach ((array) $link->pending_held_deployments as $uuid) {
                $commits[$uuid] ??= $link->pending_commit_sha;
            }
        }
        $link->delete();
        app(HeldDeployments::class)->deployEach($link, $commits);
    }

    public function setAutoApply(EnvironmentConfigLink $link, User $user, bool $autoApply): void
    {
        $this->authorizeTeamAdmin($link, $user);
        $link->update(['auto_apply' => $autoApply]);
    }

    /**
     * The plan of the newest file when the link uses another server: resources without "server" in the file move.
     * Nothing is saved.
     *
     * @param  array{server_uuid?: ?string, destination_uuid?: ?string}  $data
     * @return array{plan: ?array, error: ?string}
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function previewServerChange(EnvironmentConfigLink $link, User $user, array $data): array
    {
        $this->authorizeTeamAdmin($link, $user);
        $preview = $link->replicate()->unsetRelation('readServer')->unsetRelation('destination');
        $preview->forceFill(['destination_type' => null, 'destination_id' => null] + $this->requiredPlacement($user, $link, $data));
        $preview->setRelation('environment', $link->environment);
        if ($link->isGit()) {
            return app(ConfigLinkPreview::class)->plan($preview, $user);
        }
        $raw = $this->latestFile($link);
        if ($raw === null) {
            return ['plan' => null, 'error' => 'No file was applied yet.'];
        }
        try {
            return ['plan' => app(ConfigPlanner::class)->plan($this->revisions->resolve($preview, $raw), ApplyContext::for($preview, $user))->toArray(), 'error' => null];
        } catch (ConfigException $e) {
            return ['plan' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * Saves another server for the link and plans the newest file again. A resource that moves is a destructive
     * change, so that plan waits for approval.
     *
     * @param  array{server_uuid?: ?string, destination_uuid?: ?string}  $data
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function changeServer(EnvironmentConfigLink $link, User $user, array $data): void
    {
        $this->authorizeTeamAdmin($link, $user);
        $link->update(['destination_type' => null, 'destination_id' => null] + $this->requiredPlacement($user, $link, $data));
        auditLog('system.project_config.server_changed', [
            'team_id' => $link->teamId(),
            'environment_uuid' => $link->environment->uuid,
            'server_uuid' => $link->fresh()->readServer?->uuid,
            'actor_id' => $user->id,
        ]);
        if ($link->isGit()) {
            PlanEnvironmentConfigJob::dispatch($link->fresh(), 'link');

            return;
        }
        $raw = $this->latestFile($link);
        if ($raw !== null) {
            $revision = $this->revisions->record($link->fresh(), $raw, 'retry', creator: $user);
            $this->revisions->supersedeOlder($revision);
            $this->dispatchWhenPlanned($revision->refresh());
        }
    }

    /**
     * @return array{read_server_id: int, destination_type?: string, destination_id?: int}
     */
    private function requiredPlacement(User $user, EnvironmentConfigLink $link, array $data): array
    {
        $attributes = $this->placementAttributes($user, $link->teamId(), $data);
        if (! isset($attributes['read_server_id'])) {
            throw ValidationException::withMessages(['server_uuid' => ['Choose a server.']]);
        }

        return $attributes;
    }

    /**
     * @throws AuthorizationException
     */
    private function authorizeTeamAdmin(EnvironmentConfigLink $link, User $user): void
    {
        if (! $user->isAdminOfTeam($link->teamId())) {
            throw new AuthorizationException('Only a team admin can change the coolify.json link.');
        }
    }

    public function suspend(EnvironmentConfigLink $link, User $user): void
    {
        $link->update(['suspended_at' => now(), 'suspended_by' => $user->id]);
        auditLog('system.project_config.suspended', ['team_id' => $link->teamId(), 'environment_uuid' => $link->environment->uuid, 'actor_id' => $user->id]);
    }

    /**
     * Without confirmation: the plan of the latest file, not stored. With confirmation: resume and apply.
     */
    public function resume(EnvironmentConfigLink $link, User $user, bool $confirm): ConfigPlan|EnvironmentConfigRevision|null
    {
        $raw = $this->latestFile($link);
        if (! $confirm) {
            return $raw === null ? null : app(ConfigPlanner::class)->plan($this->revisions->resolve($link, $raw), ApplyContext::for($link, $user));
        }

        $link->update(['suspended_at' => null, 'suspended_by' => null]);
        auditLog('system.project_config.resumed', ['team_id' => $link->teamId(), 'environment_uuid' => $link->environment->uuid, 'actor_id' => $user->id]);
        if ($raw === null) {
            return null;
        }
        $revision = $this->revisions->record($link->fresh(), $raw, 'retry', $link->revisions()->latest('id')->value('commit_sha'), $user);
        $this->dispatchWhenPlanned($revision);

        return $revision->fresh();
    }

    public function release(EnvironmentConfigRevision $revision, User $user): void
    {
        $this->revisions->fail($revision, "Released by {$user->name}.", [ConfigRevisionStatus::Applying]);
    }

    public function retry(EnvironmentConfigRevision $revision, User $user): EnvironmentConfigRevision
    {
        $new = $this->revisions->record($revision->link, (string) $revision->file_contents, 'retry', $revision->commit_sha, $user, $revision->held_deployments ?? []);
        $this->dispatchWhenPlanned($new);

        return $new->fresh();
    }

    private function latestFile(EnvironmentConfigLink $link): ?string
    {
        return $link->revisions()->whereNotNull('file_contents')->latest('id')->first()?->file_contents;
    }
}
