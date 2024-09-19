<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

class EncryptExistingPrivateKeys extends Migration
{
    public function up()
    {
        DB::table('private_keys')->chunkById(100, function ($keys) {
            foreach ($keys as $key) {
                DB::table('private_keys')
                    ->where('id', $key->id)
                    ->update(['private_key' => Crypt::encryptString($key->private_key)]);
            }
        });
    }
}
