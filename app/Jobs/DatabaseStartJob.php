<?php

namespace App\Jobs;

use App\Actions\Database\StartClickhouse;
use App\Actions\Database\StartDragonfly;
use App\Actions\Database\StartInfluxdb;
use App\Actions\Database\StartKeydb;
use App\Actions\Database\StartMariadb;
use App\Actions\Database\StartMongodb;
use App\Actions\Database\StartMysql;
use App\Actions\Database\StartPostgresql;
use App\Actions\Database\StartRedis;
use App\Enums\ProcessStatus;
use App\Events\DatabaseStatusChanged;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneInfluxdb;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Spatie\Activitylog\Models\Activity;
use Throwable;

class DatabaseStartJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(
        public string $databaseClass,
        public int $databaseId,
        public int $teamId,
        public int $activityId,
        public ?int $userId,
    ) {
        $this->onQueue(deployment_queue());
    }

    public function handle(): void
    {
        $database = $this->databaseClass::query()->findOrFail($this->databaseId);
        abort_unless((int) $database->team()->id === $this->teamId, 403);
        $activity = Activity::query()->findOrFail($this->activityId);

        match ($database->getMorphClass()) {
            StandalonePostgresql::class => StartPostgresql::run($database, $activity),
            StandaloneRedis::class => StartRedis::run($database, $activity),
            StandaloneMongodb::class => StartMongodb::run($database, $activity),
            StandaloneMysql::class => StartMysql::run($database, $activity),
            StandaloneMariadb::class => StartMariadb::run($database, $activity),
            StandaloneKeydb::class => StartKeydb::run($database, $activity),
            StandaloneDragonfly::class => StartDragonfly::run($database, $activity),
            StandaloneClickhouse::class => StartClickhouse::run($database, $activity),
            StandaloneInfluxdb::class => StartInfluxdb::run($database, $activity),
        };

        event(new DatabaseStatusChanged($this->userId));
    }

    public function failed(?Throwable $exception): void
    {
        try {
            $activity = Activity::query()->find($this->activityId);
            if (! $activity) {
                return;
            }

            $activity->properties = $activity->properties->merge([
                'status' => ProcessStatus::ERROR->value,
                'error' => 'Database start failed.',
                'failed_at' => now()->toIso8601String(),
            ]);
            $activity->save();
        } finally {
            event(new DatabaseStatusChanged($this->userId));
        }
    }
}
