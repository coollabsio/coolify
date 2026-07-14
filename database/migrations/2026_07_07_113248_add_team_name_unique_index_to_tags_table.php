<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if ($this->indexExists()) {
            return;
        }

        DB::table('tags')
            ->select('team_id', 'name', DB::raw('MIN(id) as keep_id'), DB::raw('COUNT(*) as tag_count'))
            ->whereNotNull('team_id')
            ->groupBy('team_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('keep_id')
            ->cursor()
            ->each(function ($duplicate): void {
                DB::table('tags')
                    ->select('id')
                    ->where('team_id', $duplicate->team_id)
                    ->where('name', $duplicate->name)
                    ->where('id', '!=', $duplicate->keep_id)
                    ->orderBy('id')
                    ->cursor()
                    ->each(function ($duplicateTag) use ($duplicate): void {
                        DB::table('taggables')
                            ->where('tag_id', $duplicateTag->id)
                            ->orderBy('taggable_id')
                            ->cursor()
                            ->each(function ($taggable) use ($duplicate): void {
                                DB::table('taggables')->updateOrInsert([
                                    'tag_id' => $duplicate->keep_id,
                                    'taggable_id' => $taggable->taggable_id,
                                    'taggable_type' => $taggable->taggable_type,
                                ]);
                            });

                        DB::table('taggables')->where('tag_id', $duplicateTag->id)->delete();
                        DB::table('tags')->where('id', $duplicateTag->id)->delete();
                    });
            });

        Schema::table('tags', function (Blueprint $table) {
            $table->unique(['team_id', 'name'], 'tags_team_id_name_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! $this->indexExists()) {
            return;
        }

        Schema::table('tags', function (Blueprint $table) {
            $table->dropUnique('tags_team_id_name_unique');
        });
    }

    private function indexExists(): bool
    {
        return collect(Schema::getIndexes('tags'))
            ->contains(fn (array $index): bool => $index['name'] === 'tags_team_id_name_unique');
    }
};
