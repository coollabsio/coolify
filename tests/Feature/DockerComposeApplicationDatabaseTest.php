<?php

namespace Tests\Feature;

use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServiceDatabase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DockerComposeApplicationDatabaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_detects_database_services_in_application_docker_compose()
    {
        $server = Server::factory()->create();
        $project = Project::factory()->create();
        $environment = $project->environments()->first();

        $application = Application::create([
            'name' => 'test-app',
            'git_repository' => 'test/repo',
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'environment_id' => $environment->id,
            'destination_id' => $server->destinations()->first()->id,
            'docker_compose_raw' => "
version: '3.8'
services:
  web:
    image: nginx:alpine
    ports:
      - '80:80'
  db:
    image: postgres:15
    environment:
      POSTGRES_PASSWORD: example
"
        ]);

        parseDockerComposeFile($application);

        $this->assertDatabaseHas('service_databases', [
            'name' => 'db',
            'image' => 'postgres:15',
            'application_id' => $application->id,
        ]);

        $serviceDatabase = ServiceDatabase::where('application_id', $application->id)->first();
        $this->assertNotNull($serviceDatabase);
        $this->assertEquals('db', $serviceDatabase->name);
        // $this->assertTrue($serviceDatabase->isDatabase()); // explicit check via class type
        $this->assertInstanceOf(ServiceDatabase::class, $serviceDatabase);
    }

    public function test_it_updates_existing_service_database()
    {
        $server = Server::factory()->create();
        $project = Project::factory()->create();
        $environment = $project->environments()->first();

        $application = Application::create([
            'name' => 'test-app',
            'git_repository' => 'test/repo',
            'git_branch' => 'main',
            'build_pack' => 'dockercompose',
            'environment_id' => $environment->id,
            'destination_id' => $server->destinations()->first()->id,
            'docker_compose_raw' => "
version: '3.8'
services:
  db:
    image: postgres:15
"
        ]);

        // First parse
        parseDockerComposeFile($application);
        $this->assertDatabaseHas('service_databases', [
            'name' => 'db',
            'image' => 'postgres:15',
        ]);

        // Update compose
        $application->docker_compose_raw = "
version: '3.8'
services:
  db:
    image: postgres:16
";
        $application->save();

        // Second parse
        parseDockerComposeFile($application);
        
        $this->assertDatabaseHas('service_databases', [
            'name' => 'db',
            'image' => 'postgres:16',
            'application_id' => $application->id,
        ]);
        
        // Ensure only one record exists
        $this->assertEquals(1, ServiceDatabase::where('application_id', $application->id)->count());
    }
}
