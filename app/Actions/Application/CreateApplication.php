<?php

namespace App\Actions\Application;

use App\Data\ResourcePlacement;
use App\Exceptions\DomainAlreadyInUseException;
use App\Exceptions\GithubAppNotFoundException;
use App\Exceptions\GitRepositoryNotAccessibleException;
use App\Exceptions\InvalidDockerComposeDomainsException;
use App\Exceptions\PrivateKeyNotFoundException;
use App\Models\Application;
use App\Models\GithubApp;
use App\Models\PrivateKey;
use App\Services\DockerImageParser;
use App\Support\DomainPortOverrides;
use App\Support\ValidationPatterns;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Lorisleiva\Actions\Concerns\AsAction;
use Spatie\Url\Url;

class CreateApplication
{
    use AsAction;

    public const TYPES = ['public', 'private-gh-app', 'private-deploy-key', 'dockerfile', 'dockerimage'];

    /** Request flags that map onto ApplicationSetting columns. */
    public const FLAG_KEYS = [
        'is_static' => 'is_static',
        'is_spa' => 'is_spa',
        'is_auto_deploy_enabled' => 'is_auto_deploy_enabled',
        'is_force_https_enabled' => 'is_force_https_enabled',
        'is_preview_deployments_enabled' => 'is_preview_deployments_enabled',
        'connect_to_docker_network' => 'connect_to_docker_network',
        'use_build_server' => 'is_build_server_enabled',
        'use_build_secrets' => 'use_build_secrets',
        'is_container_label_escape_enabled' => 'is_container_label_escape_enabled',
        'is_preserve_repository_enabled' => 'is_preserve_repository_enabled',
    ];

    /** Keys that steer creation but are not Application columns. */
    public const CONTROL_KEYS = [
        'domains', 'autogenerate_domain', 'force_domain_override', 'github_app_uuid', 'private_key_uuid',
        'docker_compose_domains', 'docker_compose_raw', 'tags', 'instant_deploy', 'type',
    ];

    private const DOMAIN_CONFLICT_WARNING = 'Using the same domain for multiple resources can cause routing conflicts and unpredictable behavior.';

    /**
     * @param  array<string, mixed>  $data  Validated, already-decoded input.
     */
    public function handle(ResourcePlacement $placement, string $type, array $data, bool $instantDeploy = false): Application
    {
        if (! in_array($type, self::TYPES, true)) {
            throw new \InvalidArgumentException("Unknown application type [{$type}].");
        }

        $teamId = (int) $placement->project->team_id;
        $fqdn = $data['domains'] ?? null;
        // Compose is only valid for the git-based types; guard it so a dockerfile
        // or dockerimage request carrying build_pack=dockercompose can't trigger
        // compose-domain handling / LoadComposeFile.
        $isCompose = in_array($type, ['public', 'private-gh-app', 'private-deploy-key'], true)
            && ($data['build_pack'] ?? null) === 'dockercompose';

        $application = new Application;
        $application->fill(Arr::except($data, [
            ...self::CONTROL_KEYS, ...array_keys(self::FLAG_KEYS), ...Application::API_SETTING_FIELDS,
        ]));

        match ($type) {
            'public' => $this->buildPublic($application, $data),
            'private-gh-app' => $this->buildPrivateGithubApp($application, $data, $teamId),
            'private-deploy-key' => $this->buildPrivateDeployKey($application, $data, $teamId),
            'dockerfile' => $this->buildDockerfile($application, $data),
            'dockerimage' => $this->buildDockerImage($application, $data),
        };

        if ($isCompose) {
            $application->ports_exposes = $application->ports_exposes ?: '80';
            $this->applyComposeDomains($application, $data, $teamId);
        } elseif (filled($fqdn) && $placement->server->isProxyShouldRun()) {
            $this->assertDomainsAvailable($fqdn, $teamId, (bool) ($data['force_domain_override'] ?? false));
        }

        $application->fqdn = $isCompose ? null : $fqdn;
        $application->destination_id = $placement->destination->id;
        $application->destination_type = $placement->destination->getMorphClass();
        $application->environment_id = $placement->environment->id;
        $application->save();

        $application->applyApiSettings(Arr::only($data, Application::API_SETTING_FIELDS));
        foreach (self::FLAG_KEYS as $key => $column) {
            if (isset($data[$key])) {
                $application->settings->{$column} = $data[$key];
            }
        }
        $application->settings->save();
        $application->refresh();

        if (($data['autogenerate_domain'] ?? true) && blank($application->fqdn) && ! $isCompose) {
            $application->fqdn = generateUrl(server: $placement->server, random: $application->uuid);
            $application->save();
        }
        if ($application->settings->is_container_label_readonly_enabled) {
            $application->custom_labels = str(implode('|coolify|', generateLabelsApplication($application)))->replace('|coolify|', "\n");
            $application->save();
        }
        $application->isConfigurationChanged(true);

        if ($instantDeploy) {
            queue_application_deployment(
                application: $application,
                deployment_uuid: new_public_id(),
                no_questions_asked: true,
                is_api: true,
            );
        } elseif ($isCompose) {
            LoadComposeFile::dispatch($application);
        }

        return $application;
    }

