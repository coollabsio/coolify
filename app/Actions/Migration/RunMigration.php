<?php

namespace App\Actions\Migration;

use App\Services\Migration\MigrationApiClient;
use Illuminate\Console\Command;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\warning;

class RunMigration
{
    use AsAction;

    public string $commandSignature = 'coolify:migrate
                            {--source-url= : Source Coolify URL}
                            {--source-token= : Source API token}
                            {--target-url= : Target Coolify URL}
                            {--target-token= : Target API token}
                            {--storage=s3 : Storage driver (s3, local-ssh, azure, gcs)}
                            {--storage-endpoint= : S3-compatible endpoint}
                            {--storage-bucket= : Bucket or container name}
                            {--storage-region= : Storage region}
                            {--storage-key= : Storage access key}
                            {--storage-secret= : Storage secret}
                            {--s3-storage-uuid= : Existing team S3 storage UUID}
                            {--destination= : Target destination UUID}
                            {--project= : Target project UUID}
                            {--environment= : Target environment UUID}
                            {--resources= : Comma-separated source resource UUIDs}
                            {--skip-data : Export and import metadata only}
                            {--dry-run : Discover and validate without migrating}';

    public string $commandDescription = 'Migrate resources from one Coolify instance to another.';

    public function handle(
        string $sourceUrl,
        string $sourceToken,
        string $targetUrl,
        string $targetToken,
        array $options = [],
        ?Command $command = null,
    ): int {
        $source = new MigrationApiClient($sourceUrl, $sourceToken);
        $target = new MigrationApiClient($targetUrl, $targetToken);
        $interactive = ! (bool) ($options['no_interaction'] ?? false);

        $this->preflight($source, $target, $command);

        $resources = $source->get('migrations/resources');
        $selected = $this->selectResources($resources, $options['resources'] ?? null, $interactive);
        if ($selected === []) {
            $command?->error('No resources selected.');

            return self::failure();
        }

        $this->printSelection($selected, $command);

        if ((bool) ($options['dry_run'] ?? false)) {
            info('Dry run complete. No resources were exported or imported.');

            return self::success();
        }

        $export = $source->post('migrations/export', [
            'resource_uuids' => array_column($selected, 'uuid'),
            'skip_data' => (bool) ($options['skip_data'] ?? false),
            'storage' => $this->storagePayload($options),
        ]);

        $export = $this->poll($source, (string) $export['uuid'], $command, 'Export');
        if (($export['status'] ?? null) === 'failed') {
            error('Export failed: '.($export['error'] ?? 'unknown error'));

            return self::failure();
        }

        $manifest = $export['manifest'] ?? null;
        if (! is_array($manifest)) {
            throw new RuntimeException('Export completed without a manifest. The source token needs read:sensitive.');
        }

        $import = $target->post('migrations/import', [
            'manifest' => $manifest,
            'destination_uuid' => $options['destination'] ?? null,
            'project_uuid' => $options['project'] ?? null,
            'environment_uuid' => $options['environment'] ?? null,
            'skip_data' => (bool) ($options['skip_data'] ?? false),
            'storage' => $this->storagePayload($options),
        ]);

        $import = $this->poll($target, (string) $import['uuid'], $command, 'Import');
        $this->report($import, $command);

        if (($import['status'] ?? null) === 'completed') {
            $source->post('migrations/'.$export['uuid'].'/cleanup');
            $target->post('migrations/'.$import['uuid'].'/cleanup');
        } else {
            warning('Staging archives were kept so you can retry the failed resources.');
        }

        return ($import['status'] ?? null) === 'failed' ? self::failure() : self::success();
    }

    public function asCommand(Command $command): int
    {
        $sourceUrl = (string) $command->option('source-url');
        $sourceToken = (string) $command->option('source-token');
        $targetUrl = (string) $command->option('target-url');
        $targetToken = (string) $command->option('target-token');

        if ($sourceUrl === '' || $sourceToken === '' || $targetUrl === '' || $targetToken === '') {
            $command->error('source-url, source-token, target-url, and target-token are required.');

            return self::failure();
        }

        if (! $command->option('dry-run') && blank($command->option('destination'))) {
            $command->error('The --destination option is required unless --dry-run is set.');

            return self::failure();
        }

        if (! $command->option('dry-run') && blank($command->option('project'))) {
            $command->error('The --project option is required unless --dry-run is set.');

            return self::failure();
        }

        try {
            return $this->handle(
                $sourceUrl,
                $sourceToken,
                $targetUrl,
                $targetToken,
                [
                    'storage' => $command->option('storage'),
                    'storage-endpoint' => $command->option('storage-endpoint'),
                    'storage-bucket' => $command->option('storage-bucket'),
                    'storage-region' => $command->option('storage-region'),
                    'storage-key' => $command->option('storage-key'),
                    'storage-secret' => $command->option('storage-secret'),
                    's3-storage-uuid' => $command->option('s3-storage-uuid'),
                    'destination' => $command->option('destination'),
                    'project' => $command->option('project'),
                    'environment' => $command->option('environment'),
                    'resources' => $command->option('resources'),
                    'skip_data' => (bool) $command->option('skip-data'),
                    'dry_run' => (bool) $command->option('dry-run'),
                    'no_interaction' => (bool) $command->option('no-interaction'),
                ],
                $command,
            );
        } catch (Throwable $exception) {
            $command->error($exception->getMessage());

            return self::failure();
        }
    }

