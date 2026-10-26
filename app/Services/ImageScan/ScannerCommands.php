<?php

namespace App\Services\ImageScan;

use App\Models\Server;
use App\Support\ValidationPatterns;
use InvalidArgumentException;

/**
 * Builds the shell commands that run Syft, Grype, and regctl on a server.
 *
 * Each method returns one command list for `instant_remote_process()` (one `bash -se` batch). The lists
 * work as root and through `parseCommandsByLineForSudo()`, and they are safe to replay after an SSH retry:
 * each list first removes the container `coolify-scan-{runId}` and the work directory, and writes the
 * SBOM to `{name}.json.tmp-{runId}` before it moves it into place.
 *
 * Output contract (stdout of the batch):
 * - Every tool run prints one marker line `coolify-scan-exit {step} {code}`; steps that produce output add
 *   the full output size in bytes: `coolify-scan-exit {step} {code} {bytes}`. `{code}` is the exit code of
 *   the run: 0 success, 124 timeout, 137 killed (timeout kill or memory limit), 125 `docker run` failed
 *   (for example the image could not be pulled), any other value is an error of the tool. Steps: `pull-syft`,
 *   `pull-grype`, `pull-regctl` (prepare), `db-update`, `db-status`, `inspect`, `save` and `sbom`
 *   (sbomFromLocal), `sbom` (sbomFromRegistry), `grype`, `packages`, `tags`, `digest`.
 * - A failed run does not stop the batch, and the container and the work directory are still removed.
 * - Methods with a payload print the line `coolify-scan-payload` after the markers, then at most 10485761
 *   bytes of the tool output (`head -c`), so the output is cut when `bytes > OUTPUT_LIMIT`. Use `parseOutput()`:
 *   it reads markers only before the separator, so the payload cannot fake one. Only setup commands (mkdir, chown,
 *   chmod) stop the batch when they fail; call `cleanup()` after every scan, also after an exception.
 * - `inspect`: one line `{Id}|{RepoDigest or empty}|{os}/{arch}|{size}`. `diskFree`: `{docker root free} {scan dir free}` in bytes.
 *
 * Containers get only a scratch directory as writable mount. The host never reads, moves, or changes
 * the owner of a file that a container could write: tool output goes through stdout into root-owned
 * files, and the work directory is removed with `rm -rf`. A `trap` cannot be used: the sudo parser
 * would turn it (and `W=$(mktemp ...)`) into `sudo trap`, so the work directory is
 * `/data/coolify/image-scans/tmp-{runId}`.
 */
class ScannerCommands
{
    public const BASE_DIR = '/data/coolify/image-scans';

    public const SBOM_DIR = self::BASE_DIR.'/sboms';

    public const DB_VOLUME = 'coolify-grype-db';

    public const OUTPUT_LIMIT = 10485760;

    public const MARKER = 'coolify-scan-exit';

    public const PAYLOAD_SEPARATOR = 'coolify-scan-payload';

    private const SCANNER_USER = '65534:65534';

    private const TIMEOUT_SBOM = 600;

    private const TIMEOUT_GRYPE = 300;

    private const TIMEOUT_REGISTRY = 30;

    private const TIMEOUT_DB_UPDATE = 900;

    private const TIMEOUT_DB_STATUS = 60;

    private const TIMEOUT_PULL = 600;

    public function __construct(public readonly Server $server, public readonly string $runId)
    {
        if (preg_match('/\A[a-z0-9]{8,32}\z/', $runId) !== 1) {
            throw new InvalidArgumentException('Invalid scan run ID.');
        }
    }

    /**
     * Creates the SBOM directory and the Grype database volume, and pulls the three scanner images.
     *
     * @return list<string>
     */
    public function prepare(): array
    {
        return [
            $this->removeContainer(),
            ...$this->sbomDirectory(),
            ...$this->databaseVolume(),
            ...array_map(
                fn (string $tool) => $this->step("pull-{$tool}", 'timeout -k 10 '.self::TIMEOUT_PULL.' docker pull '.escapeshellarg($this->image($tool)).' >/dev/null 2>&1'),
                ['syft', 'grype', 'regctl'],
            ),
        ];
    }

