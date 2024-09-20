<?php

namespace Database\Seeders;

use App\Models\PrivateKey;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class PopulateSshKeysDirectorySeeder extends Seeder
{
    public function run()
    {
        Storage::disk('ssh-keys')->deleteDirectory('');
        Storage::disk('ssh-keys')->makeDirectory('');
        Storage::disk('ssh-mux')->deleteDirectory('');
        Storage::disk('ssh-mux')->makeDirectory('');

        PrivateKey::chunk(100, function ($keys) {
            foreach ($keys as $key) {
                echo 'Storing key: '.$key->name."\n";
                $key->storeInFileSystem();
            }
        });

        Process::run('chown -R 9999:9999 '.storage_path('app/ssh/keys'));
        Process::run('chown -R 9999:9999 '.storage_path('app/ssh/mux'));
    }
}
