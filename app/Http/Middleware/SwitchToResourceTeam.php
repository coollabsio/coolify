<?php

namespace App\Http\Middleware;

use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;
use App\Models\Team;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resource pages look up their records in the session team. When a user opens a
 * direct link to a record of another team they belong to, switch the session to
 * that team first, so that the page opens instead of a 404.
 * Teams that the user is not a member of are never switched to.
 */
class SwitchToResourceTeam
{
    /**
     * Route parameters of pages that load a record owned by a team.
     *
     * @var array<string, class-string>
     */
    private const TEAM_OWNED = [
        'project_uuid' => Project::class,
        'server_uuid' => Server::class,
        'storage_uuid' => S3Storage::class,
        'private_key_uuid' => PrivateKey::class,
        'cloud_token_uuid' => CloudProviderToken::class,
        'cloud_init_script_uuid' => CloudInitScript::class,
    ];

    /**
     * Route parameters of git sources. A system-wide source opens from every team.
     *
     * @var array<string, class-string>
     */
    private const GIT_SOURCES = [
        'github_app_uuid' => GithubApp::class,
        'gitlab_app_uuid' => GitlabApp::class,
        'gitea_app_uuid' => GiteaApp::class,
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $teamId = $this->resourceTeamId($request);
        if (! is_null($teamId)
            && $teamId !== data_get(session('currentTeam'), 'id')
            && $user->teams->contains('id', $teamId)) {
            refreshSession(Team::find($teamId));
        }

        return $next($request);
    }

    private function resourceTeamId(Request $request): ?int
    {
        $route = $request->route();
        if (! $route) {
            return null;
        }

        foreach (self::TEAM_OWNED as $parameter => $model) {
            if (filled($uuid = $route->parameter($parameter))) {
                return $model::query()->where('uuid', $uuid)->value('team_id');
            }
        }

        foreach (self::GIT_SOURCES as $parameter => $model) {
            if (filled($uuid = $route->parameter($parameter))) {
                return $model::query()->where('uuid', $uuid)->where('is_system_wide', false)->value('team_id');
            }
        }

        if (filled($uuid = $route->parameter('destination_uuid'))) {
            $destination = StandaloneDocker::query()->where('uuid', $uuid)->first()
                ?? SwarmDocker::query()->where('uuid', $uuid)->first();

            return $destination?->server()->value('team_id');
        }

        return null;
    }
}
