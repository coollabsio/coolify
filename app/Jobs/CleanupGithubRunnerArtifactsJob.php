<?php

namespace App\Jobs;

use App\Models\GithubRunnerConfig;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CleanupGithubRunnerArtifactsJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 300;

    public $tries = 1;

    public function __construct()
    {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        $targets = GithubRunnerConfig::query()
            ->where('is_enabled', true)
            ->with('server')
            ->get()
            ->filter(fn (GithubRunnerConfig $config) => $config->server?->isFunctional())
            ->map(fn (GithubRunnerConfig $config): array => [
                'server' => $config->server,
                'base_dir' => $config->runner_base_dir,
            ])
            ->unique(fn (array $target): string => "{$target['server']->id}:{$target['base_dir']}")
            ->values();

        foreach ($targets as $target) {
            $baseDir = validateShellSafePath($target['base_dir'], 'runner base directory');

            instant_remote_process(static::buildCleanupCommands($baseDir), $target['server'], throwError: false);
        }
    }

    public static function buildCleanupCommands(string $baseDir): array
    {
        $quotedBaseDir = escapeshellarg($baseDir);

        return [
            "if [ -d {$quotedBaseDir}/.cache ]; then for arch in x64 arm64; do ls -1t {$quotedBaseDir}/.cache/actions-runner-linux-\${arch}-*.tar.gz 2>/dev/null | tail -n +3 | xargs -r rm -f; done; fi",
            "if [ -d {$quotedBaseDir}/.templates ]; then for arch in x64 arm64; do ls -1td {$quotedBaseDir}/.templates/runner-\${arch}-* 2>/dev/null | tail -n +3 | xargs -r rm -rf; done; fi",
            "if [ -d {$quotedBaseDir}/.template ]; then for arch in x64 arm64; do ls -1td {$quotedBaseDir}/.template/runner-\${arch}-* 2>/dev/null | tail -n +3 | xargs -r rm -rf; done; fi",
        ];
    }
}
