<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\ScheduledTask;
use App\Models\Service;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;

class ScheduledTasks implements ChildRecords
{
    private const DEFAULTS = ['enabled' => true, 'timeout' => 300, 'use_shell' => true];

    public function key(): string
    {
        return 'scheduled_tasks';
    }

    public function map(): FieldMap
    {
        return new FieldMap('scheduled_tasks', [
            Field::managed('name', 'scheduled_tasks'),
            Field::managed('command', 'scheduled_tasks.*.command'),
            Field::managed('frequency', 'scheduled_tasks.*.frequency'),
            Field::managed('container', 'scheduled_tasks.*.container'),
            Field::managed('timeout', 'scheduled_tasks.*.timeout', 300, 'integer'),
            Field::managed('enabled', 'scheduled_tasks.*.enabled', true, 'boolean'),
            Field::managed('use_shell', 'scheduled_tasks.*.use_shell', true, 'boolean'),
            Field::derived('team_id', 'Coolify sets the team of the resource.'),
            Field::state('consecutive_failures', 'Runtime failure counter.'),
            Field::state('failing_since', 'Runtime failure counter.'),
            Field::state('disabled_reason', 'Runtime failure counter.'),
        ], ['scheduled_tasks' => ['application_id', 'service_id']]);
    }

    public function read(Model $owner): ?array
    {
        $column = $owner instanceof Service ? 'service_id' : 'application_id';
        $result = [];
        foreach (ScheduledTask::query()->where($column, $owner->getKey())->orderBy('id')->get() as $task) {
            $result[$task->name] = $this->canonicalEntry([
                'command' => $task->command,
                'frequency' => $task->frequency,
                'container' => $task->container,
                'timeout' => (int) $task->timeout,
                'enabled' => (bool) $task->enabled,
                'use_shell' => (bool) $task->use_shell,
            ]);
        }
        ksort($result);

        return $result === [] ? null : $result;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }
        foreach ($desired as $name => $entry) {
            $desired[$name] = $this->canonicalEntry($entry);
        }
        ksort($desired);

        return $desired;
    }

    private function canonicalEntry(array $entry): array
    {
        foreach ($entry as $key => $value) {
            if ($value === null || $value === '' || (array_key_exists($key, self::DEFAULTS) && self::DEFAULTS[$key] === $value)) {
                unset($entry[$key]);
            }
        }
        ksort($entry);

        return $entry;
    }

    public function schema(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => [
                'type' => 'object',
                'required' => ['command', 'frequency'],
                'additionalProperties' => false,
                'properties' => [
                    'command' => ['type' => 'string'],
                    'frequency' => ['type' => 'string'],
                    'container' => ['type' => ['string', 'null']],
                    'timeout' => ['type' => 'integer'],
                    'enabled' => ['type' => 'boolean'],
                    'use_shell' => ['type' => 'boolean'],
                ],
            ],
        ];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $desired = is_array($desired) ? $desired : [];
        $column = $owner instanceof Service ? 'service_id' : 'application_id';
        $existing = ScheduledTask::query()->where($column, $owner->getKey())->get()->keyBy('name');

        foreach ($desired as $name => $entry) {
            $task = $existing[$name] ?? new ScheduledTask(['name' => $name]);
            $task->forceFill([
                $column => $owner->getKey(),
                'team_id' => $ctx->teamId,
                'command' => $entry['command'],
                'frequency' => $entry['frequency'],
                'container' => $entry['container'] ?? null,
                'timeout' => $entry['timeout'] ?? self::DEFAULTS['timeout'],
                'enabled' => $entry['enabled'] ?? self::DEFAULTS['enabled'],
                'use_shell' => $entry['use_shell'] ?? self::DEFAULTS['use_shell'],
            ])->save();
            unset($existing[$name]);
        }

        foreach ($existing as $task) {
            $task->delete();
        }
    }
}