    /**
     * @return list<string>
     */
    public function dbUpdate(?string $dbUrl): array
    {
        $env = ['GRYPE_DB_CACHE_DIR' => '/db', 'TMPDIR' => '/db/tmp'];
        if ($dbUrl !== null) {
            $env['GRYPE_DB_UPDATE_URL'] = $this->validDatabaseUrl($dbUrl);
        }

        return [
            ...$this->start(['scratch']),
            ...$this->ownWorkDir(),
            ...$this->databaseVolume(),
            $this->step('db-update', $this->dockerRun('grype', self::TIMEOUT_DB_UPDATE, true, $env, ['type=volume,src='.self::DB_VOLUME.',dst=/db'], ['db', 'update']).' >/dev/null 2>&1'),
            ...$this->finish(),
        ];
    }

    /**
     * Prints `grype db status -o json` (database build date and schema) as payload.
     *
     * @return list<string>
     */
    public function dbStatus(): array
    {
        return $this->capture('db-status', $this->dockerRun('grype', self::TIMEOUT_DB_STATUS, false, $this->grypeDatabaseEnv(), [$this->databaseMount()], ['db', 'status', '-o', 'json']));
    }

    /**
     * @return list<string>
     */
    public function inspect(string $imageRef): array
    {
        $ref = escapeshellarg((string) ImageRef::parse($imageRef));
        $format = escapeshellarg('{{.Id}}|{{if .RepoDigests}}{{index .RepoDigests 0}}{{end}}|{{.Os}}/{{.Architecture}}|{{.Size}}');

        return [
            $this->removeContainer(),
            $this->step('inspect', "docker image inspect --format '{{.Id}}' {$ref} >/dev/null 2>&1"),
            'echo '.self::PAYLOAD_SEPARATOR,
            "docker image inspect --format {$format} {$ref} 2>/dev/null || true",
        ];
    }

    /**
     * @return list<string>
     */
    public function diskFree(): array
    {
        $base = escapeshellarg(self::BASE_DIR);

        return [
            $this->removeContainer(),
            "mkdir -p -m 0755 {$base}",
            "df -P -B1 \"\$(docker info -f '{{.DockerRootDir}}')\" | awk 'NR==2 {printf \"%s \", \$4}'",
            "df -P -B1 {$base} | awk 'NR==2 {print \$4}'",
        ];
    }

    /**
     * Saves a local image by its ID (not by $imageRef: a tag can move after inspect) and writes its SBOM
     * to `sboms/{imageIdHex}.json`. $imageRef is only validated.
     *
     * @return list<string>
     */
    public function sbomFromLocal(string $imageRef, string $imageIdHex): array
    {
        ImageRef::parse($imageRef);
        if (preg_match('/\A[a-f0-9]{64}\z/', $imageIdHex) !== 1) {
            throw new InvalidArgumentException('Invalid image ID.');
        }
        $archive = $this->workDir().'/in/image.tar';

        return [
            $this->removeContainer(),
            'rm -rf '.escapeshellarg($this->workDir()).' || true',
            ...$this->sbomDirectory(),
            'rm -f '.escapeshellarg($this->sbomTmpPath($imageIdHex)).' || true',
            'mkdir -p -m 0700 '.escapeshellarg($this->workDir().'/in').' '.escapeshellarg($this->workDir().'/scratch'),
            $this->step('save', 'timeout -k 10 '.self::TIMEOUT_SBOM.' docker save -o '.escapeshellarg($archive).' '.escapeshellarg('sha256:'.$imageIdHex).' >/dev/null 2>&1'),
            ...$this->ownWorkDir(),
            $this->writeSbom($imageIdHex, $this->dockerRun('syft', self::TIMEOUT_SBOM, false, [], ['type=bind,src='.$this->workDir().'/in,dst=/in,readonly'], ['scan', 'docker-archive:/in/image.tar', '-o', 'syft-json', '-q'])),
            ...$this->finish(['rm -f '.escapeshellarg($this->sbomTmpPath($imageIdHex)).' || true']),
        ];
    }

