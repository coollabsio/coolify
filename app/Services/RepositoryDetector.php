<?php

namespace App\Services;

use App\Data\RepositoryDetectionResult;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Support\Collection;
use RuntimeException;

class RepositoryDetector
{
    /**
     * Largest env template that is read from the repository, in bytes.
     */
    private const MAX_ENV_FILE_BYTES = 65536;

    /**
     * @param  Application  $application  Unsaved application with the repository, branch, and Git source or deploy key.
     */
    public function __construct(
        private Application $application,
        private string $baseDirectory,
        private Server $server,
    ) {}

    /**
     * @throws RuntimeException When the repository cannot be read.
     */
    public function detect(): RepositoryDetectionResult
    {
        $uuid = new_public_id();

        try {
            $output = instant_remote_process($this->scanCommands($uuid), $this->server, timeout: 60);
        } finally {
            instant_remote_process(["rm -rf /tmp/{$uuid}"], $this->server, false);
        }

        return $this->parseOutput((string) str($output)->trim()->afterLast("\n"));
    }

    /**
     * Clones the repository without a checkout and lists the files with `git ls-tree`, so no file is
     * written to the disk. Each command is one line because non-root servers send every line through
     * parseCommandsByLineForSudo().
     *
     * @return Collection<int, string>
     */
    protected function scanCommands(string $uuid): Collection
    {
        $checkoutDir = "/tmp/{$uuid}/checkout";
        $baseDir = trim($this->baseDirectory, '/');
        if (preg_match('~(^|/)\.\.?(/|$)~', $baseDir) || ! preg_match('~^[a-zA-Z0-9_./-]*$~', $baseDir)) {
            throw new RuntimeException('Invalid repository base directory.');
        }
        $tree = escapeshellarg($baseDir === '' ? 'HEAD' : "HEAD:{$baseDir}");
        $maxEnvBytes = self::MAX_ENV_FILE_BYTES;

        $classifyFiles = <<<'AWK'
{ split($1, meta, " "); if (meta[1] != "100644" && meta[1] != "100755") next; path = $2; lower = tolower(path); name = lower; sub(/.*\//, "", name); if (name ~ /^dockerfile(\.[a-z0-9_-]+)?$/) kind = "dockerfile"; else if (name ~ /^(docker-)?compose\.ya?ml$/) kind = "compose"; else if (lower ~ /^\.env\.(example|sample|template|dist|local\.example)$/) kind = "env"; else next; print kind "\t" meta[3] "\t" path }
AWK;
        $exposedPort = <<<'AWK'
{ sub(/\r$/, "") } toupper($1) == "EXPOSE" { split($2, p, "/"); if (p[1] ~ /^[0-9]+$/ && p[1] + 0 > 0 && p[1] + 0 <= 65535) print p[1] + 0; exit }
AWK;
        $summary = '{dockerfiles: map(select(.kind == "dockerfile") | .file), dockerComposeFiles: map(select(.kind == "compose") | .file), envFiles: (map(select(.kind == "env") | {(.file): .content}) | add // {}), dockerfilePorts: (map(select(.kind == "dockerfile") | {(.file): .port}) | add // {})}';

        $scan = 'tab=$(printf "\t"); '
            ."git -c core.quotePath=false ls-tree -r {$tree} | awk -F '\\t' ".escapeshellarg($classifyFiles)
            .' | while IFS="$tab" read -r kind object file; do case "$kind" in '
            .'dockerfile) port=$(git cat-file blob "$object" | awk '.escapeshellarg($exposedPort).'); '
            .'jq -nc --arg file "$file" --argjson port "${port:-null}" \'{kind: "dockerfile", file: $file, port: $port}\' ;; '
            .'compose) jq -nc --arg file "$file" \'{kind: "compose", file: $file}\' ;; '
            ."env) git cat-file blob \"\$object\" | head -c {$maxEnvBytes} | jq -Rsc --arg file \"\$file\" '{kind: \"env\", file: \$file, content: .}' ;; "
            .'esac; done | jq -sc '.escapeshellarg($summary);

        return collect([
            "rm -rf /tmp/{$uuid}",
            "mkdir -p /tmp/{$uuid}",
            "cd /tmp/{$uuid}",
            'sh -c '.escapeshellarg($this->application->serverCheckoutCommand($uuid, $checkoutDir)),
            "cd {$checkoutDir}",
            'sh -c '.escapeshellarg($scan),
        ]);
    }

    protected function parseOutput(string $output): RepositoryDetectionResult
    {
        $data = json_decode(trim($output), true);

        if (! is_array($data)) {
            return RepositoryDetectionResult::none();
        }

        foreach (['dockerfiles', 'dockerComposeFiles', 'envFiles', 'dockerfilePorts'] as $key) {
            if (isset($data[$key]) && ! is_array($data[$key])) {
                return RepositoryDetectionResult::none();
            }
        }

        $dockerfilePorts = [];
        foreach ($data['dockerfilePorts'] ?? [] as $file => $port) {
            $dockerfilePorts[$file] = (is_int($port) || (is_string($port) && ctype_digit($port))) && (int) $port > 0 && (int) $port <= 65535 ? (int) $port : null;
        }

        return new RepositoryDetectionResult(
            dockerfiles: $this->sortByPreference(array_filter($data['dockerfiles'] ?? [], 'is_string'), '/^dockerfile$/i'),
            dockerComposeFiles: $this->sortByPreference(array_filter($data['dockerComposeFiles'] ?? [], 'is_string'), '/^docker-compose\.ya?ml$/i'),
            envFiles: array_filter($data['envFiles'] ?? [], fn ($content) => is_string($content) || $content === null),
            dockerfilePorts: $dockerfilePorts,
        );
    }

    /**
     * Puts the file nearest to the base directory first. At the same depth, a file with the default
     * name (for example `Dockerfile`) comes before a variant. Other files keep the scan order.
     *
     * @param  array<int|string, string>  $files
     * @return array<int, string>
     */
    private function sortByPreference(array $files, string $defaultNamePattern): array
    {
        $files = array_values($files);
        usort($files, fn (string $a, string $b): int => [substr_count($a, '/'), ! preg_match($defaultNamePattern, basename($a))]
            <=> [substr_count($b, '/'), ! preg_match($defaultNamePattern, basename($b))]);

        return $files;
    }
}
