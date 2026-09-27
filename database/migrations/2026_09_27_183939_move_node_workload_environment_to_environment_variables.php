<?php

use App\Models\EnvironmentVariable;
use App\Models\NodeWorkload;
use Illuminate\Database\Migrations\Migration;

/**
 * Cluster application variables are now edited with the shared environment
 * variable UI. Copy the variables of each latest revision so the next
 * deployment keeps them.
 */
return new class extends Migration
{
    public function up(): void
    {
        NodeWorkload::query()->with(['revisions' => fn ($query) => $query->latest('id')->limit(1)])->chunkById(100, function ($workloads): void {
            foreach ($workloads as $workload) {
                if ($workload->environment_variables()->exists()) {
                    continue;
                }
                $order = 0;
                foreach ($workload->revisions->first()?->environment ?? [] as $key => $value) {
                    EnvironmentVariable::query()->create([
                        'key' => $key,
                        'value' => $value,
                        'is_runtime' => true,
                        'is_buildtime' => false,
                        'is_preview' => false,
                        'order' => ++$order,
                        'resourceable_type' => NodeWorkload::class,
                        'resourceable_id' => $workload->id,
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        EnvironmentVariable::query()->where('resourceable_type', NodeWorkload::class)->delete();
    }
};
