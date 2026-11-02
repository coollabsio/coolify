<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Custom HTML that the proxy shows (with status 503) for requests that no resource handles.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->text('proxy_error_page')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->dropColumn('proxy_error_page');
        });
    }
};
