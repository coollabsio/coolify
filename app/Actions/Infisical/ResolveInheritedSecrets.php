<?php

namespace App\Actions\Infisical;

use App\Models\InfisicalBinding;
use App\Models\SharedEnvironmentVariable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Lorisleiva\Actions\Concerns\AsAction;

class ResolveInheritedSecrets
{
    use AsAction;

    /**
     * Infisical-owned variables for a resource's environment, as key => value.
     *
     * Callers merge these underneath resource-level variables: a resource-level
     * variable of the same key always wins.
     *
     * @return Collection<string, string>
     */
    public function handle(Model $resource): Collection
    {
        $environmentId = $resource->environment_id ?? null;

        if ($environmentId === null) {
            return collect();
        }

        $binding = InfisicalBinding::query()
            ->where('environment_id', $environmentId)
            ->where('is_enabled', true)
            ->first();

        if ($binding === null) {
            return collect();
        }

        return SharedEnvironmentVariable::query()
            ->where('infisical_binding_id', $binding->id)
            ->get()
            ->mapWithKeys(fn (SharedEnvironmentVariable $variable) => [$variable->key => $variable->value]);
    }
}
