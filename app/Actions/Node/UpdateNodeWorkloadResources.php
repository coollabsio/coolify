<?php

namespace App\Actions\Node;

use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

class UpdateNodeWorkloadResources
{
    use AsAction;

    /**
     * The resources from the form or the API: CPUs and MB, empty for no limit. A reservation must
     * not be greater than its limit.
     *
     * @param  array{cpu_limit?: mixed, cpu_reservation?: mixed, memory_limit_mb?: mixed, memory_reservation_mb?: mixed}  $input
     * @return array{cpu_limit: ?float, cpu_reservation: ?float, memory_limit_bytes: ?int, memory_reservation_bytes: ?int}
     *
     * @throws ValidationException
     */
    public static function fromInput(array $input): array
    {
        $validated = Validator::make($input, [
            'cpu_limit' => ['nullable', 'numeric', 'between:0.01,1024'],
            'cpu_reservation' => ['nullable', 'numeric', 'between:0.01,1024'],
            'memory_limit_mb' => ['nullable', 'integer', 'between:4,1048576'],
            'memory_reservation_mb' => ['nullable', 'integer', 'between:4,1048576'],
        ])->validate();
        $value = fn (string $key): mixed => filled($validated[$key] ?? null) ? $validated[$key] : null;
        $errors = [];
        if ($value('cpu_limit') !== null && $value('cpu_reservation') !== null && (float) $value('cpu_reservation') > (float) $value('cpu_limit')) {
            $errors['cpu_reservation'] = 'CPU reservation cannot be greater than the CPU limit.';
        }
        if ($value('memory_limit_mb') !== null && $value('memory_reservation_mb') !== null && (int) $value('memory_reservation_mb') > (int) $value('memory_limit_mb')) {
            $errors['memory_reservation_mb'] = 'Memory reservation cannot be greater than the memory limit.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return [
            'cpu_limit' => $value('cpu_limit') === null ? null : (float) $value('cpu_limit'),
            'cpu_reservation' => $value('cpu_reservation') === null ? null : (float) $value('cpu_reservation'),
            'memory_limit_bytes' => $value('memory_limit_mb') === null ? null : (int) $value('memory_limit_mb') * 1_048_576,
            'memory_reservation_bytes' => $value('memory_reservation_mb') === null ? null : (int) $value('memory_reservation_mb') * 1_048_576,
        ];
    }

    /**
     * @param  array{cpu_limit: ?float, cpu_reservation: ?float, memory_limit_bytes: ?int, memory_reservation_bytes: ?int}  $resources
     */
    public function handle(NodeWorkload $workload, array $resources): NodeWorkloadRevision
    {
        return DB::transaction(function () use ($workload, $resources): NodeWorkloadRevision {
            $workload = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
            $current = $workload->revisions()->latest('id')->lockForUpdate()->firstOrFail();
            $resources = array_filter($resources, fn ($value): bool => $value !== null);
            $configuration = $current->configuration ?? [];
            $currentResources = $configuration['resources'] ?? [];
            if ($currentResources === $resources) {
                return $current;
            }
            if ($resources === []) {
                unset($configuration['resources']);
            } else {
                $configuration['resources'] = $resources;
            }

            return $workload->createRevision($current->image, $configuration, $current->environment ?? [], $current->secrets ?? []);
        });
    }
}
