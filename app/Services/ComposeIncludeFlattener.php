<?php

namespace App\Services;

use App\Exceptions\DeploymentException;
use App\Models\Application;
use App\Models\Server;
use RuntimeException;
use Symfony\Component\Yaml\Yaml;

/**
 * Resolves the `include` section of a Compose file from a repository checkout into one Compose file, so the
 * parser, the validation and the deployment see every service. `docker compose config` does the merge and
 * rebases the paths of included files; Coolify only copies the files that it validated into an empty folder
 * first, so Compose cannot read anything outside the repository.
 *
 * @see https://docs.docker.com/reference/compose-file/include/
 */
class ComposeIncludeFlattener
{
    public const MAX_FILES = 20;

    private const TOO_LARGE_MARKER = '__COOLIFY_COMPOSE_TOO_LARGE__';

    /**
     * @param  string  $workDirectory  Folder on the server that contains the `checkout` folder of the repository
     */
    public function __construct(private Server $server, private string $workDirectory) {}

    public static function hasIncludes(string $compose): bool
    {
        try {
            $parsed = Yaml::parse($compose);
        } catch (\Throwable) {
            return false;
        }

        return is_array($parsed) && array_key_exists('include', $parsed);
    }

    /**
     * @param  string  $composeFile  Repository path of the main Compose file, for example `app/docker-compose.yml`
     * @param  string  $projectDirectory  Repository path of the base directory, which the deployment uses as the project directory
     *
     * @throws DeploymentException If an include is not safe to use
     * @throws RuntimeException If a server command fails
     */
    public function flatten(string $composeFile, string $projectDirectory): string
    {
        $pending = [[$composeFile, $projectDirectory]];
        $copied = [$composeFile => true];
        $envFiles = [];
        while ($pending !== []) {
            [$file, $directory] = array_shift($pending);
            $content = $this->copyFile($file);
            try {
                $compose = Yaml::parse($content);
            } catch (\Throwable) {
                $compose = null;
            }
            if (! is_array($compose)) {
                throw new DeploymentException("{$file} is not a Docker Compose file. Symbolic links are not supported.");
            }

            foreach (self::includeEntries($compose, $directory) as $entry) {
                $envFiles = [...$envFiles, ...$entry['envFiles']];
                foreach ($entry['files'] as $included) {
                    if (isset($copied[$included])) {
                        continue;
                    }
                    if (count($copied) >= self::MAX_FILES) {
                        throw new DeploymentException('A Docker Compose file can include at most '.(self::MAX_FILES - 1).' files.');
                    }
                    $copied[$included] = true;
                    $pending[] = [$included, $entry['projectDirectory']];
                }
            }
        }

        $flattened = $this->runComposeConfig($composeFile, $projectDirectory, array_unique($envFiles));
        $compose = Yaml::parse($flattened);
        if (! is_array($compose)) {
            throw new RuntimeException('docker compose config returned no Compose file.');
        }

        return Yaml::dump(self::prefixRelativeBindSources($compose), 10, 2);
    }

    /**
     * The files of each entry in the `include` section, as repository paths. Main-file includes resolve against
     * the project directory; the includes of an included file resolve against its own project directory.
     *
     * @param  array<mixed>  $compose
     * @return list<array{files: list<string>, projectDirectory: string, envFiles: list<string>}>
     *
     * @throws DeploymentException If an include is not a relative path inside the repository
     */
    public static function includeEntries(array $compose, string $projectDirectory): array
    {
        if (! array_key_exists('include', $compose)) {
            return [];
        }
        if (! is_array($compose['include']) || ! array_is_list($compose['include'])) {
            throw new DeploymentException('The include section must be a list.');
        }

        $entries = [];
        foreach ($compose['include'] as $include) {
            $include = is_string($include) ? ['path' => $include] : $include;
            if (! is_array($include) || ! isset($include['path'])) {
                throw new DeploymentException('Each include entry needs a path.');
            }

            $files = array_map(fn ($path) => self::repositoryPath($path, $projectDirectory, 'include path'), (array) $include['path']);
            $entries[] = [
                'files' => $files,
                'projectDirectory' => isset($include['project_directory'])
                    ? self::repositoryPath($include['project_directory'], $projectDirectory, 'include project_directory')
                    : self::directoryOf($files[0]),
                'envFiles' => array_map(fn ($path) => self::repositoryPath($path, $projectDirectory, 'include env_file'), (array) ($include['env_file'] ?? [])),
            ];
        }

        return $entries;
    }

