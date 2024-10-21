<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class MoveRedisPasswordToEnvs extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            DB::table('standalone_redis')->chunkById(100, function ($redisInstances) {
                foreach ($redisInstances as $redis) {
                    $redis->runtime_environment_variables()->firstOrCreate([
                        'key' => 'REDIS_PASSWORD',
                        'value' => $redis->redis_password,
                    ]);
                }
            });
            DB::statement('ALTER TABLE standalone_redis DROP COLUMN redis_password');
        } catch (\Exception $e) {
            echo 'Moving Redis passwords to envs failed.';
            echo $e->getMessage();
        }
    }
}
