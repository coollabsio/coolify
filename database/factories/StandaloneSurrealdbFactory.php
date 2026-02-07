<?php

namespace Database\Factories;

use App\Models\StandaloneDocker;
use App\Models\StandaloneSurrealdb;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\StandaloneSurrealdb>
 */
class StandaloneSurrealdbFactory extends Factory
{
    protected $model = StandaloneSurrealdb::class;

    public function definition(): array
    {
        return [
            'uuid' => Str::uuid(),
            'name' => 'surrealdb-'.fake()->word(),
            'description' => fake()->sentence(),
            'image' => 'surrealdb/surrealdb:v2',
            'surrealdb_user' => 'root',
            'surrealdb_password' => fake()->password(12),
            'ports_mappings' => null,
            'is_public' => false,
            'public_port' => null,
            'enable_ssl' => false,
            'ssl_mode' => 'disable',
            'is_log_drain_enabled' => false,
            'destination_type' => StandaloneDocker::class,
            'destination_id' => function (array $attributes) {
                // Get environment to find team_id
                $environment = \App\Models\Environment::find($attributes['environment_id']);
                $teamId = $environment ? $environment->project->team_id : \App\Models\Team::factory()->create()->id;
                
                // Create a server with team_id
                $server = \App\Models\Server::factory()->create([
                    'team_id' => $teamId,
                ]);
                
                // Then create a StandaloneDocker destination
                return StandaloneDocker::create([
                    'name' => 'test-docker-'.uniqid(),
                    'uuid' => Str::uuid(),
                    'network' => 'coolify-test-'.uniqid(),
                    'server_id' => $server->id,
                ])->id;
            },
            'status' => 'exited:unhealthy',
            'config_hash' => null,
            'custom_docker_run_options' => null,
        ];
    }

    /**
     * Indicate that the database is running.
     */
    public function running(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'running:healthy',
        ]);
    }

    /**
     * Indicate that the database is public.
     */
    public function public(int $port = 8001): static
    {
        return $this->state(fn (array $attributes) => [
            'is_public' => true,
            'public_port' => $port,
        ]);
    }

    /**
     * Indicate that SSL is enabled.
     */
    public function withSsl(string $mode = 'require'): static
    {
        return $this->state(fn (array $attributes) => [
            'enable_ssl' => true,
            'ssl_mode' => $mode,
        ]);
    }
}

