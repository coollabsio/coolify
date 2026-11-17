<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The number of operations of a deploy notification group, stored before the first
     * operation is dispatched. The group sends only when that number of operations exists and
     * every one ended, so a server that finishes before the next operation exists does not send.
     */
    public function up(): void
    {
        Schema::table('node_operations', function (Blueprint $table) {
            $table->unsignedSmallInteger('notification_group_size')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('node_operations', function (Blueprint $table) {
            $table->dropColumn('notification_group_size');
        });
    }
};
