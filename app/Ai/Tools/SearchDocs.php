<?php

namespace App\Ai\Tools;

use App\Ai\Docs\DocsIndexStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class SearchDocs implements Tool
{
    public function description(): string
    {
        return 'Search the official Coolify documentation for a query and return the most relevant pages. '
            .'Use this when unsure about a Coolify feature or configuration, then read a page with read_doc_page and cite its url.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->description('What to search the docs for.')->required(),
            'limit' => $schema->integer()->description('Maximum pages to return (default 5).'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'query' => 'required|string',
            'limit' => 'nullable|integer|min:1|max:10',
        ]);

        $index = app(DocsIndexStore::class)->get();
        if (! $index) {
            return 'Documentation search is unavailable right now.';
        }

        $results = $index->search($args['query'], (int) ($args['limit'] ?? 5));
        if ($results === []) {
            return 'No documentation matched that query.';
        }

        return collect($results)->map(fn ($r) => "{$r['title']} ({$r['url']})\npage_id: {$r['page_id']}\n{$r['snippet']}")
            ->implode("\n\n");
    }
}
