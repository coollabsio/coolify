<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use App\Models\PrivateKey;

class PopulateSshKeysDirectorySeeder extends Seeder
{
    public function run()
    {
        Storage::disk('ssh-keys')->deleteDirectory('');
        Storage::disk('ssh-keys')->makeDirectory('');

        PrivateKey::chunk(100, function ($keys) {
            foreach ($keys as $key) {
                $key->storeInFileSystem();
            }
        });
    }
}
