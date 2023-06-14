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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;

class ProductionSeeder extends Seeder
{
    public function run(): void
    {
        if (InstanceSettings::find(0) == null) {
            InstanceSettings::create([
                'id' => 0
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

        // Save SSH Keys for the Coolify Host
        $coolify_key_name = "id.root@host.docker.internal";
        $coolify_key = Storage::disk('ssh-keys')->get("{$coolify_key_name}");

        if ($coolify_key) {
            $private_key = PrivateKey::find(0);
            if ($private_key == null) {
                PrivateKey::create([
                    'id' => 0,
                    'name' => 'localhost\'s key',
                    'description' => 'The private key for the Coolify host machine (localhost).',
                    'private_key' => $coolify_key,
                    'team_id' => 0,
                ]);
            } else {
                $private_key->private_key = $coolify_key;
                $private_key->save();
            }
        } else {
            // TODO: Add a command to generate a new SSH key for the Coolify host machine (localhost).
            echo "No SSH key found for the Coolify host machine (localhost).\n";
            echo "Please generate one and save it in storage/app/ssh/keys/{$coolify_key_name}\n";
        }

        // Add Coolify host (localhost) as Server if it doesn't exist
        if (Server::find(0) == null) {
            $server_details = [
                'id' => 0,
                'name' => "localhost",
                'description' => "This is the server where Coolify is running on. Don't delete this!",
                'user' => 'root',
                'ip' => "host.docker.internal",
                'team_id' => 0,
                'private_key_id' => 0
            ];
            $server_details['extra_attributes'] = ServerMetadata::from([
                'proxy_type' => ProxyTypes::TRAEFIK_V2->value,
                'proxy_status' => ProxyStatus::EXITED->value
            ]);
            $server = Server::create($server_details);
            $server->settings->is_validated = true;
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
        try {
            $settings = InstanceSettings::get();
            if (is_null($settings->public_ipv4)) {
                $ipv4 = Process::run('curl -4s https://ifconfig.io')->output();
                if ($ipv4) {
                    $ipv4 = trim($ipv4);
                    $ipv4 = filter_var($ipv4, FILTER_VALIDATE_IP);
                    $settings->update(['public_ipv4' => $ipv4]);
                }
            }
            if (is_null($settings->public_ipv6)) {
                $ipv6 = Process::run('curl -6s https://ifconfig.io')->output();
                if ($ipv6) {
                    $ipv6 = trim($ipv6);
                    $ipv6 = filter_var($ipv6, FILTER_VALIDATE_IP);
                    $settings->update(['public_ipv6' => $ipv6]);
                }
            }
        } catch (\Exception $e) {
            echo "Error: {$e->getMessage()}\n";
        }
    }
}
