<?php

namespace Tests\Unit\Actions\Server;

use App\Actions\Server\CollectServerInfo;
use App\Models\InstanceSettings;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// Define a function in the same namespace to override the global one
function instant_remote_process($command, $server, $throwError = true, $no_sudo = false)
{
    if (is_array($command) && count($command) > 0) {
        if (str_contains($command[0], 'model name')) {
            return 'Intel(R) Xeon(R) CPU @ 2.20GHz';
        } elseif (str_contains($command[0], 'nproc')) {
            return '4';
        } elseif (str_contains($command[0], 'cpu MHz')) {
            return '2200.000';
        } elseif (str_contains($command[0], 'free -h') && str_contains($command[0], 'Mem:')) {
            return '16G';
        } elseif (str_contains($command[0], 'dmidecode')) {
            return '2666 MHz';
        } elseif (str_contains($command[0], 'free -h') && str_contains($command[0], 'Swap:')) {
            return '4G';
        } elseif (str_contains($command[0], 'df -h')) {
            return '100G 45G 55G';
        } elseif (str_contains($command[0], 'lspci')) {
            return 'NVIDIA GeForce RTX 3080';
        } elseif (str_contains($command[0], 'nvidia-smi')) {
            return '10GB';
        } elseif (str_contains($command[0], 'os-release')) {
            return 'Ubuntu 22.04 LTS';
        } elseif (str_contains($command[0], 'uname -r')) {
            return '5.15.0-1031-aws';
        } elseif (str_contains($command[0], 'uname -m')) {
            return 'x86_64';
        }
    }
    return null;
}

class CollectServerInfoTest extends TestCase
{
    use RefreshDatabase;

    public function test_collect_server_info_updates_server_settings()
    {
        // Create instance settings
        InstanceSettings::factory()->create();

        // Create a team
        $team = Team::factory()->create();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create server settings
        ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Run the action
        $result = CollectServerInfo::run($server);

        // Assert the result is true
        $this->assertTrue($result);

        // Refresh the server from the database
        $server->refresh();

        // Assert that the server settings were updated
        $this->assertEquals('Intel(R) Xeon(R) CPU @ 2.20GHz', $server->settings->cpu_model);
        $this->assertEquals('4', $server->settings->cpu_cores);
        $this->assertEquals('2200.000 MHz', $server->settings->cpu_speed);
        $this->assertEquals('16G', $server->settings->memory_total);
        $this->assertEquals('2666 MHz', $server->settings->memory_speed);
        $this->assertEquals('4G', $server->settings->swap_total);
        $this->assertEquals('100G', $server->settings->disk_total);
        $this->assertEquals('45G', $server->settings->disk_used);
        $this->assertEquals('55G', $server->settings->disk_free);
        $this->assertEquals('NVIDIA GeForce RTX 3080', $server->settings->gpu_model);
        $this->assertEquals('10GB', $server->settings->gpu_memory);
        $this->assertEquals('Ubuntu', $server->settings->os_name);
        $this->assertEquals('22.04 LTS', $server->settings->os_version);
        $this->assertEquals('5.15.0-1031-aws', $server->settings->kernel_version);
        $this->assertEquals('x86_64', $server->settings->architecture);
    }

    public function test_collect_server_info_handles_missing_data()
    {
        // Create instance settings
        InstanceSettings::factory()->create();

        // The instant_remote_process function is already mocked at the namespace level
        // and will return null for this test since we're not matching any command patterns

        // Create a team
        $team = Team::factory()->create();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create server settings
        ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Run the action
        $result = CollectServerInfo::run($server);

        // Assert the result is true
        $this->assertTrue($result);

        // Refresh the server from the database
        $server->refresh();

        // Assert that the server settings were not updated (null values)
        $this->assertNull($server->settings->cpu_model);
        $this->assertNull($server->settings->cpu_cores);
        $this->assertNull($server->settings->cpu_speed);
        $this->assertNull($server->settings->memory_total);
        $this->assertNull($server->settings->memory_speed);
        $this->assertNull($server->settings->swap_total);
        $this->assertNull($server->settings->disk_total);
        $this->assertNull($server->settings->disk_used);
        $this->assertNull($server->settings->disk_free);
        $this->assertNull($server->settings->gpu_model);
        $this->assertNull($server->settings->gpu_memory);
        $this->assertNull($server->settings->os_name);
        $this->assertNull($server->settings->os_version);
        $this->assertNull($server->settings->kernel_version);
        $this->assertNull($server->settings->architecture);
    }
}