    /**
     * Writes the SBOM of a registry image to `sboms/{outName}.json` (network on, no socket).
     *
     * @return list<string>
     */
    public function sbomFromRegistry(string $imageRef, string $platform, string $outName, ?string $authConfigB64): array
    {
        $parsed = ImageRef::parse($imageRef);
        $ref = (string) $parsed;
        $platform = $this->validPlatform($platform);
        $this->validSbomName($outName);
        $auth = $this->authConfig($authConfigB64, $parsed);

        return [
            $this->removeContainer(),
            'rm -rf '.escapeshellarg($this->workDir()).' || true',
            ...$this->sbomDirectory(),
            'rm -f '.escapeshellarg($this->sbomTmpPath($outName)).' || true',
            ...$this->workDirSetup($auth),
            $this->writeSbom($outName, $this->dockerRun('syft', self::TIMEOUT_SBOM, true, $this->authEnv($auth), $this->authMounts($auth), ['scan', '--platform', $platform, 'registry:'.$ref, '-o', 'syft-json', '-q'])),
            ...$this->finish(['rm -f '.escapeshellarg($this->sbomTmpPath($outName)).' || true']),
        ];
    }

    /**
     * Matches a stored SBOM against the cached database; payload is the NDJSON of `grype.tmpl`.
     *
     * @return list<string>
     */
    public function grype(string $sbomName): array
    {
        $this->validSbomName($sbomName);

        return $this->capture('grype', $this->dockerRun('grype', self::TIMEOUT_GRYPE, false, $this->grypeDatabaseEnv(), [
            $this->databaseMount(),
            $this->sbomMount($sbomName),
            'type=bind,src='.$this->workDir().'/tpl,dst=/tpl,readonly',
        ], ['sbom:/sbom/sbom.json', '-o', 'template', '-t', '/tpl/grype.tmpl', '-q']), 'grype.tmpl');
    }

    /**
     * Lists the packages of a stored SBOM; payload is the NDJSON of `syft.tmpl`.
     *
     * @return list<string>
     */
    public function packages(string $sbomName): array
    {
        $this->validSbomName($sbomName);

        return $this->capture('packages', $this->dockerRun('syft', self::TIMEOUT_GRYPE, false, [], [
            $this->sbomMount($sbomName),
            'type=bind,src='.$this->workDir().'/tpl,dst=/tpl,readonly',
        ], ['convert', '/sbom/sbom.json', '-o', 'template', '-t', '/tpl/syft.tmpl', '-q']), 'syft.tmpl');
    }

    /**
     * Lists the tags of a repository, one per line.
     *
     * @return list<string>
     */
    public function tags(string $repository, ?string $authConfigB64): array
    {
        $parsed = ImageRef::parse($repository);
        $name = $parsed->qualifiedName();
        $auth = $this->authConfig($authConfigB64, $parsed);

        return $this->capture('tags', $this->dockerRun('regctl', self::TIMEOUT_REGISTRY, true, $this->authEnv($auth), $this->authMounts($auth), ['tag', 'ls', $name]), null, $auth);
    }

    /**
     * Prints the registry digest of an image (the index digest, or the platform manifest digest with $platform).
     *
     * @return list<string>
     */
    public function digest(string $imageRef, ?string $platform, ?string $authConfigB64): array
    {
        $parsed = ImageRef::parse($imageRef);
        $ref = (string) $parsed;
        $args = ['image', 'digest'];
        if ($platform !== null) {
            array_push($args, '--platform', $this->validPlatform($platform));
        }
        $auth = $this->authConfig($authConfigB64, $parsed);

        return $this->capture('digest', $this->dockerRun('regctl', self::TIMEOUT_REGISTRY, true, $this->authEnv($auth), $this->authMounts($auth), [...$args, $ref]), null, $auth);
    }

    /**
     * Removes the container and the work directory of this run, and leftovers of runs that crashed over an hour ago.
     *
     * @return list<string>
     */
    public function cleanup(): array
    {
        return [
            $this->removeContainer(),
            'rm -rf '.escapeshellarg($this->workDir()).' || true',
            'find '.escapeshellarg(self::BASE_DIR)." -maxdepth 1 -name 'tmp-*' -mmin +60 -exec rm -rf {} + 2>/dev/null || true",
            'find '.escapeshellarg(self::SBOM_DIR)." -maxdepth 1 -name '*.json.tmp-*' -mmin +60 -delete 2>/dev/null || true",
        ];
    }

    /**
     * Removes all SBOMs, the Grype database volume, and the scanner images from the server.
     *
     * @return list<string>
     */
    public function deleteScannerData(): array
    {
        return [
            $this->removeContainer(),
            'rm -rf '.escapeshellarg(self::BASE_DIR).' || true',
            'docker volume rm -f '.self::DB_VOLUME.' >/dev/null 2>&1 || true',
            'docker image rm '.implode(' ', array_map(fn (string $tool) => escapeshellarg($this->image($tool)), ['syft', 'grype', 'regctl'])).' >/dev/null 2>&1 || true',
        ];
    }