    /** @param array<string, mixed> $data */
    private function buildPublic(Application $application, array $data): void
    {
        $application->name = $data['name'] ?? generate_application_name($data['git_repository'], $data['git_branch']);
        $httpsRepository = scpStyleGitUrlToHttps($application->git_repository);
        if (is_string($httpsRepository)) {
            $application->git_repository = $httpsRepository;
        }
        $parsed = Url::fromString($application->git_repository);
        if ($parsed->getHost() === 'github.com') {
            $application->source_type = GithubApp::class;
            $application->source_id = GithubApp::find(0)->id;
            $application->git_repository = str($parsed->getSegment(1).'/'.$parsed->getSegment(2))->trim()->toString();
        }
    }

    /** @param array<string, mixed> $data */
    private function buildPrivateGithubApp(Application $application, array $data, int $teamId): void
    {
        $application->name = $data['name'] ?? generate_application_name($data['git_repository'], $data['git_branch']);

        $githubApp = GithubApp::where('uuid', $data['github_app_uuid'] ?? null)
            ->where(fn ($q) => $q->where('team_id', $teamId)->orWhere('is_system_wide', true))
            ->first();
        if (! $githubApp) {
            throw new GithubAppNotFoundException;
        }
        $token = generateGithubInstallationToken($githubApp);
        if (! $token) {
            throw new GitRepositoryNotAccessibleException('Failed to generate Github App token.');
        }

        $repo = str($data['git_repository']);
        if ($repo->startsWith('http') || $repo->contains('github.com')) {
            $repo = $repo->replace('https://', '')->replace('http://', '')->replace('github.com/', '');
        }
        $repo = $repo->trim('/')->replaceEnd('.git', '')->toString();

        $response = Http::GitHub($githubApp->api_url, $token)->timeout(20)->retry(3, 200, throw: false)->get("/repos/{$repo}");
        if (in_array($response->status(), [403, 404], true)) {
            throw new GitRepositoryNotAccessibleException;
        }
        if (! $response->successful()) {
            throw new GitRepositoryNotAccessibleException('Failed to verify repository access: '.($response->json()['message'] ?? 'Unknown error'));
        }

        $application->git_repository = $repo;
        $application->source_type = $githubApp->getMorphClass();
        $application->source_id = $githubApp->id;
        $application->repository_project_id = data_get($response->json(), 'id');
    }

    /** @param array<string, mixed> $data */
    private function buildPrivateDeployKey(Application $application, array $data, int $teamId): void
    {
        $application->name = $data['name'] ?? generate_application_name($data['git_repository'], $data['git_branch']);
        $key = PrivateKey::whereTeamId($teamId)->where('uuid', $data['private_key_uuid'] ?? null)->first();
        if (! $key) {
            throw new PrivateKeyNotFoundException;
        }
        $application->private_key_id = $key->id;
    }

    /** @param array<string, mixed> $data */
    private function buildDockerfile(Application $application, array $data): void
    {
        $application->name = $data['name'] ?? 'dockerfile-'.new_public_id();
        $application->dockerfile = $data['dockerfile'];
        $application->ports_exposes = get_port_from_dockerfile($data['dockerfile']) ?? 80;
        $application->build_pack = 'dockerfile';
        $application->git_repository = 'coollabsio/coolify';
        $application->git_branch = 'main';
    }

