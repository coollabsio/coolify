<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

class EncryptExistingPrivateKeys extends Migration
{
    public function up()
    {
        try {
            DB::table('private_keys')->chunkById(100, function ($keys) {
                foreach ($keys as $key) {
                    DB::table('private_keys')
                        ->where('id', $key->id)
                        ->update(['private_key' => Crypt::encryptString($key->private_key)]);
                }
            });
        } catch (\Exception $e) {
            echo 'Encrypting private keys failed.';
            echo $e->getMessage();
        }
    }
}
