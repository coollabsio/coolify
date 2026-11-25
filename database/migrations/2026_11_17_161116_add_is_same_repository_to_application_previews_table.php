<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A webhook or the pull request list sets the column when the branch of the pull request is in the
     * repository of the application. Only such a preview deploys the Compose file of its pull request.
     */
    public function up(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->boolean('is_same_repository')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('application_previews', function (Blueprint $table) {
            $table->dropColumn('is_same_repository');
        });
    }
};
