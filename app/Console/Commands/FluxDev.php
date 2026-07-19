<?php

namespace App\Console\Commands;

use App\Services\Flux\AgentTokenIssuer;
use App\Support\V5\V5Feature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FluxDev extends Command
{
    protected $signature = 'flux:dev
        {host_id=coold-dev : Stable coold host id}
        {--caps= : Comma-separated host capabilities}
        {--ttl=3600 : Token lifetime in seconds}
        {--output= : Optional path to write the token with 0600 permissions}';

    protected $description = 'Run Flux development helpers.';

    /**
     * @return array<int, string>
     */
    private function defaultCapabilities(): array
    {
        return [
            'host-agent:dev',
        ];
    }

    public function handle(AgentTokenIssuer $agentTokenIssuer): int
    {
        if (! V5Feature::enabled()) {
            $this->error('V5 is only available in development environments.');

            return self::FAILURE;
        }

        $hostId = (string) $this->argument('host_id');
        $ttl = max(60, (int) $this->option('ttl'));
        $caps = collect(explode(',', (string) $this->option('caps')))
            ->map(fn (string $cap) => trim($cap))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($caps === []) {
            $caps = $this->defaultCapabilities();
        }

        try {
            $token = $agentTokenIssuer->issue($hostId, $caps, $ttl);
        } catch (\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            $outputPath = Str::startsWith($output, '/') ? $output : base_path($output);
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $token.PHP_EOL);
            chmod($outputPath, 0600);
            $this->info("Host JWT written to {$outputPath}.");

            return self::SUCCESS;
        }

        $this->line($token);

        return self::SUCCESS;
    }
}
