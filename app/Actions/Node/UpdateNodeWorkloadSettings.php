<?php

namespace App\Actions\Node;

use App\Actions\Node\Build\CreateClusterGitWorkload;
use App\Actions\Node\Build\CreateClusterInlineDockerfileWorkload;
use App\Enums\NodeWorkloadSourceType;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use App\Models\RegistryCredential;
use App\Models\User;
use App\Rules\DockerImageFormat;
use App\Rules\ValidGitBranch;
use App\Services\DockerRegistryLogins;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\MessageBag;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the settings of a cluster application or database, for the General page and the API
 * (decision 0029). Each method validates its input and throws a ValidationException with the
 * snake_case field names. The caller authorizes `update` on the workload. A registry credential
 * that the workload does not use yet also needs `useTeamCredentials`.
 */
class UpdateNodeWorkloadSettings
{
    use AsAction;

    private const PATH_RULES = ['nullable', 'string', 'max:512', 'regex:/^\/?[A-Za-z0-9._\/-]*$/', 'not_regex:/(^|\/)\.\.(\/|$)/'];

    /** The internal DNS name is allocated once, so renaming keeps the internal hostname stable. */
    public function details(NodeWorkload $workload, string $name, ?string $description): NodeWorkload
    {
        $validated = Validator::make(['name' => $name, 'description' => $description], [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
        ], ValidationPatterns::combinedMessages())->validate();
        $workload->update([
            'name' => trim($validated['name']),
            'description' => filled($validated['description']) ? trim($validated['description']) : null,
        ]);

        return $workload;
    }

