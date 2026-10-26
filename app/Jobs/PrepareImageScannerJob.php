<?php

namespace App\Jobs;

use App\Events\ImageScanUpdated;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Services\ImageScan\ScannerCommands;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Pulls the pinned scanner images on a server and updates the Grype database.
 *
 * The progress is kept in the cache (`image-scan-prepare:{server_id}`: pulling, updating_database, ready,
 * failed) and broadcast as ImageScanUpdated with the scan UUID `prepare`.
 */
class PrepareImageScannerJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const SCAN_UUID = 'prepare';

    public const STATE_PULLING = 'pulling';

    public const STATE_UPDATING_DATABASE = 'updating_database';

    public const STATE_READY = 'ready';

    public const STATE_FAILED = 'failed';

    public $timeout = 3600;

    public $tries = 1;

    private const TIMEOUT_PREPARE = 1900;

    private const TIMEOUT_DB_UPDATE = 400;

    private const TIMEOUT_DB_STATUS = 120;

    public function __construct(public Server $server)
    {
        $this->onQueue(maintenance_queue());
    }

    public static function stateCacheKey(Server $server): string
    {
        return "image-scan-prepare:{$server->id}";
    }

    public static function builtAtCacheKey(Server $server): string
    {
        return self::stateCacheKey($server).':built-at';
    }

    public static function failedStepCacheKey(Server $server): string
    {
        return self::stateCacheKey($server).':failed-step';
    }

    public static function forget(Server $server): void
    {
        Cache::forget(self::stateCacheKey($server));
        Cache::forget(self::failedStepCacheKey($server));
        Cache::forget(self::builtAtCacheKey($server));
    }

    public function handle(): void
    {
        $server = $this->server->fresh(['settings']);
        if (! $server instanceof Server || $server->isTransferredAway()) {
            return;
        }

        $settings = InstanceSettings::get();
        if (! $settings->is_image_scanning_allowed || ! $server->settings?->is_image_scan_enabled) {
            return;
        }

        try {
            $this->prepare($server, $settings->image_scan_db_url);
        } catch (\Throwable $e) {
            report($e);
            $this->setState($server, self::STATE_FAILED);
        }
    }

    private function prepare(Server $server, ?string $dbUrl): void
    {
        $commands = new ScannerCommands($server, Str::lower(Str::random(24)));

        $this->setState($server, self::STATE_PULLING);
        $pulled = $this->run($commands->prepare(), $server, self::TIMEOUT_PREPARE);
        foreach (['pull-syft', 'pull-grype', 'pull-regctl'] as $step) {
            if (($pulled['exit_codes'][$step] ?? null) !== 0) {
                $this->setState($server, self::STATE_FAILED);

                return;
            }
        }

        if (! $this->isStillEnabled($server)) {
            return;
        }

        $this->setState($server, self::STATE_UPDATING_DATABASE);
        $updated = $this->run($commands->dbUpdate(is_string($dbUrl) && trim($dbUrl) !== '' ? trim($dbUrl) : null), $server, self::TIMEOUT_DB_UPDATE);
        if (($updated['exit_codes']['db-update'] ?? null) !== 0) {
            $this->setState($server, self::STATE_FAILED);

            return;
        }

        $this->storeBuiltAt($server, $commands);
        $this->setState($server, self::STATE_READY);
    }

    /**
     * @param  list<string>  $commands
     * @return array{exit_codes: array<string, int>, sizes: array<string, int>, payload: string}
     */
    private function run(array $commands, Server $server, int $timeout): array
    {
        return ScannerCommands::parseOutput(instant_remote_process($commands, $server, throwError: false, timeout: $timeout));
    }

    private function storeBuiltAt(Server $server, ScannerCommands $commands): void
    {
        Cache::forget(self::builtAtCacheKey($server));

        try {
            $status = $this->run($commands->dbStatus(), $server, self::TIMEOUT_DB_STATUS);
            $report = ($status['exit_codes']['db-status'] ?? null) === 0 ? json_decode($status['payload'], true) : null;
            $built = is_array($report) && ($report['valid'] ?? false) === true ? ($report['built'] ?? null) : null;
            if (is_string($built)) {
                Cache::put(self::builtAtCacheKey($server), CarbonImmutable::parse($built)->toIso8601ZuluString(), now()->addDays(7));
            }
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function isStillEnabled(Server $server): bool
    {
        $enabled = (bool) $server->settings()->value('is_image_scan_enabled');
        if (! $enabled) {
            self::forget($server);
        }

        return $enabled;
    }

    private function setState(Server $server, string $state): void
    {
        $running = in_array($state, [self::STATE_PULLING, self::STATE_UPDATING_DATABASE], true);
        $previous = Cache::get(self::stateCacheKey($server));
        if ($state === self::STATE_FAILED && in_array($previous, [self::STATE_PULLING, self::STATE_UPDATING_DATABASE], true)) {
            Cache::put(self::failedStepCacheKey($server), $previous, now()->addDays(7));
        } elseif ($running) {
            Cache::forget(self::failedStepCacheKey($server));
        }
        Cache::put(self::stateCacheKey($server), $state, $running ? now()->addHour() : now()->addDays(7));
        ImageScanUpdated::dispatch($server->team_id, $server->uuid, self::SCAN_UUID);
    }
}
