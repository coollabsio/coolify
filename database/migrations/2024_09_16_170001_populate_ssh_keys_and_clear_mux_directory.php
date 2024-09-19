<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Storage;
use App\Models\PrivateKey;

class PopulateSshKeysAndClearMuxDirectory extends Migration
{
    public function up()
    {
        Storage::disk('ssh-keys')->deleteDirectory('');
        Storage::disk('ssh-keys')->makeDirectory('');

        Storage::disk('ssh-mux')->deleteDirectory('');
        Storage::disk('ssh-mux')->makeDirectory('');

        PrivateKey::chunk(100, function ($keys) {
            foreach ($keys as $key) {
                $key->storeInFileSystem();
            }
        });
    }
}
