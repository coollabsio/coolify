<?php

namespace App\Traits;

use App\Data\RepositoryDetectionResult;
use App\Jobs\PlanEnvironmentConfigJob;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Rules\ValidGitBranch;
use App\Services\ProjectConfig\ConfigLinkPreview;
use App\Services\ProjectConfig\ConfigLinks;
use App\Services\RepositoryDetector;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Livewire\Attributes\Locked;

/**
 * @property string $build_pack
 * @property int $port
 * @property string|null $docker_compose_location
 *
 * @method void updatedBuildPack()
 */
trait HasRepositoryDetection
{
    public bool $detectionRan = false;

    public bool $detectionFailed = false;

    /**
     * Build pack that the scan selected from a file in the base directory.
     */
    #[Locked]
    public ?string $suggestedBuildPack = null;

    #[Locked]
    public array $detectedDockerfiles = [];

    #[Locked]
    public array $detectedDockerComposeFiles = [];

    public ?string $selectedDockerfile = null;

    public ?string $selectedDockerComposeFile = null;

    public ?int $detectedPort = null;

    #[Locked]
    public array $dockerfilePorts = [];

    #[Locked]
    public array $detectedEnvFiles = [];

    public ?string $selectedEnvFile = null;

    #[Locked]
    public array $parsedEnvFiles = [];

    public array $envExampleVars = [];

    public bool $envImported = false;

    /**
     * Path of a coolify.json file that the scan found, relative to the scanned directory.
     */
    #[Locked]
    public ?string $detectedConfigFile = null;

    #[Locked]
    public ?array $configPlan = null;

    #[Locked]
    public ?string $configPlanError = null;

    /**
     * How to create the resources when the scan found coolify.json: 'file' or 'manual'.
     */
    public string $configSource = 'file';

    /**
     * Unsaved application with the repository, branch, and Git source or deploy key to scan.
     */
    abstract protected function applicationForDetection(): Application;

    public function detectRepository(): void
    {
        $this->authorize('create', Application::class);
        try {
            $application = $this->applicationForDetection();
        } catch (\Throwable $e) {
            handleError($e, $this);

            return;
        }
        Validator::make([
            'branch' => $application->git_branch,
            'base_directory' => $this->base_directory ?? '/',
        ], [
            'branch' => ['required', new ValidGitBranch],
            'base_directory' => ['required', 'string', 'regex:~^/?(?:[a-zA-Z0-9_.-]+/)*[a-zA-Z0-9_.-]*$~', 'not_regex:~(^|/)\.\.?(/|$)~'],
        ])->validate();

        $destination = find_resource_destination_for_current_team(data_get($this->query, 'destination'));
        if (! $destination) {
            $this->dispatch('error', 'Destination not found.');

            return;
        }
        $this->authorize('update', $destination->server);

        $this->detectionRan = false;
        $this->detectionFailed = false;
        $this->applyDetectionResult(RepositoryDetectionResult::none());
        try {
            $detector = new RepositoryDetector($application, $this->base_directory ?? '/', $destination->server);
            $this->applyDetectionResult($detector->detect());
        } catch (\Throwable $e) {
            // The exception can contain the clone command with a Git token, so do not log the message.
            Log::debug('Repository detection failed', ['exception' => $e::class]);
            $this->detectionFailed = true;
            $this->dispatch('error', 'Smart Scan could not read the repository. Check the repository access and the branch.');
        }
        $this->detectionRan = true;
        if ($this->detectedConfigFile !== null) {
            $this->configSource = 'file';
            $this->previewConfigFile();
        }
    }

    public function usesConfigFile(): bool
    {
        return $this->detectedConfigFile !== null && $this->configSource === 'file';
    }

