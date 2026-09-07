<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replaces the unindexable v5_servers.capabilities JSON array of magic strings
 * with indexed has_coold / is_ingress booleans. The Server model keeps
 * exposing a computed `capabilities` array so serialized payloads stay
 * identical.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->boolean('has_coold')->default(false)->index();
            $table->boolean('is_ingress')->default(false)->index();
        });

        DB::table('v5_servers')
            ->select(['id', 'capabilities'])
            ->orderBy('id')
            ->chunkById(100, function ($servers): void {
                foreach ($servers as $server) {
                    $capabilities = json_decode($server->capabilities ?? '[]', true);

                    if (! is_array($capabilities) || $capabilities === []) {
                        continue;
                    }

                    DB::table('v5_servers')->where('id', $server->id)->update([
                        'has_coold' => in_array('coold', $capabilities, true),
                        'is_ingress' => in_array('ingress', $capabilities, true),
                    ]);
                }
            });

        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn('capabilities');
        });
    }

    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->json('capabilities')->nullable();
        });

        DB::table('v5_servers')
            ->select(['id', 'has_coold', 'is_ingress'])
            ->orderBy('id')
            ->chunkById(100, function ($servers): void {
                foreach ($servers as $server) {
                    $capabilities = array_values(array_filter([
                        $server->has_coold ? 'coold' : null,
                        $server->is_ingress ? 'ingress' : null,
                    ]));

                    DB::table('v5_servers')->where('id', $server->id)->update([
                        'capabilities' => json_encode($capabilities),
                    ]);
                }
            });

        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropIndex(['has_coold']);
            $table->dropIndex(['is_ingress']);
            $table->dropColumn(['has_coold', 'is_ingress']);
        });
    }
};
