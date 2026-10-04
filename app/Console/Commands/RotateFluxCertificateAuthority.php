<?php

namespace App\Console\Commands;

use App\Actions\Sentinel\RotateFluxCertificateAuthority as RotateAuthority;
use Illuminate\Console\Command;
use Throwable;

class RotateFluxCertificateAuthority extends Command
{
    protected $signature = 'flux:rotate-ca
        {--status : Show the rotation phase and per-Node trust bundle acknowledgements}
        {--continue : Run the next rotation step}
        {--force : With --continue, skip Nodes that have not acknowledged the trust bundle}
        {--cancel : Cancel a rotation before the switch to the new CA}';

    protected $description = 'Rotate the Flux CA in stages: distribute a dual-CA bundle, switch the Flux certificate, and retire the old CA';

    public function handle(): int
    {
        if (! config('constants.sentinel.host_enabled')) {
            $this->error('Host-native Sentinel is disabled.');

            return self::FAILURE;
        }

        $rotation = RotateAuthority::make();
        $actions = array_filter([$this->option('continue'), $this->option('cancel'), $this->option('status')]);
        if (count($actions) > 1) {
            $this->error('Use only one of --status, --continue, or --cancel.');

            return self::INVALID;
        }
        if ($this->option('force') && ! $this->option('continue')) {
            $this->error('--force requires --continue.');

            return self::INVALID;
        }

        try {
            if ($this->option('status')) {
                $this->printStatus($rotation->status());

                return self::SUCCESS;
            }
            if ($this->option('cancel')) {
                $rotation->cancel();
                $this->info('Flux CA rotation cancelled. Nodes receive a bundle with the old CA only.');
            } elseif ($this->option('continue')) {
                if ($this->option('force')) {
                    $this->warn('Forcing this step. Nodes that have not acknowledged the trust bundle may lose their Flux connection and need Repair trust over SSH.');
                }
                $result = $rotation->advance((bool) $this->option('force'));
                $this->info("Flux CA rotation is now {$result->status}.");
            } else {
                $result = $rotation->start();
                $this->info("Flux CA rotation started. Distributing trust bundle {$result->dual_bundle_version} with the old and new CA.");
            }
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->printStatus($rotation->status());

        return self::SUCCESS;
    }

    /**
     * @param  array{bundle_version: int, rotation: array<string, mixed>|null, next_step: string|null, nodes: list<array<string, mixed>>, blocking: int}  $status
     */
    private function printStatus(array $status): void
    {
        $rotation = $status['rotation'];
        $this->line("Current trust bundle version: {$status['bundle_version']}");
        if ($rotation === null) {
            $this->line('No Flux CA rotation has run.');
        } else {
            $this->line("Rotation {$rotation['uuid']}: {$rotation['status']}");
            $this->line("Target trust bundle version: {$rotation['target_bundle_version']}");
            if (filled($rotation['last_error'])) {
                $this->warn("Last error: {$rotation['last_error']}");
            }
        }
        if ($status['next_step'] !== null) {
            $this->line("Next step: {$status['next_step']} (run with --continue). Blocking Nodes: {$status['blocking']}");
        }

        $this->table(
            ['Node', 'Team', 'Usable', 'Connected', 'Bundle', 'Acknowledged', 'Error'],
            array_map(fn (array $node): array => [
                $node['name'],
                $node['team'] ?? '',
                $node['is_usable'] ? 'yes' : 'no',
                $node['connected'] ? 'yes' : 'no',
                $node['version'] ?? 'unknown',
                $node['acknowledged'] ? 'yes' : ($node['blocking'] ? 'no (blocking)' : 'no'),
                $node['error'] ?? '',
            ], $status['nodes']),
        );
    }
}
