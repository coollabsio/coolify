<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('managed_dns_record_references', function (Blueprint $table) {
            $table->id();
            $table->foreignId('managed_dns_record_id')->constrained()->cascadeOnDelete();
            $table->morphs('resource');
            $table->timestamps();
            $table->unique(['managed_dns_record_id', 'resource_type', 'resource_id'], 'managed_dns_record_references_unique');
        });

        DB::table('managed_dns_records')
            ->whereNotNull('resource_type')
            ->whereNotNull('resource_id')
            ->orderBy('id')
            ->chunkById(500, function ($records): void {
                DB::table('managed_dns_record_references')->insertOrIgnore($records->map(fn ($record): array => [
                    'managed_dns_record_id' => $record->id,
                    'resource_type' => $record->resource_type,
                    'resource_id' => $record->resource_id,
                    'created_at' => now(),
                    'updated_at' => now(),
                ])->all());
            });

        Schema::table('managed_dns_records', function (Blueprint $table) {
            $table->dropMorphs('resource');
        });
    }

    public function down(): void
    {
        Schema::table('managed_dns_records', function (Blueprint $table) {
            $table->nullableMorphs('resource');
        });

        DB::table('managed_dns_record_references')->orderBy('id')->chunkById(500, function ($references): void {
            foreach ($references as $reference) {
                DB::table('managed_dns_records')
                    ->where('id', $reference->managed_dns_record_id)
                    ->whereNull('resource_type')
                    ->update(['resource_type' => $reference->resource_type, 'resource_id' => $reference->resource_id]);
            }
        });

        Schema::dropIfExists('managed_dns_record_references');
    }
};
