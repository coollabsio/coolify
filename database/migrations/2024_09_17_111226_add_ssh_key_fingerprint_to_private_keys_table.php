<?php

use App\Models\PrivateKey;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AddSshKeyFingerprintToPrivateKeysTable extends Migration
{
    public function up()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->string('fingerprint')->after('private_key')->nullable();
        });

        try {
            DB::table('private_keys')->chunkById(100, function ($keys) {
                foreach ($keys as $key) {
                    $fingerprint = PrivateKey::generateFingerprint($key->private_key);
                    if ($fingerprint) {
                        $key->fingerprint = $fingerprint;
                        $key->save();
                    }
                }
            });
        } catch (\Exception $e) {
            echo 'Generating fingerprints failed.';
            echo $e->getMessage();
        }
    }

    public function down()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });
    }
}
