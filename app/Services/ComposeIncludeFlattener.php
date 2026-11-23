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
 * rebases the paths of included files; Coolify only copies the included and extended (`extends.file`) files that
 * it validated into an empty folder first, so Compose cannot read anything outside the repository. Compose reads
 * no other file while it loads the model: `env_file`, `label_file` and the `file` of secrets and configs stay as
 * paths in the output.
 *
 * @see https://docs.docker.com/reference/compose-file/include/
 */
class ComposeIncludeFlattener
{
    public const MAX_FILES = 20;

    /**
     * Older versions read the `env_file` of each service and ignore `--no-path-resolution` and `--no-normalize`,
     * so the flattened file loses its `env_file` references and gets absolute paths of the flat folder.
     */
    public const MIN_COMPOSE_VERSION = '2.25.0';

    private const TOO_LARGE_MARKER = '__COOLIFY_COMPOSE_TOO_LARGE__';

    private const SYMLINK_MARKER = '__COOLIFY_COMPOSE_SYMLINK__';

    /**
     * @param  string  $checkoutDirectory  Git checkout of the repository
     * @param  string  $flatDirectory  Empty folder for the validated files, outside the checkout
     * @param  string|null  $container  Container on the server that has the checkout, for example the deployment helper
     */
    public function __construct(
        private Server $server,
        private string $checkoutDirectory,
        private string $flatDirectory,
        private ?string $container = null,
    ) {}

