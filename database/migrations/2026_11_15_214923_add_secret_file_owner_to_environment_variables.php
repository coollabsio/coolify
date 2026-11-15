<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The owner and the mode of a secret file of a cluster application (decision 0008 amendment).
     * Null means the Sentinel default: root and 0444. The mode is the octal text that the user typed.
     */
    public function up(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->unsignedInteger('secret_file_uid')->nullable();
            $table->unsignedInteger('secret_file_gid')->nullable();
            $table->string('secret_file_mode', 4)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropColumn(['secret_file_uid', 'secret_file_gid', 'secret_file_mode']);
        });
    }
};
