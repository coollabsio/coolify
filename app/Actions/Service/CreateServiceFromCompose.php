<?php

namespace App\Actions\Service;

use App\Models\Environment;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\SwarmDocker;

/**
 * Creates a service from a Compose file or a one-click template, and parses it.
 * The Compose file must pass validateDockerComposeForInjection() before this runs.
 */
class CreateServiceFromCompose
{
    /**
     * @param  array<string, mixed>  $attributes  Saved after the first save, when the UUID exists.
     */
    public function handle(
        Environment $environment,
        Server $server,
        StandaloneDocker|SwarmDocker $destination,
        string $dockerComposeRaw,
        ?string $templateKey = null,
        array $attributes = [],
        bool $connectToDockerNetwork = false,
        ?string $name = null,
    ): Service {
        $payload = [
            'name' => ($templateKey ?? 'service').'-'.str()->random(10),
            'docker_compose_raw' => $dockerComposeRaw,
            'environment_id' => $environment->id,
            'service_type' => $templateKey,
            'server_id' => $server->id,
            'destination_id' => $destination->id,
            'destination_type' => $destination->getMorphClass(),
        ];
        if ($connectToDockerNetwork || ($templateKey !== null && in_array($templateKey, NEEDS_TO_CONNECT_TO_PREDEFINED_NETWORK))) {
            $payload['connect_to_docker_network'] = true;
        }
        $service = new Service($payload);
        $service->save();
        $service->forceFill(['name' => $name ?? ($templateKey ?? 'service').'-'.$service->uuid, ...$attributes]);
        $service->save();

        if ($templateKey !== null) {
            foreach ($this->templateVariables($templateKey) as $line) {
                $key = str()->before($line, '=');
                $value = str(str()->after($line, '='));
                $generatedValue = $value;
                if ($value->contains('SERVICE_')) {
                    $command = $value->after('SERVICE_')->beforeLast('_');
                    $generatedValue = generateEnvValue($command->value(), $service);
                }
                $service->environment_variables()->updateOrCreate(['key' => $key], [
                    'value' => $generatedValue,
                    'resourceable_id' => $service->id,
                    'resourceable_type' => $service->getMorphClass(),
                    'is_preview' => false,
                ]);
            }
        }

        $service->parse(isNew: true);
        applyServiceApplicationPrerequisites($service);

        return $service;
    }

    /**
     * The KEY=VALUE lines of the .env part of a template.
     *
     * @return list<string>
     */
    public function templateVariables(string $templateKey): array
    {
        $envs = data_get(get_service_templates(), "{$templateKey}.envs");
        if (! $envs) {
            return [];
        }

        return str(base64_decode($envs))->split('/\r\n|\r|\n/')->filter(fn ($value) => ! empty($value))->values()->all();
    }

    public static function templateCompose(string $templateKey): ?string
    {
        $compose = data_get(get_service_templates(), "{$templateKey}.compose");

        return $compose ? base64_decode($compose) : null;
    }
}