    public static function hasIncludes(string $compose): bool
    {
        try {
            $parsed = Yaml::parse($compose, Yaml::PARSE_CUSTOM_TAGS);
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
        $this->ensureComposeVersion();
        // Compose resolves the includes of a file against the project directory of each entry that reaches it.
        $pending = [[$composeFile, $projectDirectory]];
        $queued = ["{$composeFile}|{$projectDirectory}" => true];
        $files = [$composeFile => true];
        $contents = [];
        $envFiles = [];
        $queue = function (string $file, string $directory) use (&$queued, &$files, &$pending) {
            $key = "{$file}|{$directory}";
            if (isset($queued[$key])) {
                return;
            }
            if (! isset($files[$file]) && count($files) >= self::MAX_FILES) {
                throw new DeploymentException('A Docker Compose file can include and extend at most '.(self::MAX_FILES - 1).' files.');
            }
            $files[$file] = true;
            $queued[$key] = true;
            $pending[] = [$file, $directory];
        };
        while ($pending !== []) {
            [$file, $directory] = array_shift($pending);
            $content = $contents[$file] ??= $this->copyFile($file);
            // Compose merge tags such as `!reset` and `!override` stay as tagged values; Compose applies them.
            try {
                $compose = Yaml::parse($content, Yaml::PARSE_CUSTOM_TAGS);
            } catch (\Throwable) {
                $compose = null;
            }
            if (! is_array($compose)) {
                throw new DeploymentException("{$file} is not a Docker Compose file.");
            }

            foreach (self::includeEntries($compose, $directory) as $entry) {
                $envFiles = [...$envFiles, ...$entry['envFiles']];
                foreach ($entry['files'] as $included) {
                    $queue($included, $entry['projectDirectory']);
                }
            }
            foreach (self::extendsFiles($compose, $directory) as $extended) {
                $queue($extended, self::directoryOf($extended));
            }
        }

        $flattened = $this->runComposeConfig($composeFile, $projectDirectory, array_unique($envFiles));
        $compose = Yaml::parse($flattened);
        if (! is_array($compose)) {
            throw new RuntimeException('docker compose config returned no Compose file.');
        }

        $flattened = Yaml::dump(self::prefixRelativeBindSources($compose), 10, 2);
        if (strlen($flattened) > Application::MAX_DOCKER_COMPOSE_SIZE_BYTES) {
            throw new DeploymentException('The Docker Compose file with its included files exceeds the 5 MiB size limit.');
        }

        return $flattened;
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
            $paths = is_string($include['path']) ? [$include['path']] : $include['path'];
            if (! is_array($paths) || $paths === [] || ! array_is_list($paths)) {
                throw new DeploymentException('Each include path must be a string or a non-empty list of strings.');
            }

            $files = array_map(fn ($path) => self::repositoryPath($path, $projectDirectory, 'include path'), $paths);
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
     * The `extends.file` references of the services, as repository paths. Docker Compose reads these files while
     * it loads the model, so Coolify validates and copies them like included files. A path resolves against the
     * project directory of the file; the references of an extended file resolve against its own folder.
     *
     * @param  array<mixed>  $compose
     * @return list<string>
     *
     * @throws DeploymentException If a file is not a relative path inside the repository
     */
    public static function extendsFiles(array $compose, string $projectDirectory): array
    {
        $files = [];
        foreach (is_array($compose['services'] ?? null) ? $compose['services'] : [] as $service) {
            $file = is_array($service) && is_array($service['extends'] ?? null) ? ($service['extends']['file'] ?? null) : null;
            if ($file !== null) {
                $files[] = self::repositoryPath($file, $projectDirectory, 'extends file');
            }
        }

        return array_values(array_unique($files));
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

    /**
     * Runs the script as one `sh -c` line, so the non-root sudo parser runs the redirects as root too.
     */
    private function run(string $script): string
    {
        $command = 'sh -c '.escapeshellarg($script);
        if ($this->container !== null) {
            $command = executeInDocker($this->container, $command);
        }

        return (string) instant_remote_process([$command], $this->server);
    }

    /**
     * Shell that goes into the submodule that contains `$file` (relative to the current Git repository), and
     * strips the submodule path from `$file`, so the next Git command reads the commit that the gitlink selects.
     * It fails when the submodule is not checked out, so the read cannot fall back to the superproject.
     */
    public static function enterSubmoduleScript(): string
    {
        return implode(' ', [
            'while sub=$(git ls-tree -r HEAD | while read -r mode type object path; do',
            'if [ "$mode" = 160000 ]; then case "$file" in "$path"/*) echo "$path"; break ;; esac; fi; done) && [ -n "$sub" ];',
            'do { cd "$sub" && [ -e .git ]; } || { echo "The Git submodule $sub is not checked out." >&2; exit 1; }; file="${file#"$sub"/}"; done;',
        ]);
    }

    /**
     * @throws DeploymentException If the Docker Compose version of the server is too old
     */
    protected function ensureComposeVersion(): void
    {
        $command = $this->container === null ? 'docker compose version --short' : executeInDocker($this->container, 'docker compose version --short');
        $version = ltrim(trim((string) instant_remote_process([$command], $this->server, false)), 'v');
        if (preg_match('/^\d+\.\d+\.\d+/', $version) !== 1 || version_compare($version, self::MIN_COMPOSE_VERSION, '<')) {
            $found = $version === '' ? 'no Docker Compose' : "Docker Compose {$version}";
            throw new DeploymentException('The include section needs Docker Compose '.self::MIN_COMPOSE_VERSION." or later on the server. The server has {$found}.");
        }
    }

    /**
     * Writes the file from the Git object store as a regular file into the flat folder and returns its content.
     * `git show` prints the target of a symbolic link instead of following it.
     */
    protected function copyFile(string $file): string
    {
        $content = $this->run(self::copyFileScript($this->checkoutDirectory, $this->flatDirectory, $file));
        if ($content === self::TOO_LARGE_MARKER) {
            throw new DeploymentException("{$file} exceeds the 5 MiB size limit.");
        }

        return $content;
    }

    /**
     * A file in a Git submodule is read from the submodule, at the commit that the superproject selects.
     */
    public static function copyFileScript(string $checkoutDirectory, string $flatDirectory, string $file): string
    {
        $target = escapeshellarg("{$flatDirectory}/{$file}");
        $limit = Application::MAX_DOCKER_COMPOSE_SIZE_BYTES;

        return 'mkdir -p '.escapeshellarg(dirname("{$flatDirectory}/{$file}"))
            .' && cd '.escapeshellarg($checkoutDirectory)
            .' && file='.escapeshellarg($file).'; '.self::enterSubmoduleScript()
            .' git show "HEAD:$file" > '.$target
            ." && if [ \"$(wc -c < {$target})\" -gt {$limit} ]; then echo ".self::TOO_LARGE_MARKER."; else cat {$target}; fi";
    }

    /**
     * Compose stops when an `env_file` of an include does not exist. Coolify does not interpolate the Compose
     * file, so empty files are enough.
     *
     * @param  array<int, string>  $envFiles
     */
    protected function runComposeConfig(string $composeFile, string $projectDirectory, array $envFiles): string
    {
        $script = collect($envFiles)
            ->map(function (string $envFile) {
                $path = escapeshellarg("{$this->flatDirectory}/{$envFile}");

                return 'mkdir -p '.escapeshellarg(dirname("{$this->flatDirectory}/{$envFile}"))." && touch {$path}";
            })
            ->push('cd '.escapeshellarg($this->flatDirectory)
                .' && COMPOSE_EXPERIMENTAL_GIT_REMOTE=0 COMPOSE_EXPERIMENTAL_OCI_REMOTE=0 docker compose'
                .' --project-directory '.escapeshellarg("./{$projectDirectory}")
                .' -f '.escapeshellarg("./{$composeFile}")
                .' config --no-interpolate --no-path-resolution --no-normalize')
            ->implode(' && ');

        $flattened = $this->run($script);
        if (strlen($flattened) > Application::MAX_DOCKER_COMPOSE_SIZE_BYTES) {
            throw new DeploymentException('The Docker Compose file with its included files exceeds the 5 MiB size limit.');
        }

        return $flattened;
    }
}