    /**
     * Splits batch output into the marker lines before `coolify-scan-payload` and the payload after it.
     *
     * @return array{exit_codes: array<string, int>, sizes: array<string, int>, payload: string}
     */
    public static function parseOutput(?string $output): array
    {
        $lines = array_map(fn (string $line) => rtrim($line, "\r"), explode("\n", (string) $output));
        $separator = array_search(self::PAYLOAD_SEPARATOR, $lines, true);
        $exitCodes = [];
        $sizes = [];

        foreach ($separator === false ? $lines : array_slice($lines, 0, $separator) as $line) {
            if (preg_match('/\A'.self::MARKER.' ([a-z-]+) (\d+)(?: +(\d+))?\z/', $line, $matches) !== 1) {
                continue;
            }
            $exitCodes[$matches[1]] = (int) $matches[2];
            if (isset($matches[3])) {
                $sizes[$matches[1]] = (int) $matches[3];
            }
        }

        return [
            'exit_codes' => $exitCodes,
            'sizes' => $sizes,
            'payload' => $separator === false ? '' : implode("\n", array_slice($lines, $separator + 1)),
        ];
    }

    private function containerName(): string
    {
        return 'coolify-scan-'.$this->runId;
    }

    private function workDir(): string
    {
        return self::BASE_DIR.'/tmp-'.$this->runId;
    }

    private function sbomPath(string $name): string
    {
        return self::SBOM_DIR.'/'.$name.'.json';
    }

    /**
     * Per run, so a replay or a parallel run never moves another run's partial file into place.
     */
    private function sbomTmpPath(string $name): string
    {
        return $this->sbomPath($name).'.tmp-'.$this->runId;
    }

    private function removeContainer(): string
    {
        return 'docker rm -f '.$this->containerName().' >/dev/null 2>&1 || true';
    }

    private function image(string $tool): string
    {
        $repo = config("constants.image_scan.images.{$tool}.repo");
        $digest = config("constants.image_scan.images.{$tool}.digest");

        if (! is_string($repo) || ! is_string($digest) || ! ValidationPatterns::isValidDockerImageName($repo) || $repo === '' || ! ImageRef::isValidDigest($digest)) {
            throw new InvalidArgumentException("The {$tool} scanner image is not pinned to a sha256 digest.");
        }

        return $repo.'@'.$digest;
    }

    /**
     * `if` keeps a failed run from stopping the batch; `$?` in `else` is the exit code of the run.
     */
    private function step(string $step, string $command): string
    {
        return "if {$command}; then echo '".self::MARKER." {$step} 0'; else echo \"".self::MARKER." {$step} \$?\"; fi";
    }

    /**
     * A `timeout`-wrapped `docker run` with the hardening flags. Values are escaped; mounts use `--mount`, which fails on a missing source.
     *
     * @param  array<string, string>  $env
     * @param  list<string>  $mounts
     * @param  list<string>  $args
     */
    private function dockerRun(string $tool, int $timeout, bool $network, array $env, array $mounts, array $args): string
    {
        $env = array_merge([
            'SYFT_CHECK_FOR_APP_UPDATE' => 'false',
            'GRYPE_CHECK_FOR_APP_UPDATE' => 'false',
            'TMPDIR' => '/scratch',
            'XDG_CACHE_HOME' => '/scratch/cache',
        ], $env);
        $mounts = ['type=bind,src='.$this->workDir().'/scratch,dst=/scratch', ...$mounts];

        $parts = [
            "timeout -k 10 {$timeout} docker run",
            '--rm --name '.$this->containerName(),
            '--cap-drop ALL --security-opt no-new-privileges --read-only --pids-limit 256',
            '--user '.self::SCANNER_USER.' --cpus 1 --memory 1g --memory-swap 1g --log-driver none',
        ];
        if (! $network) {
            $parts[] = '--network none';
        }
        foreach ($env as $key => $value) {
            $parts[] = '-e '.escapeshellarg("{$key}={$value}");
        }
        foreach ($mounts as $mount) {
            $parts[] = '--mount '.escapeshellarg($mount);
        }
        $parts[] = escapeshellarg($this->image($tool));
        foreach ($args as $arg) {
            $parts[] = escapeshellarg($arg);
        }

        return implode(' ', $parts);
    }

