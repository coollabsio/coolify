<?php

namespace Tests\Feature;

use App\Enums\ProxyTypes;
use App\Models\Application;
use App\Models\Project;
use App\Models\Server;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class DockerComposeTraefikLabelsRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_keeps_traefik_backend_labels_across_regenerate_cycles(): void
    {
        $application = $this->createDockerComposeApplication(<<<'YAML'
services:
  proxy:
    image: nginx:alpine
    expose:
      - "8080"
YAML, 'https://test.example.com');

        $application->parse();
        $this->assertTraefikBackendLabels(
            $this->serviceLabelsFor($application, 'proxy'),
            $application->uuid,
            'proxy',
            '8080'
        );

        $application->refresh();
        $application->parse();
        $this->assertTraefikBackendLabels(
            $this->serviceLabelsFor($application, 'proxy'),
            $application->uuid,
            'proxy',
            '8080'
        );

        $application->docker_compose_domains = json_encode([
            'proxy' => ['domain' => 'https://test.example.com'],
        ]);
        $application->save();

        $application->refresh();
        $application->parse();
        $this->assertTraefikBackendLabels(
            $this->serviceLabelsFor($application, 'proxy'),
            $application->uuid,
            'proxy',
            '8080'
        );
    }

    public function test_it_uses_the_container_target_port_for_traefik_labels(): void
    {
        $application = $this->createDockerComposeApplication(<<<'YAML'
services:
  proxy:
    image: nginx:alpine
    ports:
      - "127.0.0.1:18080:8080"
YAML, 'https://test.example.com');

        $application->parse();

        $labels = $this->serviceLabelsFor($application, 'proxy');

        $this->assertTraefikBackendLabels($labels, $application->uuid, 'proxy', '8080');
        $this->assertNotContains(
            "traefik.http.services.http-0-{$application->uuid}-proxy.loadbalancer.server.port=18080",
            $labels
        );
        $this->assertNotContains(
            "traefik.http.services.https-0-{$application->uuid}-proxy.loadbalancer.server.port=18080",
            $labels
        );
    }

    private function createDockerComposeApplication(string $compose, string $domain): Application
    {
        $team = Team::factory()->create();
        $server = Server::factory()->create([
            'team_id' => $team->id,
            'proxy' => ['type' => ProxyTypes::TRAEFIK->value],
        ]);
        $server->settings->update(['generate_exact_labels' => true]);

        $destination = StandaloneDocker::query()
            ->where('server_id', $server->id)
            ->firstOrFail();

        $project = Project::factory()->create(['team_id' => $team->id]);
        $environment = $project->environments()->firstOrFail();

        return Application::factory()->create([
            'name' => 'compose-traefik-test',
            'build_pack' => 'dockercompose',
            'environment_id' => $environment->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
            'docker_compose_raw' => $compose,
            'docker_compose_domains' => json_encode([
                'proxy' => ['domain' => $domain],
            ]),
        ]);
    }

    /**
     * @return list<string>
     */
    private function serviceLabelsFor(Application $application, string $serviceName): array
    {
        $parsedCompose = Yaml::parse($application->docker_compose);

        return data_get($parsedCompose, "services.{$serviceName}.labels", []);
    }

    /**
     * @param  list<string>  $labels
     */
    private function assertTraefikBackendLabels(array $labels, string $applicationUuid, string $serviceName, string $port): void
    {
        $this->assertContains(
            "traefik.http.routers.http-0-{$applicationUuid}-{$serviceName}.service=http-0-{$applicationUuid}-{$serviceName}",
            $labels
        );
        $this->assertContains(
            "traefik.http.services.http-0-{$applicationUuid}-{$serviceName}.loadbalancer.server.port={$port}",
            $labels
        );
        $this->assertContains(
            "traefik.http.routers.https-0-{$applicationUuid}-{$serviceName}.service=https-0-{$applicationUuid}-{$serviceName}",
            $labels
        );
        $this->assertContains(
            "traefik.http.services.https-0-{$applicationUuid}-{$serviceName}.loadbalancer.server.port={$port}",
            $labels
        );
    }
}
