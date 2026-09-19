<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalClient;
use App\Support\ValidationPatterns;
use InvalidArgumentException;
use Lorisleiva\Actions\Concerns\AsAction;

class SyncEnvironmentSecrets
{
    use AsAction;

    /**
     * Pull every secret for a binding into environment-scoped shared variables.
     *
     * Only rows carrying this binding's id are created, updated or deleted;
     * user-owned rows (null infisical_binding_id) are never touched.
     *
     * @return array{created: int, updated: int, deleted: int, skipped: array<int, string>}
     *
     * @throws InfisicalApiException
     */
    public function handle(InfisicalBinding $binding): array
    {
        $empty = ['created' => 0, 'updated' => 0, 'deleted' => 0, 'skipped' => []];

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

        $result = $empty;
        $seen = [];

        foreach ($secrets as $key => $value) {
            try {
                $validKey = ValidationPatterns::validatedEnvironmentVariableKey($key);
            } catch (InvalidArgumentException) {
                $result['skipped'][] = $key;

                continue;
            }

            $seen[] = $validKey;

            $existing = SharedEnvironmentVariable::query()
                ->where('infisical_binding_id', $binding->id)
                ->where('key', $validKey)
                ->first();

            if ($existing === null) {
                SharedEnvironmentVariable::create([
                    'key' => $validKey,
                    'value' => $value,
                    'type' => 'environment',
                    'team_id' => $connection->team_id,
                    'environment_id' => $binding->environment_id,
                    'infisical_binding_id' => $binding->id,
                ]);
                $result['created']++;

                continue;
            }

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
    }
}