    /** @param array<string, mixed> $data */
    private function buildDockerImage(Application $application, array $data): void
    {
        $application->name = $data['name'] ?? 'docker-image-'.new_public_id();
        $image = $data['docker_registry_image_name'];
        $tag = $data['docker_registry_image_tag'] ?? null;
        $parser = new DockerImageParser;
        $parser->parse($tag ? "{$image}:{$tag}" : $image);
        $name = $parser->getFullImageNameWithoutTag();
        if ($parser->isImageHash() && ! str_ends_with($name, '@sha256')) {
            $name .= '@sha256';
        }
        $application->docker_registry_image_name = $name;
        $application->docker_registry_image_tag = $parser->getTag();
        $application->build_pack = 'dockerimage';
        $application->git_repository = 'coollabsio/coolify';
        $application->git_branch = 'main';
    }

    private function assertDomainsAvailable(string $domains, int $teamId, bool $force): void
    {
        $urls = collect(ValidationPatterns::applicationDomainList($domains));
        $result = checkIfDomainIsAlreadyUsedViaAPI($urls, (string) $teamId);
        if (($result['hasConflicts'] ?? false) && ! $force) {
            throw new DomainAlreadyInUseException($result['conflicts'] ?? [], self::DOMAIN_CONFLICT_WARNING);
        }
    }

    /** @param array<string, mixed> $data */
    private function applyComposeDomains(Application $application, array $data, int $teamId): void
    {
        $entries = collect($data['docker_compose_domains'] ?? []);
        if ($entries->isEmpty()) {
            return;
        }
        $force = (bool) ($data['force_domain_override'] ?? false);

        $errors = [];
        $urls = $entries->flatMap(function ($item) {
            $value = data_get($item, 'domain');

            return blank($value) ? [] : str($value)->replaceStart(',', '')->replaceEnd(',', '')->trim()->explode(',')->map(fn ($u) => trim($u))->filter();
        })->each(function (string $url) use (&$errors) {
            if (! isValidDomainUrl($url)) {
                $errors[] = "Invalid URL: {$url}";

                return;
            }
            $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?? '');
            if (! in_array($scheme, ['http', 'https'], true)) {
                $errors[] = "Invalid URL scheme: {$scheme} for URL: {$url}. Only http and https are supported.";
            }
        });
        $duplicates = $urls->duplicates()->unique()->values();
        if ($duplicates->isNotEmpty() && ! $force) {
            $errors[] = 'The current request contains conflicting URLs: '.implode(', ', $duplicates->all()).' Use force_domain_override=true to proceed.';
        }
        if ($errors !== []) {
            throw new InvalidDockerComposeDomainsException($errors);
        }
        if ($urls->isNotEmpty()) {
            $result = checkIfDomainIsAlreadyUsedViaAPI($urls, (string) $teamId);
            if (isset($result['error'])) {
                throw new InvalidDockerComposeDomainsException([$result['error']]);
            }
            if ($result['hasConflicts'] && ! $force) {
                throw new DomainAlreadyInUseException($result['conflicts'], self::DOMAIN_CONFLICT_WARNING);
            }
        }

        $json = collect();
        $entries->each(function ($domain) use ($json) {
            $entry = ['domain' => data_get($domain, 'domain')];
            $redirect = data_get($domain, 'redirect');
            if (in_array($redirect, ['www', 'non-www', 'both'], true)) {
                $entry['redirect'] = $redirect;
            }
            $json->put(data_get($domain, 'name'), $entry);
        });

        [$json, $overrides] = $this->normalizeComposeDomainPorts($json);
        $application->docker_compose_domains = json_encode($json);
        $application->domain_port_overrides = $overrides;
    }

    /** @return array{0: Collection, 1: mixed} */
    private function normalizeComposeDomainPorts(Collection $domains): array
    {
        $normalized = DomainPortOverrides::normalize($domains->pluck('domain')->filter()->implode(','), null);
        $domains = $domains->map(function (array $entry): array {
            $entry['domain'] = collect(ValidationPatterns::applicationDomainList($entry['domain'] ?? null))
                ->map(fn (string $d): string => DomainPortOverrides::withoutPort($d))
                ->implode(',');

            return $entry;
        });

        return [$domains, $normalized['overrides']];
    }
}
