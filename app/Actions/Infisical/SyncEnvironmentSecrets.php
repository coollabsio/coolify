<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalClient;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncEnvironmentSecrets
{
    use AsAction;

    /**
     * Pull every secret for a binding into environment-scoped shared variables.
     *
     * Only rows carrying this binding's id are created, updated or deleted;
     * user-owned rows (null infisical_binding_id) are never touched. A key
     * that collides with an existing user-owned row in the same environment
     * is skipped and reported as "shadowed" rather than adopted or blocking
     * the rest of the sync.
     *
     * @return array{created: int, updated: int, deleted: int, skipped: array<int, string>, shadowed: array<int, string>}
     *
     * @throws InfisicalApiException
     */
    public function handle(InfisicalBinding $binding): array
    {
        $empty = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => [], 'shadowed' => []];

        if (! $binding->is_enabled) {
            return $empty;
        }

        $connection = $binding->connection;

        try {
            $secrets = (new InfisicalClient($connection))->fetchSecrets(
                $binding->infisical_project_id,
                $binding->infisical_environment_slug,
                $binding->secret_path,
            );
        } catch (InfisicalApiException $e) {
            $binding->forceFill([
                'last_sync_status' => InfisicalBinding::STATUS_FAILED,
                'last_sync_error' => $e->getMessage(),
            ])->save();

            throw $e;
        }

        return DB::transaction(function () use ($binding, $connection, $secrets, $empty): array {
            $result = $empty;
            $seen = [];

            foreach ($secrets as $key => $value) {
                try {
                    $validKey = ValidationPatterns::validatedEnvironmentVariableKey($key);
                } catch (InvalidArgumentException) {
                    $result['skipped'][] = $key;

                    continue;
                }

                $existing = SharedEnvironmentVariable::query()
                    ->where('infisical_binding_id', $binding->id)
                    ->where('key', $validKey)
                    ->first();

                if ($existing === null) {
                    $shadowedByUser = SharedEnvironmentVariable::query()
                        ->where('environment_id', $binding->environment_id)
                        ->where('key', $validKey)
                        ->whereNull('infisical_binding_id')
                        ->exists();

                    if ($shadowedByUser) {
                        $result['shadowed'][] = $validKey;

                        continue;
                    }

                    SharedEnvironmentVariable::create([
                        'key' => $validKey,
                        'value' => $value,
                        'type' => 'environment',
                        'team_id' => $connection->team_id,
                        'environment_id' => $binding->environment_id,
                        'infisical_binding_id' => $binding->id,
                    ]);
                    $result['created']++;
                    $seen[] = $validKey;

                    continue;
                }

                $seen[] = $validKey;

                if ($existing->value !== $value) {
                    $existing->update(['value' => $value]);
                    $result['updated']++;
                }
            }

            $result['deleted'] = SharedEnvironmentVariable::query()
                ->where('infisical_binding_id', $binding->id)
                ->whereNotIn('key', $seen)
                ->delete();

            $binding->forceFill([
                'last_synced_at' => now(),
                'last_sync_status' => InfisicalBinding::STATUS_SUCCESS,
                'last_sync_error' => null,
            ])->save();

            return $result;
        });
    }
}
