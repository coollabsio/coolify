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
        echo "Starting ProductionSeeder...\n";

        if (isCloud()) {
            echo "  Running in cloud mode.\n";
        } else {
            echo "  Running in self-hosted mode.\n";
        }

        // Fix for 4.0.0-beta.37
        echo "Checking for beta.37 fix...\n";
        if (User::find(0) !== null && Team::find(0) !== null) {
            echo "  Found User 0 and Team 0\n";
            if (DB::table('team_user')->where('user_id', 0)->first() === null) {
                echo "  Creating team_user relationship\n";
                DB::table('team_user')->insert([
                    'user_id' => 0,
                    'team_id' => 0,
                    'role' => 'owner',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        echo "Checking InstanceSettings...\n";
        if (InstanceSettings::find(0) == null) {
            echo "  Creating InstanceSettings\n";
            InstanceSettings::create([
                'id' => 0,
            ]);
        }

        echo "Checking GithubApp...\n";
        if (GithubApp::find(0) == null) {
            echo "  Creating GithubApp\n";
            GithubApp::create([
                'id' => 0,
                'name' => 'Public GitHub',
                'api_url' => 'https://api.github.com',
                'html_url' => 'https://github.com',
                'is_public' => true,
                'team_id' => 0,
            ]);
        }

        echo "Checking GitlabApp...\n";
        if (GitlabApp::find(0) == null) {
            echo "  Creating GitlabApp\n";
            GitlabApp::create([
                'id' => 0,
                'name' => 'Public GitLab',
                'api_url' => 'https://gitlab.com/api/v4',
                'html_url' => 'https://gitlab.com',
                'is_public' => true,
                'team_id' => 0,
            ]);
        }

        // Add Coolify host (localhost) as Server if it doesn't exist
        if (! isCloud()) {
            echo "Setting up localhost server...\n";
            if (Server::find(0) == null) {
                echo "  Creating localhost server\n";
                $server_details = [
                    'id' => 0,
                    'name' => 'localhost',
                    'description' => "This is the server where Coolify is running on. Don't delete this!",
                    'user' => 'root',
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
                echo "  Updating existing localhost server\n";
                $server = Server::find(0);
                $server->settings->is_reachable = true;
                $server->settings->is_usable = true;
                $server->settings->save();
            }

            echo "Checking StandaloneDocker...\n";
            if (StandaloneDocker::find(0) == null) {
                echo "  Creating StandaloneDocker\n";
                StandaloneDocker::create([
                    'id' => 0,
                    'name' => 'localhost-coolify',
                    'network' => 'coolify',
                    'server_id' => 0,
                ]);
            }
        }

        if (! isCloud() && config('constants.coolify.is_windows_docker_desktop') == false) {
            echo "Setting up SSH keys for non-Windows environment...\n";
            $coolify_key_name = '@host.docker.internal';
            $ssh_keys_directory = Storage::disk('ssh-keys')->files();
            echo '  Found '.count($ssh_keys_directory)." SSH keys\n";
            $coolify_key = collect($ssh_keys_directory)->firstWhere(fn ($item) => str($item)->contains($coolify_key_name));

            $server = Server::find(0);
            $found = $server->privateKey;
            if (! $found) {
                if ($coolify_key) {
                    echo "  Found Coolify SSH key\n";
                    $user = str($coolify_key)->before('@')->after('id.');
                    $coolify_key = Storage::disk('ssh-keys')->get($coolify_key);
                    PrivateKey::create([
                        'id' => 0,
                        'team_id' => 0,
                        'name' => 'localhost\'s key',
                        'description' => 'The private key for the Coolify host machine (localhost).',
                        'private_key' => $coolify_key,
                    ]);
                    $server->update(['user' => $user]);
                    echo "SSH key found for the Coolify host machine (localhost).\n";
                } else {
                    echo "No SSH key found for the Coolify host machine (localhost).\n";
                    echo "Please read the following documentation (point 3) to fix it: https://coolify.io/docs/knowledge-base/server/openssh/\n";
                    echo "Your localhost connection won't work until then.";
                }
            }
        }

        if (config('constants.coolify.is_windows_docker_desktop')) {
            echo "Setting up Windows Docker Desktop environment...\n";
            echo "  Creating/updating private key\n";
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
                echo "  Creating Windows localhost server\n";
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
                echo "  Updating Windows localhost server\n";
                $server = Server::find(0);
                $server->settings->is_reachable = true;
                $server->settings->is_usable = true;
                $server->settings->save();
            }

            echo "Checking Windows StandaloneDocker...\n";
            if (StandaloneDocker::find(0) == null) {
                echo "  Creating Windows StandaloneDocker\n";
                StandaloneDocker::create([
                    'id' => 0,
                    'name' => 'localhost-coolify',
                    'network' => 'coolify',
                    'server_id' => 0,
                ]);
            }
        }

        echo "Getting public IPs...\n";
        get_public_ips();

        echo "Running additional seeders...\n";
        echo "  Running OauthSettingSeeder\n";
        $this->call(OauthSettingSeeder::class);
        echo "  Running PopulateSshKeysDirectorySeeder\n";
        $this->call(PopulateSshKeysDirectorySeeder::class);
        echo "  Running SentinelSeeder\n";
        $this->call(SentinelSeeder::class);
        echo "  Running RootUserSeeder\n";
        $this->call(RootUserSeeder::class);

        echo "ProductionSeeder complete!\n";
    }
}