    private function preflight(MigrationApiClient $source, MigrationApiClient $target, ?Command $command): void
    {
        foreach (['source' => $source, 'target' => $target] as $label => $client) {
            $result = $client->get('migrations/preflight');
            if (! ($result['token_can_write'] ?? false)) {
                throw new RuntimeException("The {$label} token is missing the write ability.");
            }
            if ($label === 'source' && ! ($result['token_can_read_sensitive'] ?? false)) {
                throw new RuntimeException('The source token is missing the read:sensitive ability.');
            }
            $command?->info(ucfirst($label).' Coolify v'.($result['version'] ?? 'unknown').' is ready.');
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $resources
     * @return list<array<string, mixed>>
     */
    private function selectResources(array $resources, ?string $requested, bool $interactive): array
    {
        $resources = array_values(array_filter(
            $resources,
            fn (mixed $resource): bool => is_array($resource) && isset($resource['uuid']),
        ));
        if (is_string($requested) && $requested !== '') {
            $uuids = array_filter(array_map('trim', explode(',', $requested)));

            return array_values(array_filter(
                $resources,
                fn (array $resource): bool => in_array($resource['uuid'] ?? null, $uuids, true),
            ));
        }

        if (! $interactive || $resources === []) {
            return $resources;
        }

        $labels = [];
        foreach ($resources as $resource) {
            $labels[$resource['uuid']] = sprintf(
                '%s [%s] (%s)',
                $resource['name'] ?? $resource['uuid'],
                $resource['type'] ?? 'unknown',
                $resource['uuid'],
            );
        }

        $chosen = multiselect(
            label: 'Select resources to migrate',
            options: $labels,
            required: true,
        );

        return array_values(array_filter(
            $resources,
            fn (array $resource): bool => in_array($resource['uuid'] ?? null, $chosen, true),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $selected
     */
    private function printSelection(array $selected, ?Command $command): void
    {
        foreach ($selected as $resource) {
            $warnings = $resource['warnings'] ?? [];
            $command?->line(sprintf(
                '- %s (%s) %s',
                $resource['name'] ?? $resource['uuid'],
                $resource['type'] ?? 'unknown',
                $warnings === [] ? '' : 'warnings: '.implode('; ', $warnings),
            ));
        }
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    private function storagePayload(array $options): array
    {
        return [
            'driver' => $options['storage'] ?? 's3',
            'config' => array_filter([
                'endpoint' => $options['storage-endpoint'] ?? null,
                'bucket' => $options['storage-bucket'] ?? null,
                'region' => $options['storage-region'] ?? null,
                'key' => $options['storage-key'] ?? null,
                'secret' => $options['storage-secret'] ?? null,
                's3_storage_uuid' => $options['s3-storage-uuid'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function poll(MigrationApiClient $client, string $uuid, ?Command $command, string $phase): array
    {
        $attempts = app()->environment('testing') ? 3 : 120;
        $sleep = app()->environment('testing') ? 0 : 5;
        $status = 'pending';
        $payload = [];

        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $payload = $client->get('migrations/'.$uuid);
            $status = (string) ($payload['status'] ?? 'pending');
            $command?->info("{$phase} status: {$status}");
            if (in_array($status, ['completed', 'partial', 'failed'], true)) {
                return $payload;
            }
            if ($sleep > 0) {
                sleep($sleep);
            }
        }

        throw new RuntimeException("Timed out waiting for {$phase} to finish.");
    }

    /**
     * @param  array<string, mixed>  $import
     */
    private function report(array $import, ?Command $command): void
    {
        $migrated = 0;
        $failed = 0;
        $skipped = 0;

        foreach ($import['items'] ?? [] as $item) {
            $status = $item['status'] ?? 'unknown';
            match ($status) {
                'healthy' => $migrated++,
                'skipped' => $skipped++,
                default => $failed++,
            };
            $command?->line(sprintf(
                '%s: %s%s',
                $item['name'] ?? $item['source_uuid'],
                $status,
                filled($item['error'] ?? null) ? ' ('.$item['error'].')' : '',
            ));
        }

        info("Migrated: {$migrated}. Failed: {$failed}. Skipped: {$skipped}.");
    }

    private static function success(): int
    {
        return Command::SUCCESS;
    }

    private static function failure(): int
    {
        return Command::FAILURE;
    }
}
