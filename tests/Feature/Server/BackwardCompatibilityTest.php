<?php

namespace Tests\Feature\Server;

use App\Actions\Server\CollectServerInfo;
use App\Jobs\CollectServerInfoJob;
use App\Livewire\Server\Info;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Livewire\Livewire;
use Tests\TestCase;

class BackwardCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_info_works_with_missing_fields()
    {
        // Create a user and team
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $user->teams()->attach($team);
        $user->switchTeam($team);

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings
        $serverSettings = ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Simulate a scenario where the server_settings table doesn't have the new fields
        // by setting them to null
        $serverSettings->cpu_model = null;
        $serverSettings->cpu_cores = null;
        $serverSettings->cpu_speed = null;
        $serverSettings->memory_total = null;
        $serverSettings->memory_speed = null;
        $serverSettings->swap_total = null;
        $serverSettings->disk_total = null;
        $serverSettings->disk_used = null;
        $serverSettings->disk_free = null;
        $serverSettings->gpu_model = null;
        $serverSettings->gpu_memory = null;
        $serverSettings->os_name = null;
        $serverSettings->os_version = null;
        $serverSettings->kernel_version = null;
        $serverSettings->architecture = null;
        $serverSettings->save();

        // Act as the user
        $this->actingAs($user);

        // Test that the component renders correctly even with missing fields
        Livewire::test(Info::class, ['server_uuid' => $server->uuid])
            ->assertSee('Server Information')
            ->assertSee('CPU Information')
            ->assertSee('Memory Information')
            ->assertSee('Disk Information')
            ->assertSee('GPU Information')
            ->assertSee('Operating System Information')
            ->assertSee('Not available'); // The default text for missing info
    }

    public function test_collect_server_info_handles_missing_fields()
    {
        // Mock the instant_remote_process function
        $this->mock('instant_remote_process', function ($command, $server, $throwError = true) {
            if (str_contains($command[0], 'model name')) {
                return 'Intel(R) Xeon(R) CPU @ 2.20GHz';
            }
            return null;
        });

        // Create a team
        $team = Team::factory()->create();

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
        ]);

        // Create server settings
        $serverSettings = ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Simulate a scenario where the server_settings table doesn't have the new fields
        // by setting them to null
        $serverSettings->cpu_model = null;
        $serverSettings->cpu_cores = null;
        $serverSettings->cpu_speed = null;
        $serverSettings->memory_total = null;
        $serverSettings->memory_speed = null;
        $serverSettings->swap_total = null;
        $serverSettings->disk_total = null;
        $serverSettings->disk_used = null;
        $serverSettings->disk_free = null;
        $serverSettings->gpu_model = null;
        $serverSettings->gpu_memory = null;
        $serverSettings->os_name = null;
        $serverSettings->os_version = null;
        $serverSettings->kernel_version = null;
        $serverSettings->architecture = null;
        $serverSettings->save();

        // Run the action
        $result = CollectServerInfo::run($server);

        // Assert the result is true
        $this->assertTrue($result);

        // Refresh the server from the database
        $server->refresh();

        // Assert that only the cpu_model field was updated
        $this->assertEquals('Intel(R) Xeon(R) CPU @ 2.20GHz', $server->settings->cpu_model);
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

    public function test_job_works_with_missing_fields()
    {
        // Set up queue fake
        Queue::fake();

        // Create a user and team
        $user = User::factory()->create();
        $team = Team::factory()->create();
        $user->teams()->attach($team);
        $user->switchTeam($team);

        // Create a server with settings
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'name' => 'Test Server',
        ]);

        // Create server settings with missing fields
        $serverSettings = ServerSetting::factory()->create([
            'server_id' => $server->id,
        ]);

        // Simulate a scenario where the server_settings table doesn't have the new fields
        // by setting them to null
        $serverSettings->cpu_model = null;
        $serverSettings->cpu_cores = null;
        $serverSettings->cpu_speed = null;
        $serverSettings->memory_total = null;
        $serverSettings->memory_speed = null;
        $serverSettings->swap_total = null;
        $serverSettings->disk_total = null;
        $serverSettings->disk_used = null;
        $serverSettings->disk_free = null;
        $serverSettings->gpu_model = null;
        $serverSettings->gpu_memory = null;
        $serverSettings->os_name = null;
        $serverSettings->os_version = null;
        $serverSettings->kernel_version = null;
        $serverSettings->architecture = null;
        $serverSettings->save();

        // Act as the user
        $this->actingAs($user);

        // Test that the collectServerInfo method dispatches the job
        Livewire::test(Info::class, ['server_uuid' => $server->uuid])
            ->call('collectServerInfo');

        // Assert that the job was dispatched
        Queue::assertPushed(CollectServerInfoJob::class, function ($job) use ($server) {
            return $job->server->id === $server->id;
        });
    }
}