    /**
     * Runs a tool whose stdout is the payload: stdout goes to a root-owned file in the work directory
     * (not mounted into the container), then at most 10485761 bytes of it are printed.
     *
     * @param  array<string, mixed>|null  $auth
     * @return list<string>
     */
    private function capture(string $step, string $dockerRun, ?string $template = null, ?array $auth = null): array
    {
        $out = escapeshellarg($this->workDir().'/out');
        $commands = [...$this->start($template === null ? ['scratch'] : ['scratch', 'tpl'])];
        if ($template !== null) {
            $commands[] = $this->writeFile(file_get_contents(resource_path("image-scan/{$template}")), $this->workDir()."/tpl/{$template}");
        }
        if ($auth !== null) {
            $commands = [...$commands, ...$this->authSetup($auth)];
        }

        return [
            ...$commands,
            ...$this->ownWorkDir($auth !== null),
            $this->shell("{$dockerRun} > {$out} 2>/dev/null; rc=\$?; echo \"".self::MARKER." {$step} \$rc \$(wc -c < {$out} 2>/dev/null || echo 0)\""),
            ...$this->finish(['echo '.self::PAYLOAD_SEPARATOR, 'head -c '.(self::OUTPUT_LIMIT + 1)." {$out} 2>/dev/null || true"]),
        ];
    }

    /**
     * Syft writes the SBOM to stdout; only a complete run is moved into place.
     */
    private function writeSbom(string $name, string $dockerRun): string
    {
        $tmp = escapeshellarg($this->sbomTmpPath($name));
        $final = escapeshellarg($this->sbomPath($name));

        return $this->shell("{$dockerRun} > {$tmp} 2>/dev/null && chown ".self::SCANNER_USER." {$tmp} && chmod 600 {$tmp} && mv -f {$tmp} {$final}; rc=\$?; echo \"".self::MARKER.' sbom $rc"');
    }

    /**
     * One `sh -c` line: the sudo parser prefixes it with `sudo` and leaves the script as it is.
     */
    private function shell(string $script): string
    {
        return 'sh -c '.escapeshellarg($script);
    }

    /**
     * @param  list<string>  $subdirectories
     * @return list<string>
     */
    private function start(array $subdirectories): array
    {
        return [
            $this->removeContainer(),
            'rm -rf '.escapeshellarg($this->workDir()).' || true',
            'mkdir -p -m 0700 '.implode(' ', array_map(fn (string $dir) => escapeshellarg($this->workDir()."/{$dir}"), $subdirectories)),
        ];
    }

    /**
     * @param  list<string>  $before
     * @return list<string>
     */
    private function finish(array $before = []): array
    {
        return [
            $this->removeContainer(),
            ...$before,
            'rm -rf '.escapeshellarg($this->workDir()).' || true',
        ];
    }

    /**
     * Runs before any container used the directory, so nothing in it can be a planted link.
     *
     * @return list<string>
     */
    private function ownWorkDir(bool $hasAuth = false): array
    {
        $commands = [
            'chown -R '.self::SCANNER_USER.' '.escapeshellarg($this->workDir()),
            'chmod 700 '.escapeshellarg($this->workDir()),
        ];
        if ($hasAuth) {
            $commands[] = 'chmod 600 '.escapeshellarg($this->workDir().'/auth/config.json');
        }

        return $commands;
    }

    /**
     * @param  array<string, mixed>|null  $auth
     * @return list<string>
     */
    private function workDirSetup(?array $auth): array
    {
        return [
            'mkdir -p -m 0700 '.escapeshellarg($this->workDir().'/scratch'),
            ...($auth !== null ? $this->authSetup($auth) : []),
            ...$this->ownWorkDir($auth !== null),
        ];
    }

    /**
     * @return list<string>
     */
    private function sbomDirectory(): array
    {
        $dir = escapeshellarg(self::SBOM_DIR);

        return [
            "mkdir -p -m 0700 {$dir}",
            'chown '.self::SCANNER_USER." {$dir}",
            "chmod 700 {$dir}",
        ];
    }

