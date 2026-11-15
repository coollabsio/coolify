<?php

use App\Support\CpuArchitecture;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The CPU architecture of the builder Node of a build (decision 0020), in OCI names such as `amd64`.
     * A revision with a built image runs only on Nodes of this architecture. Null for a
     * builder of an unknown architecture: its image fits every Node. Earlier builds get the architecture
     * of their builder Node, which a build cannot change.
     */
    public function up(): void
    {
        Schema::table('node_builds', function (Blueprint $table) {
            $table->string('architecture', 32)->nullable();
        });
        DB::table('node_builds')->whereNotNull('node_id')->select('node_id')->distinct()->pluck('node_id')->each(function ($nodeId): void {
            $metadata = json_decode((string) DB::table('nodes')->where('id', $nodeId)->value('metadata'), true);
            $architecture = CpuArchitecture::normalize(is_array($metadata) ? ($metadata['arch'] ?? null) : null);
            if ($architecture !== null) {
                DB::table('node_builds')->where('node_id', $nodeId)->update(['architecture' => $architecture]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('node_builds', function (Blueprint $table) {
            $table->dropColumn('architecture');
        });
    }
};
