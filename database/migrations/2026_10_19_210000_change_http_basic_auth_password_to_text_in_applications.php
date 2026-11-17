<?php

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
        Schema::table('applications', function (Blueprint $table) {
            $table->text('http_basic_auth_password')->nullable()->default(null)->change();
        });
    }

    /**
     * The column stays text: encrypted passwords are longer than 255 characters, so Postgres would refuse the change back to varchar(255).
     */
    public function down(): void {}
};
