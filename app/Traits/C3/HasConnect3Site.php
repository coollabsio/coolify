<?php

namespace App\Traits\C3;

use App\Actions\C3\SyncProjectProxyConfig;
use App\Models\Application;
use Illuminate\Validation\ValidationException;
use Spatie\Url\Url;

/**
 * Connect3 fork: staged/live site model on Project (PRD section 4).
 *
 * Booted via Laravel's trait boot convention so the delta in Project.php is a single `use`.
 */
trait HasConnect3Site
{
    public static function bootHasConnect3Site(): void
    {
        static::saving(function ($project) {
            if ($project->isDirty('client_slug')) {
                if (is_string($project->client_slug) && str_contains($project->client_slug, '--')) {
                    throw ValidationException::withMessages([
                        'client_slug' => 'A double dash is reserved for preview hostnames.',
                    ]);
                }
                $slug = c3_normalizeSlug($project->client_slug);
                if ($slug !== null && ! c3_isValidSlug($slug)) {
                    throw ValidationException::withMessages([
                        'client_slug' => 'Client slug must be lowercase letters, digits and single dashes (DNS label, max 63 chars).',
                    ]);
                }
                $project->client_slug = $slug;
            }
            if (! in_array($project->site_state, [C3_STATE_STAGED, C3_STATE_LIVE], true)) {
                $project->site_state = C3_STATE_STAGED;
            }
            if (filled($project->client_slug)) {
                if (blank($project->staging_auth_user)) {
                    $project->staging_auth_user = $project->client_slug;
                }
                if (blank($project->staging_auth_pass)) {
                    $project->staging_auth_pass = c3_generatePassword();
                }
            }
        });

        static::saved(function ($project) {
            $watched = ['client_slug', 'staging_auth_user', 'staging_auth_pass', 'site_state', 'live_domains'];
            if ($project->wasRecentlyCreated || $project->wasChanged($watched)) {
                $previousSlug = $project->getOriginal('client_slug');
                SyncProjectProxyConfig::run($project, $previousSlug !== $project->client_slug ? $previousSlug : null);
            }
        });

        static::deleting(function ($project) {
            if (filled($project->client_slug)) {
                SyncProjectProxyConfig::run($project, null, true);
            }
        });
    }

    public function c3Enabled(): bool
    {
        return c3_enabled() && filled($this->client_slug);
    }

    public function isLive(): bool
    {
        return $this->site_state === C3_STATE_LIVE;
    }

    public function stagingHost(?string $prefix = null): ?string
    {
        $apex = c3_stagingApex();
        if (! $apex || blank($this->client_slug)) {
            return null;
        }

        return c3_stagingHost($this->client_slug, $apex, $prefix);
    }

    public function stagingUrl(): ?string
    {
        $host = $this->stagingHost();

        return $host ? "https://{$host}" : null;
    }

    /**
     * The hostname a given application in this project should get. The first application
     * takes the bare "<slug>.<apex>"; any further one gets "<app-name>--<slug>.<apex>".
     */
    public function stagingHostFor(Application $application): ?string
    {
        $bare = $this->stagingHost();
        if (! $bare) {
            return null;
        }
        $taken = $this->applications()
            ->where('applications.id', '!=', $application->id ?? 0)
            ->get()
            ->contains(fn (Application $other) => in_array($bare, $other->c3Hosts(), true));

        if (! $taken) {
            return $bare;
        }

        return $this->stagingHost(c3_normalizeSlug($application->name) ?? $application->uuid);
    }

    /**
     * @return array<int, string>
     */
    public function liveDomainsList(): array
    {
        return c3_parseDomainList($this->live_domains ?? []);
    }

    /**
     * The application that answers on "<slug>.<apex>"; falls back to the first application
     * with any domain so promotion has something to route to.
     */
    public function primaryApplication(): ?Application
    {
        $bare = $this->stagingHost();
        $apps = $this->applications()->get();
        if ($bare) {
            $match = $apps->first(fn (Application $app) => in_array($bare, $app->c3Hosts(), true));
            if ($match) {
                return $match;
            }
        }

        return $apps->first(fn (Application $app) => filled($app->fqdn));
    }

    /**
     * Name of the Traefik docker-provider service that fronts the primary application.
     * Mirrors the naming in fqdnLabelsForTraefik(): "<scheme>-<index>-<uuid>".
     */
    public function primaryDockerServiceName(): ?string
    {
        $app = $this->primaryApplication();
        if (! $app || blank($app->fqdn)) {
            return null;
        }
        $first = trim(explode(',', $app->fqdn)[0]);
        try {
            $scheme = Url::fromString($first)->getScheme();
        } catch (\Throwable) {
            $scheme = 'https';
        }
        $prefix = $scheme === 'https' ? 'https' : 'http';

        return "{$prefix}-0-{$app->uuid}";
    }

    /**
     * @return array<string, mixed>
     */
    public function c3DynamicConfig(): array
    {
        return c3_projectDynamicConfig(
            slug: $this->client_slug,
            username: $this->staging_auth_user,
            passwordHash: c3_htpasswdHash($this->staging_auth_pass),
            siteState: $this->site_state,
            liveDomains: $this->liveDomainsList(),
            dockerServiceName: $this->primaryDockerServiceName(),
        );
    }
}
