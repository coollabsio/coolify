<?php

namespace App\Services\ProjectConfig;

use App\Services\ProjectConfig\Adapters\ResourceAdapter;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * The adapter for each resource type, and the JSON schema built from them.
 */
class AdapterRegistry
{
    /** @var array<string, ResourceAdapter> */
    private array $adapters = [];

    /**
     * @param  iterable<ResourceAdapter>  $adapters
     */
    public function __construct(iterable $adapters = [])
    {
        foreach ($adapters as $adapter) {
            $this->adapters[$adapter->type()] = $adapter;
        }
    }

    public function for(string $type): ResourceAdapter
    {
        return $this->adapters[$type] ?? throw new InvalidArgumentException("No adapter for resource type \"{$type}\".");
    }

    public function has(string $type): bool
    {
        return isset($this->adapters[$type]);
    }

    /**
     * @return list<ResourceAdapter>
     */
    public function all(): array
    {
        return array_values($this->adapters);
    }

    public function forModel(Model $model): ResourceAdapter
    {
        foreach ($this->adapters as $adapter) {
            if ($model instanceof ($adapter->modelClass())) {
                return $adapter;
            }
        }

        throw new InvalidArgumentException('No adapter for '.$model::class.'.');
    }

    public function schema(): array
    {
        $typeRules = [];
        $definitions = ['env' => self::envSchema()];
        foreach ($this->adapters as $type => $adapter) {
            $definitions[$type] = $adapter->schema();
            $typeRules[] = [
                'if' => ['properties' => ['type' => ['const' => $type]]],
                'then' => ['$ref' => "#/\$defs/{$type}"],
            ];
        }

        $key = ['type' => 'string', 'pattern' => '^[a-z][a-z0-9-]{0,62}$'];
        $resource = ['type' => 'object', 'properties' => ['type' => ['enum' => ConfigParser::TYPES]], 'required' => ['type']];
        if ($typeRules !== []) {
            $resource['allOf'] = $typeRules;
        }
        $definitions['resource'] = $resource;

        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://coolify.io/schemas/coolify.v1.json',
            'title' => 'coolify.json',
            'type' => 'object',
            'required' => ['version', 'resources', 'environments'],
            'additionalProperties' => false,
            'patternProperties' => ['^x-' => true],
            'properties' => [
                '$schema' => ['type' => 'string'],
                'version' => ['const' => 1],
                'minimum_coolify_version' => ['type' => 'string'],
                'project' => ['type' => 'string'],
                'resources' => ['type' => 'object', 'propertyNames' => $key, 'additionalProperties' => ['$ref' => '#/$defs/resource']],
                'environments' => [
                    'type' => 'object',
                    'minProperties' => 1,
                    'additionalProperties' => [
                        'type' => 'object',
                        'required' => ['branch'],
                        'additionalProperties' => false,
                        'patternProperties' => ['^x-' => true],
                        'properties' => [
                            'branch' => ['type' => 'string'],
                            'resources' => ['type' => 'object', 'propertyNames' => $key],
                            'import' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['to', 'uuid'], 'properties' => ['to' => $key, 'uuid' => ['type' => 'string']]]],
                        ],
                    ],
                ],
                'moved' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['from', 'to'], 'properties' => ['from' => $key, 'to' => $key]]],
                'unmanage' => ['type' => 'array', 'items' => $key],
            ],
            '$defs' => $definitions,
        ];
    }

    private static function envSchema(): array
    {
        $entry = [
            'type' => 'object',
            'properties' => [
                'value' => ['type' => 'string'],
                'required' => ['const' => true],
                'generate' => ['enum' => ConfigParser::GENERATE_KINDS],
                'ref' => ['type' => 'string'],
                'length' => ['type' => 'integer', 'minimum' => 8, 'maximum' => 256],
                'rotation' => ['type' => 'integer', 'minimum' => 0],
                'buildtime' => ['type' => 'boolean'],
                'runtime' => ['type' => 'boolean'],
                'literal' => ['type' => 'boolean'],
                'multiline' => ['type' => 'boolean'],
            ],
            'oneOf' => [['required' => ['value']], ['required' => ['required']], ['required' => ['generate']], ['required' => ['ref']]],
        ];
        $entry['properties']['preview'] = ['type' => 'object'];

        return [
            'type' => 'object',
            'propertyNames' => ['pattern' => '^[A-Za-z_][A-Za-z0-9_]*$'],
            'additionalProperties' => ['anyOf' => [$entry, ['type' => 'null']]],
        ];
    }
}
