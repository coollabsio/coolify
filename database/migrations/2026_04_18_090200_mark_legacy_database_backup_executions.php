<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scheduled_database_backup_executions', function (Blueprint $table) {
            $table->boolean('legacy')->default(false);
        });

        DB::table('scheduled_database_backup_executions')
            ->where(function ($query) {
                $query->where(function ($q) {
                    $q->where('filename', 'like', '%/pg-dump-%')
                        ->where('filename', 'not like', '%/pg-dump-all-%');
                })->orWhere(function ($q) {
                    $q->where('filename', 'like', '%/mysql-dump-%')
                        ->where('filename', 'not like', '%/mysql-dump-all-%');
                })->orWhere(function ($q) {
                    $q->where('filename', 'like', '%/mariadb-dump-%')
                        ->where('filename', 'not like', '%/mariadb-dump-all-%');
                })->orWhere(function ($q) {
                    $q->where('filename', 'like', '%/mongo-dump-%')
                        ->where('filename', 'not like', '%/mongo-dump-all-%');
                });
            })
            ->update(['legacy' => true]);
    }
};
