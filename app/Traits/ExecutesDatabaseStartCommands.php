<?php

namespace App\Traits;

use App\Jobs\ScanResourceImagesJob;
use App\Services\DatabaseStartCommandExecutor;
use App\Services\ImageScan\ImageStartPin;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Spatie\Activitylog\Models\Activity;

trait ExecutesDatabaseStartCommands
{
    private function executeDatabaseStartCommands(array $commands, Model $database, ?Activity $activity = null): Activity
    {
        if ($activity) {
            $activity = app(DatabaseStartCommandExecutor::class)->execute($commands, $database, $activity);
        } else {
            $activity = remote_process($commands, $database->destination->server, callEventOnFinish: 'DatabaseStatusChanged', queue: deployment_queue());
        }
        ScanResourceImagesJob::dispatchAfterStart($database);

        return $activity;
    }

    /**
     * The image pull of the start: the scanned build of a "Pull latest" security update when one waits for this
     * database (the compose file keeps the tag), else a pull of the configured image.
     *
     * @return list<string>
     */
    private function pullImageCommands(string $composeFile): array
    {
        $prefix = serverDockerConfigPrefix($this->database->destination->server);
        $pins = ImageStartPin::for(ImageStartPin::take($this->database), [(string) $this->database->image]);

        return $pins === []
            ? [$prefix."docker compose -f {$composeFile} pull"]
            : ImageStartPin::commands($pins, $prefix);
    }

    /**
     * Whether a `KEY=value` list already defines the exact key (not just a key or value containing it).
     *
     * @param  Collection<int, string>  $environmentVariables
     */
    private function hasEnvironmentVariable(Collection $environmentVariables, string $key): bool
    {
        return $environmentVariables->contains(fn ($variable) => str($variable)->before('=')->value() === $key);
    }

    /**
     * Adds the Coolify variables that applications and services also get. A key that the user
     * defines wins.
     *
     * @param  Collection<int, string>  $environmentVariables
     */
    private function addCoolifyEnvironmentVariables(Collection $environmentVariables): void
    {
        $definedKeys = $environmentVariables->map(fn ($variable) => str($variable)->before('=')->value());
        $coolifyVariables = collect([
            'COOLIFY_RESOURCE_UUID' => $this->database->uuid,
            'COOLIFY_CONTAINER_NAME' => $this->database->uuid,
        ])->except($definedKeys->all())->merge(coolifyDefaultEnvironmentVariables($this->database, $definedKeys));

        foreach ($coolifyVariables as $key => $value) {
            $environmentVariables->push($key.'='.escapeDollarSign($value));
        }
    }
}
