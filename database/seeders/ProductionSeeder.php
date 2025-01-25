<?php

namespace Database\Seeders;

use App\Data\ServerMetadata;
use App\Enums\ProxyStatus;
use App\Enums\ProxyTypes;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\InstanceSettings;
use App\Models\PrivateKey;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        $user = 'root';

        if (isCloud()) {
            echo "  Running in cloud mode.\n";
        } else {
            echo "  Running in self-hosted mode.\n";
        }

        if (User::find(0) !== null && Team::find(0) !== null) {
            if (DB::table('team_user')->where('user_id', 0)->first() === null) {
                DB::table('team_user')->insert([
                    'user_id' => 0,
                    'team_id' => 0,
                    'role' => 'owner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        if (InstanceSettings::find(0) == null) {
            InstanceSettings::create([
                'id' => 0,
            ]);
        }

        if (GithubApp::find(0) == null) {
            GithubApp::create([
                'id' => 0,
                'name' => 'Public GitHub',
                'api_url' => 'https://api.github.com',
                'html_url' => 'https://github.com',
                'is_public' => true,
                'team_id' => 0,
            ]);
        }

        if (GitlabApp::find(0) == null) {
            GitlabApp::create([
                'id' => 0,
                'name' => 'Public GitLab',
                'api_url' => 'https://gitlab.com/api/v4',
                'html_url' => 'https://gitlab.com',
                'is_public' => true,
                'team_id' => 0,
            ]);
        }

        if (! isCloud() && config('constants.coolify.is_windows_docker_desktop') == false) {
            $coolify_key_name = '@host.docker.internal';
            $ssh_keys_directory = Storage::disk('ssh-keys')->files();
            $coolify_key = collect($ssh_keys_directory)->firstWhere(fn ($item) => str($item)->contains($coolify_key_name));

            $private_key_found = PrivateKey::find(0);
            if (! $private_key_found) {
                if ($coolify_key) {
                    $user = str($coolify_key)->before('@')->after('id.');
                    $coolify_key = Storage::disk('ssh-keys')->get($coolify_key);
                    PrivateKey::create([
                        'id' => 0,
                        'team_id' => 0,
                        'name' => 'localhost\'s key',
                        'description' => 'The private key for the Coolify host machine (localhost).',
                        'private_key' => $coolify_key,
                    ]);
                    echo "SSH key found for the Coolify host machine (localhost).\n";
                } else {
                    echo "No SSH key found for the Coolify host machine (localhost).\n";
                    echo "Please read the following documentation (point 3) to fix it: https://coolify.
                io/docs/knowledge-base/server/openssh/\n";
                    echo "Your localhost connection won't work until then.";
                }
            }
        }

        if (! isCloud()) {
            if (Server::find(0) == null) {
                $server_details = [
                    'id' => 0,
                    'name' => 'localhost',
                    'description' => "This is the server where Coolify is running on. Don't delete this!",
                    'user' => $user,
                    'ip' => 'host.docker.internal',
                    'team_id' => 0,
                    'private_key_id' => 0,
                ];
                $server_details['proxy'] = ServerMetadata::from([
                    'type' => ProxyTypes::TRAEFIK->value,
                    'status' => ProxyStatus::EXITED->value,
                ]);
                $server = Server::create($server_details);
                $server->settings->is_reachable = true;
                $server->settings->is_usable = true;
                $server->settings->save();
            } else {
                $server = Server::find(0);
                $server->settings->is_reachable = true;
                $server->settings->is_usable = true;
                $server->settings->save();
            }

            if (StandaloneDocker::find(0) == null) {
                StandaloneDocker::create([
                    'id' => 0,
                    'name' => 'localhost-coolify',
                    'network' => 'coolify',
                    'server_id' => 0,
                ]);
            }
        }

        if (config('constants.coolify.is_windows_docker_desktop')) {
            PrivateKey::updateOrCreate(
                [
                    'id' => 0,
                    'team_id' => 0,
                ],
                [
                    'name' => 'Testing-host',
                    'description' => 'This is a a docker container with SSH access',
                    'private_key' => '-----BEGIN OPENSSH PRIVATE KEY-----
b3BlbnNzaC1rZXktdjEAAAAABG5vbmUAAAAEbm9uZQAAAAAAAAABAAAAMwAAAAtzc2gtZW
QyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevAAAAJi/QySHv0Mk
hwAAAAtzc2gtZWQyNTUxOQAAACBbhpqHhqv6aI67Mj9abM3DVbmcfYhZAhC7ca4d9UCevA
AAAECBQw4jg1WRT2IGHMncCiZhURCts2s24HoDS0thHnnRKVuGmoeGq/pojrsyP1pszcNV
uZx9iFkCELtxrh31QJ68AAAAEXNhaWxANzZmZjY2ZDJlMmRkAQIDBA==
-----END OPENSSH PRIVATE KEY-----
',
                ]
            );
            if (Server::find(0) == null) {
                $server_details = [
                    'id' => 0,
                    'uuid' => 'coolify-testing-host',
                    'name' => 'localhost',
                    'description' => "This is the server where Coolify is running on. Don't delete this!",
                    'user' => 'root',
                    'ip' => 'coolify-testing-host',
                    'team_id' => 0,
                    'private_key_id' => 0,
                ];
                $server_details['proxy'] = ServerMetadata::from([
                    'type' => ProxyTypes::TRAEFIK->value,
                    'status' => ProxyStatus::EXITED->value,
                ]);
                $server = Server::create($server_details);
                $server->settings->is_reachable = true;
                $server->settings->is_usable = true;
                $server->settings->save();
            } else {
                $server = Server::find(0);
                $server->settings->is_reachable = true;
                $server->settings->is_usable = true;
                $server->settings->save();
            }

            if (StandaloneDocker::find(0) == null) {
                StandaloneDocker::create([
                    'id' => 0,
                    'name' => 'localhost-coolify',
                    'network' => 'coolify',
                    'server_id' => 0,
                ]);
            }
        }

        get_public_ips();

        $this->call(OauthSettingSeeder::class);
        $this->call(PopulateSshKeysDirectorySeeder::class);
        $this->call(SentinelSeeder::class);
        $this->call(RootUserSeeder::class);
    }
}
