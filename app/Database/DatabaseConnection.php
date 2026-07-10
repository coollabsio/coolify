<?php

namespace App\Database;

use Illuminate\Support\Facades\DB;

class DatabaseConnection
{
    public static function connect($type)
    {
        if ($type === 'surrealdb') {
            DB::purge('surrealdb');
            DB::connection('surrealdb')->reconnect();
        }
    }

    public static function disconnect($type)
    {
        if ($type === 'surrealdb') {
            DB::purge('surrealdb');
        }
    }
}
