<?php

namespace App\Services;

use App\Data\RepositoryDetectionResult;
use App\Models\Server;
use Illuminate\Support\Facades\Log;
use Visus\Cuid2\Cuid2;

class RepositoryDetector
{
    /**
     * Env file patterns considered safe to import (template/example files, not real secrets).
     */
    private const ENV_FILE_PATTERN = '^\\.env\\.(example|sample|template|dist|local\\.example|development|production|staging|testing|test)$';

    public function __construct(
        private string $repositoryUrl,
        private string $branch,
        private string $baseDirectory,
        private int $serverId,
        private int $teamId,
    ) {}

    public function detect(): RepositoryDetectionResult
    {
        $server = Server::query()
            ->where('id', $this->serverId)
            ->where('team_id', $this->teamId)
            ->first();

        if (! $server) {
            Log::debug('Repository detection skipped: server not found', [
                'serverId' => $this->serverId,
                'teamId' => $this->teamId,
            ]);

            return RepositoryDetectionResult::none();
        }

        $uuid = (string) new Cuid2;
        if (strlen($uuid) < 10 || ! preg_match('/^[a-z0-9]+$/', $uuid)) {
            return RepositoryDetectionResult::none();
        }

        $tempDir = "/tmp/coolify-detect-{$uuid}";
        $baseDir = rtrim($this->baseDirectory, '/');
        if ($baseDir === '') {
            $baseDir = '/';
        }
        $cdBase = $baseDir === '/' ? '' : $baseDir;

        $workDir = escapeshellarg("{$tempDir}{$cdBase}");
        $envPattern = self::ENV_FILE_PATTERN;

        $escapedTempDir = escapeshellarg($tempDir);

        $commands = collect([
            'rm -rf -- '.$escapedTempDir,
            "trap 'rm -rf -- {$escapedTempDir}' EXIT",
            'git clone --depth 1 -b '.escapeshellarg($this->branch).' '.escapeshellarg($this->repositoryUrl).' '.escapeshellarg($tempDir).' >/dev/null 2>&1 || git clone --depth 1 '.escapeshellarg($this->repositoryUrl).' '.escapeshellarg($tempDir).' >/dev/null 2>&1',
            "cd {$workDir}",
            // Collect file lists into shell variables
            'df_list=$(git ls-files | grep -iE \'(^|/)Dockerfile(\.[a-zA-Z0-9_-]+)?$\' || true)',
            'compose_list=$(git ls-files | grep -iE \'(^|/)(docker-compose\.(yml|yaml)|compose\.(yml|yaml))$\' || true)',
            'env_list=$(git ls-files | grep -iE \''.$envPattern.'\' || true)',
            // Build env file contents as a JSON object (uses jq to safely encode file content)
            'env_json=\'{}\'',
            'for f in $env_list; do',
            '  file_json=$(jq -Rs \'.\' "$f")',
            '  env_json=$(echo "$env_json" | jq --arg k "$f" --argjson v "$file_json" \'. + {($k): $v}\')',
            'done',
            // Build dockerfile ports as a JSON object
            'port_json=\'{}\'',
            'for f in $df_list; do',
            '  port=$(grep -m1 \'^EXPOSE\' "$f" 2>/dev/null | awk \'{print $2}\' || true)',
            '  if [ -n "$port" ] && echo "$port" | grep -qE \'^[0-9]+$\'; then',
            '    port_json=$(echo "$port_json" | jq --arg k "$f" --argjson v "$port" \'. + {($k): $v}\')',
            '  else',
            '    port_json=$(echo "$port_json" | jq --arg k "$f" \'. + {($k): null}\')',
            '  fi',
            'done',
            // Output structured JSON
            'jq -n \\',
            '  --argjson dockerfiles "$(echo "$df_list" | jq -R -s \'split("\\n") | map(select(. != ""))\')" \\',
            '  --argjson dockerComposeFiles "$(echo "$compose_list" | jq -R -s \'split("\\n") | map(select(. != ""))\')" \\',
            '  --argjson envFiles "$env_json" \\',
            '  --argjson dockerfilePorts "$port_json" \\',
            '  \'$ARGS.named\'',
        ]);

        try {
            $output = instant_remote_process($commands, $server, throwError: false, timeout: 60);

            if (! $output) {
                return RepositoryDetectionResult::none();
            }

            return $this->parseOutput($output);
        } catch (\Throwable $e) {
            Log::debug('Repository detection failed', [
                'error' => $e->getMessage(),
                'repositoryUrl' => $this->repositoryUrl,
                'branch' => $this->branch,
            ]);

            return RepositoryDetectionResult::none();
        }
    }

    protected function parseOutput(string $output): RepositoryDetectionResult
    {
        $data = json_decode(trim($output), true);

        if (! is_array($data)) {
            return RepositoryDetectionResult::none();
        }

        $dockerfilePorts = [];
        foreach ($data['dockerfilePorts'] ?? [] as $file => $port) {
            $dockerfilePorts[$file] = is_numeric($port) ? (int) $port : null;
        }

        return new RepositoryDetectionResult(
            dockerfiles: $data['dockerfiles'] ?? [],
            dockerComposeFiles: $data['dockerComposeFiles'] ?? [],
            envFiles: $data['envFiles'] ?? [],
            dockerfilePorts: $dockerfilePorts,
        );
    }
}
