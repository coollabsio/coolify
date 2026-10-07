<?php

namespace App\Traits;

use App\Data\RepositoryDetectionResult;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentVariable;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Rules\ValidGitBranch;
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
    }

    /**
     * Builds an application that is not saved, only to generate the clone command for the scan.
     */
    protected function unsavedApplicationForDetection(string $repository, string $branch, GithubApp|GitlabApp|null $source = null, ?PrivateKey $privateKey = null): Application
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
                EnvironmentVariable::create([
                    'key' => $key,
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
