<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\IntegrationToken;
use App\Models\SecretManagerLink;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;

/**
 * The external secret manager of a resource: a team integration token by name, plus its settings.
 */
class SecretManager implements ChildRecords
{
    public function key(): string
    {
        return 'secret_manager';
    }

    public function map(): FieldMap
    {
        return new FieldMap('secret_manager_links', [
            Field::managed('integration_token_id', 'secret_manager.token'),
            Field::managed('settings', 'secret_manager.settings', jsonType: 'object'),
        ], ['secret_manager_links' => ['resourceable_type', 'resourceable_id']]);
    }

    public function read(Model $owner): ?array
    {
        $link = SecretManagerLink::query()
            ->where('resourceable_type', $owner->getMorphClass())
            ->where('resourceable_id', $owner->getKey())
            ->with('integrationToken')
            ->first();
        if ($link === null || $link->integrationToken === null) {
            return null;
        }

        return $this->canonical(['token' => $link->integrationToken->name, 'settings' => $link->settings ?? []]);
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || ! isset($desired['token'])) {
            return null;
        }
        $settings = (array) ($desired['settings'] ?? []);
        ksort($settings);
        $result = ['token' => (string) $desired['token']];
        if ($settings !== []) {
            $result['settings'] = $settings;
        }

        return $result;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'required' => ['token'],
            'additionalProperties' => false,
            'properties' => ['token' => ['type' => 'string'], 'settings' => ['type' => 'object']],
        ];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        if (! is_array($desired) || ! isset($desired['token'])) {
            $owner->secretManagerLink()->delete();

            return;
        }
        $token = IntegrationToken::query()->where('team_id', $ctx->teamId)->where('name', $desired['token'])->firstOrFail();
        $settings = array_filter((array) ($desired['settings'] ?? []), fn ($value) => filled($value));
        $owner->secretManagerLink()->updateOrCreate([], [
            'integration_token_id' => $token->id,
            'settings' => $settings ?: null,
        ]);
    }
}
