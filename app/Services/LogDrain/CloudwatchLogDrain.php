<?php

namespace App\Services\LogDrain;

/**
 * Containers send their logs directly with the Docker awslogs driver, so no Fluent Bit
 * container runs. The Docker daemon reads the AWS credentials.
 */
class CloudwatchLogDrain implements LogDrainDriver
{
    public function label(): string
    {
        return 'Amazon CloudWatch Logs';
    }

    public function fields(): array
    {
        return [
            'region' => ['label' => 'Region', 'rules' => ['string', 'max:64', 'regex:/^[a-z]{2}(-[a-z]+)+-\d+$/'], 'required' => true],
            'group' => ['label' => 'Log group', 'rules' => ['string', 'regex:/^[\.\-_\/#A-Za-z0-9]{1,512}$/'], 'required' => true],
            'stream_prefix' => ['label' => 'Log stream prefix', 'rules' => ['string', 'max:256', 'regex:/^[A-Za-z0-9_\-\.\/#]*$/']],
        ];
    }

    public function containerLogging(array $config): array
    {
        $options = [
            'awslogs-region' => $config['region'] ?? null,
            'awslogs-group' => $config['group'] ?? null,
            'awslogs-create-group' => 'true',
            // Like fluentd-async: a CloudWatch outage or missing credentials must not block or fail the container.
            'mode' => 'non-blocking',
            // The stream is named after the container; awslogs-stream is never set because it would override this tag.
            'tag' => ($config['stream_prefix'] ?? '').'{{.Name}}',
        ];

        return [
            'driver' => 'awslogs',
            'options' => collect($options)
                ->reject(fn ($value) => blank($value))
                ->all(),
        ];
    }
}
