<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptExistingRedisPasswords extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        try {
            DB::table('standalone_redis')->chunkById(100, function ($redisInstances) {
                foreach ($redisInstances as $redis) {
                    DB::table('standalone_redis')
                        ->where('id', $redis->id)
                        ->update(['redis_password' => Crypt::encryptString($redis->redis_password)]);
                }
            });
        } catch (\Exception $e) {
            echo 'Encrypting Redis passwords failed.';
            echo $e->getMessage();
        }
    }
}