    /** The image, the start command, and the registry credential of an application from a Docker image. */
    public function image(NodeWorkload $workload, string $imageName, ?string $imageTag, ?string $startCommand, ?string $registryCredentialUuid, User $user): NodeWorkload
    {
        Validator::make(['image_name' => $imageName, 'image_tag' => $imageTag, 'start_command' => $startCommand], [
            'image_name' => ValidationPatterns::dockerImageNameRules(required: true),
            'image_tag' => ValidationPatterns::dockerImageTagRules(),
            'start_command' => ['nullable', 'string', 'max:16384'],
        ])->validate();
        $errors = new MessageBag;
        $command = self::parseStartCommand((string) $startCommand, $errors);
        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }
        $tag = filled($imageTag) ? trim($imageTag) : 'latest';
        $image = CreateClusterDockerImageWorkload::qualifiedImage(
            trim($imageName).(preg_match('/^[a-f0-9]{64}$/i', $tag) === 1 ? '@sha256:' : ':').$tag,
        );
        $credential = self::registryCredential($workload, (string) $registryCredentialUuid, $image, $user, $errors);
        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }
        $workload->update(['registry_credential_id' => $credential?->id]);
        UpdateNodeWorkloadConfiguration::run($workload, $command, null, $image);

        return $workload;
    }

    /**
     * The source of an application that Coolify builds: the Git settings, or the Dockerfile of an
     * application without Git, and the registry that the build pushes to.
     *
     * @param  array{git_branch?: ?string, build_pack?: ?string, dockerfile?: ?string, base_directory?: ?string, dockerfile_location?: ?string, publish_directory?: ?string, start_command?: ?string, docker_registry_image_name?: ?string, docker_registry_image_tag?: ?string, registry_credential_uuid?: ?string}  $input
     */
    public function source(NodeWorkload $workload, array $input, User $user): NodeWorkload
    {
        $input['git_branch'] = trim((string) ($input['git_branch'] ?? ''));
        $usesGit = $workload->buildsFromGit();
        Validator::make($input, [
            ...($usesGit ? [
                'git_branch' => ['required', 'string', 'max:255', new ValidGitBranch],
                'build_pack' => ['required', Rule::enum(NodeWorkloadSourceType::class)->only(NodeWorkloadSourceType::gitBuildPacks())],
            ] : [
                'dockerfile' => CreateClusterInlineDockerfileWorkload::dockerfileRules(),
            ]),
            'base_directory' => self::PATH_RULES,
            'dockerfile_location' => self::PATH_RULES,
            'publish_directory' => self::PATH_RULES,
            'start_command' => ['nullable', 'string', 'max:16384'],
            'docker_registry_image_name' => [...ValidationPatterns::dockerImageNameRules(), 'not_regex:/@/', 'not_regex:/^(localhost|'.preg_quote(NodeRegistry::HOST, '/').')\//'],
            'docker_registry_image_tag' => ['nullable', 'string', 'max:128', 'regex:'.ValidationPatterns::DOCKER_IMAGE_LITERAL_TAG_PATTERN],
        ], [
            'docker_registry_image_name.regex' => 'Enter an image repository without a tag, for example ghcr.io/acme/api.',
            'docker_registry_image_name.not_regex' => 'Enter an image repository outside the cluster registry, without a digest.',
        ])->validate();
        $errors = new MessageBag;
        $command = self::parseStartCommand((string) ($input['start_command'] ?? ''), $errors);
        $imageName = trim((string) ($input['docker_registry_image_name'] ?? ''));
        $credentialUuid = (string) ($input['registry_credential_uuid'] ?? '');
        $credential = null;
        if ($imageName === '' && $credentialUuid !== '') {
            $errors->add('registry_credential_uuid', 'Set the Docker image name to push with a registry credential.');
        } elseif ($imageName !== '') {
            $credential = self::registryCredential($workload, $credentialUuid, $imageName, $user, $errors);
        }
        if ($errors->isNotEmpty()) {
            throw ValidationException::withMessages($errors->toArray());
        }
        $workload->update([
            'registry_credential_id' => $credential?->id,
            'docker_registry_image_name' => $imageName === '' ? null : $imageName,
            'docker_registry_image_tag' => filled($input['docker_registry_image_tag'] ?? null) ? trim($input['docker_registry_image_tag']) : null,
            ...($usesGit ? [
                'git_branch' => $input['git_branch'],
                'source_type' => NodeWorkloadSourceType::from($input['build_pack']),
                'base_directory' => filled($input['base_directory'] ?? null) ? trim($input['base_directory'], ' /') : null,
                'dockerfile_location' => filled($input['dockerfile_location'] ?? null) ? ltrim(trim($input['dockerfile_location']), '/') : null,
                'publish_directory' => CreateClusterGitWorkload::publishDirectory($input['publish_directory'] ?? null),
            ] : ['dockerfile' => $input['dockerfile']]),
        ]);
        $revision = $workload->revisions()->latest('id')->first();
        if ($revision !== null && $command !== ($revision->configuration['command'] ?? [])) {
            UpdateNodeWorkloadConfiguration::run($workload, $command, null, $revision->image);
        } elseif ($revision === null && $command !== []) {
            throw ValidationException::withMessages(['start_command' => 'Deploy the application once before you set a start command.']);
        }

        return $workload;
    }

    /** The webhook deployment settings, like the v4 Advanced > Deployment settings. */
    public function deploymentSettings(NodeWorkload $workload, bool $autoDeploy, bool $autoCancelQueued, bool $autoCancelBuilding): NodeWorkload
    {
        $workload->update([
            'is_auto_deploy_enabled' => $autoDeploy,
            // Stopping a running build also cancels the waiting ones, as in v4.
            'is_auto_cancel_queued_deployments_enabled' => $autoCancelQueued || $autoCancelBuilding,
            'is_auto_cancel_building_deployments_enabled' => $autoCancelBuilding,
        ]);

        return $workload;
    }

    /**
     * The General page of a database, like the v4 database General page. Credentials are
     * environment variables, so the container gets new values at the next deployment. A locked
     * credential (shown once) cannot change here.
     *
     * @param  array<string, ?string>  $credentials  The credentials to change, keyed by variable name.
     */
    public function database(NodeWorkload $workload, string $name, ?string $description, string $image, array $credentials): NodeWorkload
    {
        $type = $workload->database_type;
        abort_if($type === null, 404);
        $keys = array_column($type->credentials(), 'key');
        $locked = $workload->environment_variables()->whereIn('key', $keys)->where('is_shown_once', true)->pluck('key')->all();
        $unknown = array_diff(array_keys($credentials), $keys);
        if ($unknown !== []) {
            throw ValidationException::withMessages(['credentials' => 'Unknown credential: '.implode(', ', $unknown).'. Use '.implode(', ', $keys).'.']);
        }
        $lockedChanges = array_intersect(array_keys($credentials), $locked);
        if ($lockedChanges !== []) {
            throw ValidationException::withMessages(['credentials' => 'A locked credential cannot change: '.implode(', ', $lockedChanges).'.']);
        }
        $credentialRules = collect($credentials)
            ->mapWithKeys(fn ($value, string $key): array => ["credentials.{$key}" => ['required', 'string', 'max:255', 'not_regex:/\x00/']])
            ->all();
        $validated = Validator::make(['name' => $name, 'description' => $description, 'image' => $image, 'credentials' => $credentials], [
            'name' => ValidationPatterns::nameRules(),
            'description' => ValidationPatterns::descriptionRules(),
            'image' => ['required', 'string', 'max:255', new DockerImageFormat],
            ...$credentialRules,
        ], ValidationPatterns::combinedMessages(), ['image' => 'database image'])->validate();

        $workload->update([
            'name' => trim($validated['name']),
            'description' => filled($validated['description']) ? trim($validated['description']) : null,
        ]);
        foreach ($type->credentials() as $credential) {
            if (array_key_exists($credential['key'], $credentials)) {
                CreateClusterDatabaseWorkload::storeCredential($workload, $credential, (string) $credentials[$credential['key']]);
            }
        }
        $revision = $workload->revisions()->latest('id')->first();
        $qualified = CreateClusterDockerImageWorkload::qualifiedImage(trim($validated['image']));
        if ($revision !== null && $qualified !== $revision->image) {
            UpdateNodeWorkloadConfiguration::run($workload, $revision->configuration['command'] ?? [], null, $qualified);
        }

        return $workload;
    }

    /**
     * The arguments of a start command: words split at spaces, with "" quotes like a shell.
     *
     * @return list<string>
     */
    public static function parseStartCommand(string $startCommand, MessageBag $errors): array
    {
        $command = array_values(array_filter(
            str_getcsv(trim($startCommand), ' ', '"', ''),
            fn (?string $argument): bool => $argument !== null && $argument !== '',
        ));
        if (count($command) > 64 || collect($command)->contains(fn (string $argument): bool => mb_strlen($argument) > 4096)) {
            $errors->add('start_command', 'Use at most 64 arguments with at most 4096 characters each.');

            return [];
        }

        return $command;
    }

    /**
     * The selected registry credential, which must belong to the team of the workload and be for
     * the registry of the image. Adds an error to `registry_credential_uuid` otherwise.
     */
    public static function registryCredential(NodeWorkload $workload, string $uuid, string $image, User $user, MessageBag $errors): ?RegistryCredential
    {
        if ($uuid === '') {
            return null;
        }
        $credential = RegistryCredential::query()
            ->where('team_id', $workload->team_id)
            ->where('uuid', $uuid)
            ->first();
        if ($credential === null) {
            $errors->add('registry_credential_uuid', 'Select a registry credential of this team.');

            return null;
        }
        if (! $credential->matchesImage($image)) {
            $errors->add('registry_credential_uuid', "This credential is for {$credential->registry}, but the image is in ".DockerRegistryLogins::registryFromImage($image).'.');

            return null;
        }
        if ($credential->id !== $workload->registry_credential_id) {
            Gate::forUser($user)->authorize('useTeamCredentials', $workload);
        }

        return $credential;
    }
}
