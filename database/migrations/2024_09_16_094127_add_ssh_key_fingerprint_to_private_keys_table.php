<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddSshKeyFingerprintToPrivateKeysTable extends Migration
{
    public function up()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->string('fingerprint')->nullable()->unique()->after('private_key');
        });
    }

    public function down()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->dropColumn('fingerprint');
        });
    }
}
