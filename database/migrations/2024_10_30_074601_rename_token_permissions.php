<?php

use App\Models\PersonalAccessToken;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $tokens = PersonalAccessToken::all();
        foreach ($tokens as $token) {
            $abilities = collect();
            if (in_array('*', $token->abilities)) $abilities->push('write', 'read', 'read:sensitive');
            if (in_array('read-only', $token->abilities)) $abilities->push('read');
            if (in_array('view:sensitive', $token->abilities)) $abilities->push('read', 'read:sensitive');
            $token->abilities = $abilities->unique()->values()->all();
            $token->save();
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tokens = PersonalAccessToken::all();
        foreach ($tokens as $token) {
            $abilities = collect();
            if (in_array('write', $token->abilities)) {
                $abilities->push('*');
            } else {
                if (in_array('read', $token->abilities)) $abilities->push('read-only');
                if (in_array('read:sensitive', $token->abilities)) $abilities->push('view:sensitive');
            }
            $token->abilities = $abilities->unique()->values()->all();
            $token->save();
        }
    }
};
