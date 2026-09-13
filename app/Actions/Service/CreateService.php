<?php

namespace App\Actions\Service;

use App\Data\ResourcePlacement;
use App\Exceptions\ServiceTemplateNotFoundException;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use Lorisleiva\Actions\Concerns\AsAction;
use Symfony\Component\Yaml\Yaml;

class CreateService
{
    use AsAction;

    /**
     * Create a service either from a one-click template slug or from raw
     * (base64) docker compose. Exactly one of $templateSlug / $dockerComposeRaw
     * must be provided.
     *
     * @param  array<string, mixed>  $data  Optional: name, description, connect_to_docker_network, is_container_label_escape_enabled
     */
    public function handle(
        ResourcePlacement $placement,
        ?string $templateSlug,
        ?string $dockerComposeRaw,
        array $data = [],
        bool $instantDeploy = false,
    ): Service {
        $service = $templateSlug
            ? $this->fromTemplate($placement, $templateSlug, $data)
            : $this->fromCompose($placement, (string) $dockerComposeRaw, $data);

        if ($instantDeploy) {
            StartService::dispatch($service);
        }

        return $service;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fromTemplate(ResourcePlacement $placement, string $slug, array $data): Service
    {
        $services = get_service_templates();
        $compose = data_get($services, "$slug.compose");
        if (! $compose) {
            throw new ServiceTemplateNotFoundException($services->keys()->all());
        }

        $dotEnvs = data_get($services, "$slug.envs", null);
        if ($dotEnvs) {
            $dotEnvs = str(base64_decode($dotEnvs))->split('/\r\n|\r|\n/')->filter(fn ($value) => ! empty($value));
        }

        $dockerComposeRaw = base64_decode($compose);
        // Normalize the base Exception the injection guard throws to a
        // RuntimeException so API callers get a 422 (validation) rather than a 500.
        try {
            validateDockerComposeForInjection($dockerComposeRaw);
        } catch (\RuntimeException $e) {
            throw $e;
        } catch (\Exception $e) {
            throw new \RuntimeException($e->getMessage(), 0, $e);
        }

        $payload = [
            'name' => "$slug-".str()->random(10),
            'docker_compose_raw' => $dockerComposeRaw,
            'environment_id' => $placement->environment->id,
            'service_type' => $slug,
            'server_id' => $placement->server->id,
            'destination_id' => $placement->destination->id,
            'destination_type' => $placement->destination->getMorphClass(),
        ];
        if (in_array($slug, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK)) {
            data_set($payload, 'connect_to_docker_network', true);
        }

        $service = new Service($payload);
        $service->save();
        $service->name = $data['name'] ?? "$slug-".$service->uuid;
        $service->description = $data['description'] ?? null;
        if (array_key_exists('is_container_label_escape_enabled', $data)) {
            $service->is_container_label_escape_enabled = (bool) $data['is_container_label_escape_enabled'];
        }
        $service->save();

        if ($dotEnvs && $dotEnvs->count() > 0) {
            $dotEnvs->each(function ($value) use ($service) {
                $key = str()->before($value, '=');
                $value = str(str()->after($value, '='));
                $generatedValue = $value;
                if ($value->contains('SERVICE_')) {
                    $command = $value->after('SERVICE_')->beforeLast('_');
                    $generatedValue = generateEnvValue($command->value(), $service);
                }
                EnvironmentVariable::create([
                    'key' => $key,
                    'value' => $generatedValue,
                    'resourceable_id' => $service->id,
                    'resourceable_type' => $service->getMorphClass(),
                    'is_preview' => false,
                ]);
            });
        }

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);

        return $service;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function fromCompose(ResourcePlacement $placement, string $base64Compose, array $data): Service
    {
        if (! isBase64Encoded($base64Compose)) {
            throw new \RuntimeException('The docker_compose_raw should be base64 encoded.');
        }
        $decoded = base64_decode($base64Compose);
        if (mb_detect_encoding($decoded, 'UTF-8', true) === false) {
            throw new \RuntimeException('The docker_compose_raw should be base64 encoded.');
        }
        $dockerComposeRaw = Yaml::dump(Yaml::parse($decoded), 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        validateDockerComposeForInjection($dockerComposeRaw);

        $service = new Service;
        $service->name = $data['name'] ?? 'service-'.str()->random(10);
        $service->description = $data['description'] ?? null;
        $service->docker_compose_raw = $dockerComposeRaw;
        $service->environment_id = $placement->environment->id;
        $service->server_id = $placement->server->id;
        $service->destination_id = $placement->destination->id;
        $service->destination_type = $placement->destination->getMorphClass();
        $service->connect_to_docker_network = $data['connect_to_docker_network'] ?? false;
        if (array_key_exists('is_container_label_escape_enabled', $data)) {
            $service->is_container_label_escape_enabled = (bool) $data['is_container_label_escape_enabled'];
        }
        $service->save();
        $service->parse(isNew: true);

        return $service;
    }
}