    /**
     * Creates the database volume owned by the scanner user with a `tmp/` directory for `db update`.
     * `chown -h` does not follow a link that a database update could have left.
     *
     * @return list<string>
     */
    private function databaseVolume(): array
    {
        $mountpoint = "\"\$(docker volume inspect -f '{{.Mountpoint}}' ".self::DB_VOLUME.')';

        return [
            'docker volume create '.self::DB_VOLUME.' >/dev/null',
            'chown '.self::SCANNER_USER." {$mountpoint}\"",
            "mkdir -p -m 0700 {$mountpoint}/tmp\"",
            'chown -h '.self::SCANNER_USER." {$mountpoint}/tmp\"",
        ];
    }

    /**
     * @return array<string, string>
     */
    private function grypeDatabaseEnv(): array
    {
        return ['GRYPE_DB_CACHE_DIR' => '/db', 'GRYPE_DB_AUTO_UPDATE' => 'false'];
    }

    private function databaseMount(): string
    {
        return 'type=volume,src='.self::DB_VOLUME.',dst=/db,readonly';
    }

    private function sbomMount(string $name): string
    {
        return 'type=bind,src='.$this->sbomPath($name).',dst=/sbom/sbom.json,readonly';
    }

    /**
     * `echo` is a shell builtin and a sudo parser keyword: the content never shows up in a process
     * argument list or in the sudo log; only `base64 -d` and `tee` run with sudo.
     */
    private function writeFile(string $content, string $path): string
    {
        return 'echo '.escapeshellarg(base64_encode($content)).' | base64 -d | tee '.escapeshellarg($path).' >/dev/null';
    }

    /**
     * Accepts a Docker config with one registry in `auths` and nothing else (no credential helpers).
     * The registry must be the registry of the image.
     *
     * @return array<string, mixed>|null
     */
    private function authConfig(?string $authConfigB64, ImageRef $image): ?array
    {
        if ($authConfigB64 === null) {
            return null;
        }

        $json = preg_match('/\A[A-Za-z0-9+\/]+={0,2}\z/', $authConfigB64) === 1 ? base64_decode($authConfigB64, true) : false;
        $config = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($config) || array_keys($config) !== ['auths'] || ! is_array($config['auths']) || count($config['auths']) !== 1 || ! is_array(reset($config['auths']))) {
            throw new InvalidArgumentException('The registry credentials must be a Docker config for one registry.');
        }
        if (! $this->credentialsMatchRegistry((string) array_key_first($config['auths']), $image->registry)) {
            throw new InvalidArgumentException('The registry credentials are not for the registry of the image.');
        }

        return $config;
    }

    private function credentialsMatchRegistry(string $key, string $registry): bool
    {
        $host = rtrim(preg_replace('#\Ahttps?://#', '', $key) ?? '', '/');

        if ($registry === 'docker.io') {
            return in_array($host, ['index.docker.io/v1', 'index.docker.io', 'docker.io', 'registry-1.docker.io'], true);
        }

        return $host === $registry;
    }

    /**
     * @param  array<string, mixed>  $auth
     * @return list<string>
     */
    private function authSetup(array $auth): array
    {
        return [
            'mkdir -p -m 0700 '.escapeshellarg($this->workDir().'/auth'),
            $this->writeFile(json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), $this->workDir().'/auth/config.json'),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $auth
     * @return array<string, string>
     */
    private function authEnv(?array $auth): array
    {
        return $auth === null ? [] : ['DOCKER_CONFIG' => '/auth'];
    }

    /**
     * @param  array<string, mixed>|null  $auth
     * @return list<string>
     */
    private function authMounts(?array $auth): array
    {
        return $auth === null ? [] : ['type=bind,src='.$this->workDir().'/auth,dst=/auth,readonly'];
    }

    private function validPlatform(string $platform): string
    {
        if (preg_match('#\A[a-z0-9]+/[a-z0-9_]+(/[a-z0-9]+)?\z#', $platform) !== 1) {
            throw new InvalidArgumentException('Invalid platform.');
        }

        return $platform;
    }

    private function validSbomName(string $name): void
    {
        if (preg_match('/\A[a-z0-9][a-z0-9-]{0,99}\z/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid SBOM name.');
        }
    }

    /**
     * No `&`, `|`, `$`, or quotes: the sudo parser rewrites `&&`, `||`, and `$(` even inside quotes.
     */
    private function validDatabaseUrl(string $url): string
    {
        if (preg_match('#\Ahttps?://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[A-Za-z0-9._~%/+=-]*)?\z#', $url) !== 1) {
            throw new InvalidArgumentException('Invalid vulnerability database URL.');
        }

        return $url;
    }
}
