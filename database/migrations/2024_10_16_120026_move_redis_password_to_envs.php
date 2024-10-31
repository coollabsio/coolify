<?php

use App\Models\EnvironmentVariable;
use App\Models\StandaloneRedis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MoveRedisPasswordToEnvs extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            StandaloneRedis::chunkById(100, function ($redisInstances) {
                foreach ($redisInstances as $redis) {
                    $redis_password = DB::table('standalone_redis')->where('id', $redis->id)->value('redis_password');
                    EnvironmentVariable::create([
                        'standalone_redis_id' => $redis->id,
                        'key' => 'REDIS_PASSWORD',
                        'value' => $redis_password,
                    ]);
                    EnvironmentVariable::create([
                        'standalone_redis_id' => $redis->id,
                        'key' => 'REDIS_USERNAME',
                        'value' => 'default',
                    ]);
                }
            });
            Schema::table('standalone_redis', function (Blueprint $table) {
                $table->dropColumn('redis_password');
            });
        } catch (\Exception $e) {
            echo 'Moving Redis passwords to envs failed.';
            echo $e->getMessage();
        }
    }
}
