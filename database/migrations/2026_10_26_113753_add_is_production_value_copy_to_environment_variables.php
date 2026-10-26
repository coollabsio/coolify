<?php

use App\Models\Application;
use App\Models\EnvironmentVariable;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->boolean('is_production_value_copy')->default(false);
        });

        // Mark the preview variables that still have the production value, so they stay copies when production changes.
        EnvironmentVariable::query()
            ->where('resourceable_type', Application::class)
            ->where('is_preview', true)
            ->chunkById(500, function (Collection $previews) {
                $productionValues = EnvironmentVariable::query()
                    ->where('resourceable_type', Application::class)
                    ->where('is_preview', false)
                    ->whereIn('resourceable_id', $previews->pluck('resourceable_id')->unique())
                    ->whereIn('key', $previews->pluck('key')->unique())
                    ->get()
                    ->mapWithKeys(fn (EnvironmentVariable $env) => [$env->resourceable_id.'|'.$env->key => $this->valueOf($env)]);

                $copyIds = $previews
                    ->filter(function (EnvironmentVariable $env) use ($productionValues) {
                        $value = $this->valueOf($env);

                        return filled($value) && $productionValues->get($env->resourceable_id.'|'.$env->key) === $value;
                    })
                    ->pluck('id');

                if ($copyIds->isNotEmpty()) {
                    EnvironmentVariable::query()->whereIn('id', $copyIds)->toBase()->update(['is_production_value_copy' => true]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn('is_production_value_copy');
        });
    }

    private function valueOf(EnvironmentVariable $env): ?string
    {
        try {
            return $env->value;
        } catch (DecryptException) {
            return null;
        }
    }
};
