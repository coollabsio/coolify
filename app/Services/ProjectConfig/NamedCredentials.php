<?php

namespace App\Services\ProjectConfig;

use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\IntegrationToken;
use App\Models\PrivateKey;
use App\Models\S3Storage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Finds the team credential that coolify.json names. Names are not unique in Coolify, so a name must match
 * exactly one credential. A credential of the team wins over a system-wide git app with the same name.
 */
class NamedCredentials
{
    private const LABELS = [
        'github_app' => 'GitHub app',
        'gitlab_app' => 'GitLab app',
        'gitea_app' => 'Gitea app',
        'deploy_key' => 'private key',
        's3_storage' => 'S3 storage',
        'token' => 'integration token',
    ];

    public static function find(string $kind, string $name, int $teamId): ?Model
    {
        $matches = self::matches($kind, $name, $teamId);

        return $matches->count() === 1 ? $matches->first() : null;
    }

    /**
     * Why the name does not select one credential, or null when it does.
     */
    public static function error(string $kind, string $name, int $teamId): ?string
    {
        $label = self::LABELS[$kind];

        return match ($count = self::matches($kind, $name, $teamId)->count()) {
            1 => null,
            0 => "No {$label} named \"{$name}\" is available to this team.",
            default => "{$count} {$label}s are named \"{$name}\". Give one of them another name in Coolify.",
        };
    }

    /**
     * @return Collection<int, Model>
     */
    private static function matches(string $kind, string $name, int $teamId): Collection
    {
        $query = match ($kind) {
            'deploy_key' => PrivateKey::query()->where('team_id', $teamId),
            's3_storage' => S3Storage::query()->where('team_id', $teamId),
            'token' => IntegrationToken::query()->where('team_id', $teamId),
            'github_app' => self::gitApp(GithubApp::query(), $teamId),
            'gitea_app' => self::gitApp(GiteaApp::query(), $teamId),
            'gitlab_app' => self::gitApp(GitlabApp::query()->where('is_public', false), $teamId),
        };
        $matches = $query->where('name', $name)->get();
        $own = $matches->filter(fn (Model $model) => (int) $model->team_id === $teamId);

        return ($own->isNotEmpty() ? $own : $matches)->values();
    }

    private static function gitApp(Builder $query, int $teamId): Builder
    {
        return $query->where(fn (Builder $query) => $query->where('team_id', $teamId)->orWhere('is_system_wide', true));
    }
}
