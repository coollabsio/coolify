<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Before v4.7.0, parallel Compose parses and Developer view saves could add a second variable with
     * the key of an existing one, often without a value. The deployment writes both to the .env file and
     * Docker Compose uses the last one, so an empty copy can replace the saved value and fail the
     * deployment. New copies are refused since then; this removes the copies that are left.
     * Of each key of a resource and preview flag, keep the most recently changed variable that has a
     * value, else the most recently changed one.
     */
    public function up(): void
    {
        $duplicates = DB::table('environment_variables')
            ->whereNotNull('resourceable_type')
            ->whereNotNull('resourceable_id')
            ->select('resourceable_type', 'resourceable_id', 'is_preview', 'key')
            ->groupBy('resourceable_type', 'resourceable_id', 'is_preview', 'key')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $variables = DB::table('environment_variables')
                ->where('resourceable_type', $duplicate->resourceable_type)
                ->where('resourceable_id', $duplicate->resourceable_id)
                ->where('is_preview', $duplicate->is_preview)
                ->where('key', $duplicate->key)
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->get(['id', 'value']);

            $kept = $variables->first(fn (object $variable): bool => $this->hasValue($variable->value)) ?? $variables->first();

            DB::table('environment_variables')
                ->whereIn('id', $variables->pluck('id')->reject(fn (int $id): bool => $id === $kept->id)->all())
                ->delete();
        }
    }

    private function hasValue(?string $encryptedValue): bool
    {
        if ($encryptedValue === null || $encryptedValue === '') {
            return false;
        }

        try {
            return trim((string) decrypt($encryptedValue)) !== '';
        } catch (Throwable) {
            // A value that this APP_KEY cannot read is not known to be empty.
            return true;
        }
    }

    public function down(): void
    {
        //
    }
};