    public function canLinkConfigFile(): bool
    {
        try {
            return auth()->user()->isAdminOfTeam((int) $this->detectionEnvironment()->project->team_id);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Reads the found coolify.json and shows its plan for this environment. Nothing is saved.
     */
    public function previewConfigFile(): void
    {
        $this->authorize('create', Application::class);
        $this->configPlan = null;
        $this->configPlanError = null;
        try {
            $data = $this->configLinkData();
            $environment = $this->detectionEnvironment();
            $destination = find_resource_destination_for_current_team(data_get($this->query, 'destination'))
                ?? throw new \RuntimeException('Destination not found.');
            $link = new EnvironmentConfigLink([
                'environment_id' => $environment->id,
                'mode' => EnvironmentConfigLink::MODE_GIT,
                'repository' => $data['repository'],
                'branch' => $data['branch'],
                'file_path' => $data['file_path'],
            ]);
            $application = $this->applicationForDetection();
            $link->forceFill(['private_key_id' => $application->private_key_id]);
            $source = $application->getRelation('source');
            if ($source !== null && ! ($source instanceof GithubApp && (int) $source->id === 0)) {
                $link->forceFill(['source_type' => $source::class, 'source_id' => $source->id]);
                $link->setRelation('source', $source);
            }
            $link->setRelation('environment', $environment);
            $link->setRelation('readServer', $destination->server);

            ['plan' => $plan, 'error' => $this->configPlanError] = app(ConfigLinkPreview::class)->plan($link, auth()->user());
            $this->configPlan = $plan === null ? null
                : $plan + ['link' => ['repository' => $data['repository'], 'branch' => $data['branch'], 'file_path' => $data['file_path']]];
        } catch (\Throwable $e) {
            $this->configPlanError = $e instanceof \RuntimeException ? $e->getMessage() : 'Coolify could not read coolify.json.';
        }
    }

    /**
     * Links this environment to coolify.json on the branch. Only a team admin can do this.
     */
    public function useConfigFile(): void
    {
        try {
            $environment = $this->detectionEnvironment();
            $teamId = (int) $environment->project->team_id;
            abort_unless(auth()->user()->isAdminOfTeam($teamId), 403, 'Only a team admin can link coolify.json.');
            $destination = find_resource_destination_for_current_team(data_get($this->query, 'destination'))
                ?? throw new \RuntimeException('Destination not found.');

            $link = app(ConfigLinks::class)->create(auth()->user(), $teamId, $this->configLinkData() + [
                'project' => $environment->project->name,
                'environment' => $environment->name,
                'mode' => EnvironmentConfigLink::MODE_GIT,
                'read_server_uuid' => $destination->server->uuid,
            ]);
            PlanEnvironmentConfigJob::dispatch($link, 'link');

            $this->redirectRoute('project.environment.configuration', [
                'project_uuid' => $environment->project->uuid,
                'environment_uuid' => $environment->uuid,
            ]);
        } catch (\Throwable $e) {
            handleError($e, $this);
        }
    }

    /**
     * Repository, branch, and git source of the form, in the shape of a link request.
     *
     * @return array{repository: string, branch: string, file_path: string, source_type: string, source_uuid: ?string}
     */
    private function configLinkData(): array
    {
        $application = $this->applicationForDetection();
        $source = $application->getRelation('source');
        [$type, $uuid] = match (true) {
            $application->private_key_id !== null => ['deploy_key', PrivateKey::query()->find($application->private_key_id)?->uuid],
            $source instanceof GitlabApp => throw new \RuntimeException('GitLab app sources cannot be linked to coolify.json yet. Use a deploy key.'),
            $source instanceof GiteaApp => ['gitea_app', $source->uuid],
            $source instanceof GithubApp && (int) $source->id !== 0 => ['github_app', $source->uuid],
            default => ['public', null],
        };
        $directory = trim((string) ($this->base_directory ?? '/'), '/');

        return [
            'repository' => (string) $application->git_repository,
            'branch' => (string) $application->git_branch,
            'file_path' => ltrim(($directory === '' ? '' : $directory.'/').($this->detectedConfigFile ?? 'coolify.json'), '/'),
            'source_type' => $type,
            'source_uuid' => $uuid,
        ];
    }

    private function detectionEnvironment(): Environment
    {
        $project = Project::ownedByCurrentTeam()->where('uuid', data_get($this->parameters, 'project_uuid'))->firstOrFail();

        return $project->environments()->where('uuid', data_get($this->parameters, 'environment_uuid'))->firstOrFail();
    }

    /**
     * Builds an application that is not saved, only to generate the clone command for the scan.
     */
    protected function unsavedApplicationForDetection(string $repository, string $branch, GithubApp|GitlabApp|GiteaApp|null $source = null, ?PrivateKey $privateKey = null): Application
    {
        $application = (new Application)->forceFill([
            'git_repository' => $repository,
            'git_branch' => $branch,
            'git_commit_sha' => 'HEAD',
            'private_key_id' => $privateKey?->id,
        ]);
        $application->setRelation('settings', (new ApplicationSetting)->forceFill([
            'is_git_shallow_clone_enabled' => true,
            'is_git_submodules_enabled' => false,
        ]));
        $application->setRelation('source', $source);
        $application->setRelation('private_key', $privateKey);

        return $application;
    }

    protected function importDetectedEnvironmentVariables(Application $application): void
    {
        if (! $this->envImported || empty($this->envExampleVars)) {
            return;
        }
        Validator::make(['variables' => $this->envExampleVars], [
            'variables' => ['array'],
            'variables.*' => ['nullable', 'string'],
        ])->validate();
        $application->getConnection()->transaction(function () use ($application): void {
            foreach ($this->envExampleVars as $key => $value) {
                if (! preg_match('/^[A-Z_][A-Z0-9_]*$/i', (string) $key)) {
                    continue;
                }
                // The created hook of the application can already have added a default for this key.
                $application->environment_variables()->updateOrCreate(['key' => $key], [
                    'value' => $value,
                    'resourceable_type' => $application->getMorphClass(),
                    'resourceable_id' => $application->id,
                    'is_preview' => false,
                ]);
            }
        });
    }

    protected function applyDetectionResult(RepositoryDetectionResult $result): void
    {
        $this->detectedConfigFile = $result->configFile;
        $this->configPlan = null;
        $this->configPlanError = null;
        $this->detectedDockerfiles = $result->dockerfiles;
        $this->detectedDockerComposeFiles = $result->dockerComposeFiles;
        $this->dockerfilePorts = $result->dockerfilePorts;

        $this->selectedDockerfile = null;
        $this->selectedDockerComposeFile = null;
        $this->detectedPort = null;
        $this->detectedEnvFiles = [];
        $this->parsedEnvFiles = [];
        $this->selectedEnvFile = null;
        $this->envExampleVars = [];
        $this->envImported = false;

        $this->suggestedBuildPack = $result->getSuggestedBuildPack()?->value;
        if ($this->suggestedBuildPack && $this->suggestedBuildPack !== $this->build_pack) {
            $this->build_pack = $this->suggestedBuildPack;
            $this->updatedBuildPack();
        }

        if ($result->hasDockerfile()) {
            $this->selectedDockerfile = $result->dockerfiles[0];
            $port = $result->dockerfilePorts[$this->selectedDockerfile] ?? null;
            $this->port = $port ?? 3000;
            $this->detectedPort = $port;
        }

        if ($result->hasDockerCompose()) {
            $this->selectedDockerComposeFile = $result->dockerComposeFiles[0];
            $this->docker_compose_location = '/'.$this->selectedDockerComposeFile;
        }

        if ($result->hasEnvFiles()) {
            $this->detectedEnvFiles = array_keys($result->envFiles);
            $this->parsedEnvFiles = [];
            foreach ($result->envFiles as $filename => $content) {
                $parsed = $content !== null ? parseEnvFormatToArray($content) : [];
                // parseEnvFormatToArray returns ['KEY' => ['value' => ..., 'comment' => ...]].
                // Flatten to a plain ['KEY' => 'value'] map for the import form.
                $this->parsedEnvFiles[$filename] = collect($parsed)
                    ->map(fn ($item) => is_array($item) ? (string) ($item['value'] ?? '') : (string) $item)
                    ->all();
            }
            $this->selectedEnvFile = $this->detectedEnvFiles[0];
            $this->envExampleVars = $this->parsedEnvFiles[$this->selectedEnvFile] ?? [];
        }
    }

    /**
     * Repository path without a leading slash, so a typed path matches the detected paths.
     */
    protected function normalizeRepositoryFilePath(?string $path): ?string
    {
        $path = ltrim(trim((string) $path), '/');

        return $path === '' ? null : $path;
    }

    /**
     * Dockerfile location for the new application: a detected file or a path the user typed.
     */
    protected function selectedDockerfileLocation(): ?string
    {
        $path = $this->normalizeRepositoryFilePath($this->selectedDockerfile);
        if ($path === null) {
            return null;
        }
        $location = '/'.$path;
        Validator::make(
            ['selectedDockerfile' => $location],
            ['selectedDockerfile' => ValidationPatterns::filePathRules()],
            ValidationPatterns::filePathMessages('selectedDockerfile', 'Dockerfile'),
        )->validate();

        return $location;
    }

    public function updatedSelectedDockerfile(): void
    {
        $this->selectedDockerfile = $this->normalizeRepositoryFilePath($this->selectedDockerfile);
        $this->resetErrorBag('selectedDockerfile');
        $this->selectedDockerfileLocation();

        $port = $this->dockerfilePorts[$this->selectedDockerfile ?? ''] ?? null;
        $this->port = $port ?? 3000;
        $this->detectedPort = $port;
    }

    public function updatedSelectedDockerComposeFile(): void
    {
        $this->selectedDockerComposeFile = $this->normalizeRepositoryFilePath($this->selectedDockerComposeFile);

        if ($this->selectedDockerComposeFile) {
            $this->docker_compose_location = '/'.$this->selectedDockerComposeFile;
        }
    }

    public function updatedSelectedEnvFile(): void
    {
        if ($this->selectedEnvFile && isset($this->parsedEnvFiles[$this->selectedEnvFile])) {
            $this->envExampleVars = $this->parsedEnvFiles[$this->selectedEnvFile];
            $this->envImported = false;
        }
    }

    public function confirmEnvImport(): void
    {
        $this->envImported = true;
    }

    public function clearEnvVars(): void
    {
        $this->envImported = false;
        $this->envExampleVars = $this->parsedEnvFiles[$this->selectedEnvFile] ?? [];
    }
}