    /**
     * Docker Compose prints the rebased bind sources of included files without `./`. Coolify only treats a
     * source that starts with `.`, `/` or `~` as a host path.
     *
     * @param  array<mixed>  $compose
     * @return array<mixed>
     */
    public static function prefixRelativeBindSources(array $compose): array
    {
        foreach ($compose['services'] ?? [] as $name => $service) {
            foreach ($service['volumes'] ?? [] as $index => $volume) {
                $source = is_array($volume) && ($volume['type'] ?? null) === 'bind' ? ($volume['source'] ?? null) : null;
                if (is_string($source) && $source !== '' && ! str_starts_with($source, '.') && ! str_starts_with($source, '/') && ! str_starts_with($source, '~') && ! str_starts_with($source, '$')) {
                    $compose['services'][$name]['volumes'][$index]['source'] = "./{$source}";
                }
            }
        }

        return $compose;
    }

    /**
     * @throws DeploymentException
     */
    private static function repositoryPath(mixed $path, string $relativeTo, string $label): string
    {
        if (! is_string($path) || trim($path) === '') {
            throw new DeploymentException("Each {$label} must be a non-empty string.");
        }
        if (str_contains($path, '://') || preg_match('/^[\w.-]+@[\w.-]+:/', $path) === 1) {
            throw new DeploymentException("Remote includes are not supported ({$path}). Add the file to the repository instead.");
        }
        if (str_contains($path, '$')) {
            throw new DeploymentException("The {$label} {$path} cannot use variables.");
        }
        if (preg_match('/[\x00-\x1f\\\\]/', $path) === 1) {
            throw new DeploymentException("The {$label} {$path} contains characters that are not allowed.");
        }
        if (str_starts_with($path, '/') || str_starts_with($path, '~')) {
            throw new DeploymentException("The {$label} {$path} must be a relative path inside the repository.");
        }

        $segments = [];
        foreach (explode('/', "{$relativeTo}/{$path}") as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                if ($segments === []) {
                    throw new DeploymentException("The {$label} {$path} must stay inside the repository.");
                }
                array_pop($segments);

                continue;
            }
            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private static function directoryOf(string $file): string
    {
        $directory = dirname($file);

        return $directory === '.' ? '' : $directory;
    }

    private function checkoutDirectory(): string
    {
        return "{$this->workDirectory}/checkout";
    }

    private function flatDirectory(): string
    {
        return "{$this->workDirectory}/flat";
    }

    /**
     * Writes the file from the Git object store as a regular file into the flat folder and returns its content.
     * `git show` prints the target of a symbolic link instead of following it.
     */
    private function copyFile(string $file): string
    {
        $target = escapeshellarg("{$this->flatDirectory()}/{$file}");
        $limit = Application::MAX_DOCKER_COMPOSE_SIZE_BYTES;
        $script = 'cd '.escapeshellarg($this->checkoutDirectory())
            .' && mkdir -p '.escapeshellarg(dirname("{$this->flatDirectory()}/{$file}"))
            .' && git show '.escapeshellarg("HEAD:{$file}")." > {$target}"
            ." && if [ \"$(wc -c < {$target})\" -gt {$limit} ]; then echo ".self::TOO_LARGE_MARKER."; else cat {$target}; fi";

        // One sh -c line, so the non-root sudo parser runs the redirects as root too.
        $content = (string) instant_remote_process(['sh -c '.escapeshellarg($script)], $this->server);
        if ($content === self::TOO_LARGE_MARKER) {
            throw new DeploymentException("{$file} exceeds the 5 MiB size limit.");
        }

        return $content;
    }

    /**
     * Compose stops when an `env_file` of an include does not exist. Coolify does not interpolate the Compose
     * file, so empty files are enough.
     *
     * @param  array<int, string>  $envFiles
     */
    private function runComposeConfig(string $composeFile, string $projectDirectory, array $envFiles): string
    {
        $script = collect($envFiles)
            ->map(function (string $envFile) {
                $path = escapeshellarg("{$this->flatDirectory()}/{$envFile}");

                return 'mkdir -p '.escapeshellarg(dirname("{$this->flatDirectory()}/{$envFile}"))." && touch {$path}";
            })
            ->push('cd '.escapeshellarg($this->flatDirectory())
                .' && COMPOSE_EXPERIMENTAL_GIT_REMOTE=0 COMPOSE_EXPERIMENTAL_OCI_REMOTE=0 docker compose'
                .' --project-directory '.escapeshellarg("./{$projectDirectory}")
                .' -f '.escapeshellarg("./{$composeFile}")
                .' config --no-interpolate --no-path-resolution --no-normalize')
            ->implode(' && ');

        $flattened = (string) instant_remote_process(['sh -c '.escapeshellarg($script)], $this->server);
        if (strlen($flattened) > Application::MAX_DOCKER_COMPOSE_SIZE_BYTES) {
            throw new DeploymentException('The Docker Compose file with its included files exceeds the 5 MiB size limit.');
        }

        return $flattened;
    }
}
