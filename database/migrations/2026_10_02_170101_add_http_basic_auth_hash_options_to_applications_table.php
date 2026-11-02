<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->string('http_basic_auth_hash_algorithm')->default('bcrypt');
            $table->unsignedTinyInteger('http_basic_auth_bcrypt_cost')->default(10);
            $table->unsignedInteger('http_basic_auth_argon2id_memory_cost')->default(65536);
            $table->unsignedTinyInteger('http_basic_auth_argon2id_time_cost')->default(4);
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn([
                'http_basic_auth_hash_algorithm',
                'http_basic_auth_bcrypt_cost',
                'http_basic_auth_argon2id_memory_cost',
                'http_basic_auth_argon2id_time_cost',
            ]);
        });
    }
};
