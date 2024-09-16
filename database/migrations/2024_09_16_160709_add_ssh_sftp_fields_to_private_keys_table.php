<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use App\Models\PrivateKey;

return new class extends Migration
{
    public function up()
    {
        Storage::disk('ssh-keys')->deleteDirectory('');
        Storage::disk('ssh-keys')->makeDirectory('');

        Schema::table('private_keys', function (Blueprint $table) {
            $table->boolean('is_server_ssh_key')->default(true);
            $table->boolean('is_sftp_key')->default(false);
        });

        PrivateKey::where('is_server_ssh_key', true)->chunk(100, function ($keys) {
            foreach ($keys as $key) {
                $key->storeInFileSystem();
            }
        });
    }

    public function down()
    {
        Schema::table('private_keys', function (Blueprint $table) {
            $table->dropColumn('is_server_ssh_key');
            $table->dropColumn('is_sftp_key');
        });
    }
};
