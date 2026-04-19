<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('scheduled_database_backups')
            ->where('dump_all', false)
            ->where(function ($query) {
                $query->whereNull('databases_to_backup')
                    ->orWhere('databases_to_backup', '');
            })
            ->update(['dump_all' => true]);
    }
};
