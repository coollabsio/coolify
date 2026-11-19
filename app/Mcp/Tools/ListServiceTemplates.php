<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
class ListServiceTemplates extends Tool
{
    protected string $name = 'list_service_templates';

    protected string $description = 'List the one-click service templates that create_service accepts as type (name, slogan, category, tags, documentation, port). Never returns the Compose content. Optional search over name, slogan, category and tags. Requires read ability.';

    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read', $this->name)) {
            return $error;
        }

        if (is_null($this->resolveTeamId($request))) {
            return $this->mcpError($request, 'Invalid token.');
        }

        $search = $request->get('search');
        $search = is_string($search) ? mb_strtolower(trim($search)) : '';

        $templates = get_service_templates()
            ->map(fn ($template, $type): array => [
                'type' => (string) $type,
                'slogan' => is_string(data_get($template, 'slogan')) ? data_get($template, 'slogan') : null,
                'category' => is_string(data_get($template, 'category')) ? data_get($template, 'category') : null,
                'tags' => collect(data_get($template, 'tags', []))->filter(fn ($tag): bool => is_string($tag))->values()->all(),
                'documentation' => is_string(data_get($template, 'documentation')) ? data_get($template, 'documentation') : null,
                'port' => is_scalar(data_get($template, 'port')) ? (string) data_get($template, 'port') : null,
            ])
            ->when($search !== '', fn ($collection) => $collection->filter(function (array $template) use ($search): bool {
                $haystack = mb_strtolower(implode(' ', [$template['type'], $template['slogan'] ?? '', $template['category'] ?? '', implode(' ', $template['tags'])]));

                return str_contains($haystack, $search);
            }))
            ->sortKeys()
            ->values();

        $args = $this->paginationArgs($request);
        $total = $templates->count();
        $extraArgs = $search !== '' ? ['search' => $search] : [];

        return $this->mcpSuccess($request, $this->respond(
            $templates->slice($args['offset'], $args['per_page'])->values()->all(),
            [],
            $this->paginationMeta($this->name, $args, $total, $extraArgs),
        ));
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Case-insensitive text to match in the template name, slogan, category, or tags.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
