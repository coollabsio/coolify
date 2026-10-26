<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('team_user', 'access')) {
            Schema::table('team_user', function (Blueprint $table) {
                $table->json('access')->nullable();
            });
        }

        if (! Schema::hasColumn('personal_access_tokens', 'scopes')) {
            Schema::table('personal_access_tokens', function (Blueprint $table) {
                $table->json('scopes')->nullable();
            });
        }

        // Delete becomes its own ability. Tokens that could write could delete, so they keep that.
        DB::table('personal_access_tokens')->orderBy('id')->chunkById(500, function ($tokens) {
            foreach ($tokens as $token) {
                $abilities = json_decode($token->abilities ?? '[]', true);
                if (! is_array($abilities) || ! in_array('write', $abilities, true) || in_array('delete', $abilities, true)) {
                    continue;
                }
                $abilities[] = 'delete';
                DB::table('personal_access_tokens')->where('id', $token->id)->update(['abilities' => json_encode($abilities)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('team_user', function (Blueprint $table) {
            $table->dropColumn('access');
        });
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn('scopes');
        });
    }
};
