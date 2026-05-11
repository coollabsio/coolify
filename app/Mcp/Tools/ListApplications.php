<?php

namespace App\Mcp\Tools;

use App\Mcp\Concerns\BuildsResponse;
use App\Mcp\Concerns\ResolvesTeam;
use App\Models\Application;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('list_applications')]
#[Description('List applications owned by the authenticated team. Returns summary (uuid, name, status, fqdn, git_repository). Optional "tag" argument filters by tag name. Use get_application for full details.')]
class ListApplications extends Tool
{
    use BuildsResponse;
    use ResolvesTeam;

    public function handle(Request $request): Response
    {
        if ($error = $this->ensureAbility($request, 'read')) {
            return $error;
        }

        $teamId = $this->resolveTeamId($request);
        if (is_null($teamId)) {
            return Response::error('Invalid token.');
        }

        $tagName = $request->get('tag');
        if ($tagName !== null && (! is_string($tagName) || trim($tagName) === '')) {
            return Response::error('tag argument must be a non-empty string.');
        }
        $args = $this->paginationArgs($request);

        $query = Application::ownedByCurrentTeamAPI($teamId)
            ->when($tagName !== null, function ($query) use ($tagName) {
                $query->whereHas('tags', fn ($q) => $q->where('name', $tagName));
            });

        $total = (clone $query)->count();

        $summaries = $query
            ->skip($args['offset'])
            ->take($args['per_page'])
            ->get()
            ->map(fn ($app) => [
                'uuid' => $app->uuid,
                'name' => $app->name,
                'status' => $app->status,
                'fqdn' => $app->fqdn,
                'git_repository' => $app->git_repository,
            ])
            ->values()
            ->all();

        $extra = $tagName ? ['tag' => $tagName] : [];

        return $this->respond(
            $summaries,
            [],
            $this->paginationMeta('list_applications', $args, $total, $extra),
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tag' => $schema->string()->description('Optional tag name filter.'),
            'page' => $schema->integer()->description('Page number (default 1).'),
            'per_page' => $schema->integer()->description('Items per page (default 50, max 100).'),
        ];
    }
}
