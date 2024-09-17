<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\PrivateKey;

class AddSshKeyFingerprintToPrivateKeysTable extends Migration
{
    public function up()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->string('fingerprint')->after('private_key')->unique();
        });

        PrivateKey::whereNull('fingerprint')->each(function ($key) {
            $fingerprint = PrivateKey::generateFingerprint($key->private_key);
            if ($fingerprint) {
                $key->fingerprint = $fingerprint;
                $key->save();
            }
        });
    }

    public function down()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });
    }
}
