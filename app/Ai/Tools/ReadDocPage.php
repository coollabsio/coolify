<?php

namespace App\Ai\Tools;

use App\Ai\Docs\DocsIndexStore;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ReadDocPage implements Tool
{
    public function description(): string
    {
        return 'Read the full markdown of a Coolify documentation page by the page_id returned from search_docs. Cite the page url in your answer.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'page_id' => $schema->string()->description('The page_id from a search_docs result.')->required(),
        ];
    }

    public function handle(Request $request): string
    {
        $pageId = (string) ($request->validate(['page_id' => 'required|string'])['page_id']);

        $index = app(DocsIndexStore::class)->get();
        if (! $index) {
            return 'Documentation is unavailable right now.';
        }

        $content = $index->page($pageId);

        return $content === '' ? 'No documentation page found for that id.' : $content;
    }
}
