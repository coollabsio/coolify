<?php

namespace App\Ai\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListServiceTemplates implements Tool
{
    public function description(): string
    {
        return 'List the official Coolify one-click service templates that can be deployed. '
            .'Returns each template as "slug — Name [category]: slogan"; the slug is the exact identifier. '
            .'Use this to tell the user which services Coolify can deploy, or to find a template. '
            .'Pass search to filter by name, slogan, tags, or category, and category to filter by category.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter templates whose slug, name, slogan, tags, or category contain this text.'),
            'category' => $schema->string()->description('Only return templates in this category.'),
            'limit' => $schema->integer()->description('Maximum templates to return. Omit to return every match.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->validate([
            'search' => 'nullable|string',
            'category' => 'nullable|string',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $search = isset($args['search']) ? str($args['search'])->lower()->value() : null;
        $category = isset($args['category']) ? str($args['category'])->lower()->value() : null;

        $rows = get_service_templates()->map(fn ($template, $slug) => [
            'slug' => (string) $slug,
            'name' => str((string) $slug)->headline()->value(),
            'category' => (string) data_get($template, 'category', ''),
            'slogan' => (string) data_get($template, 'slogan', ''),
            'tags' => collect((array) data_get($template, 'tags', []))->implode(' '),
        ])->values();

        if ($category !== null) {
            $rows = $rows->filter(fn ($row) => str_contains(str($row['category'])->lower()->value(), $category));
        }

        if ($search !== null) {
            $rows = $rows->filter(function ($row) use ($search) {
                $haystack = str("{$row['slug']} {$row['name']} {$row['slogan']} {$row['tags']} {$row['category']}")->lower()->value();

                return str_contains($haystack, $search);
            });
        }

        $total = $rows->count();
        if ($total === 0) {
            return 'No service templates matched.';
        }

        if (isset($args['limit'])) {
            $rows = $rows->take((int) $args['limit']);
        }

        $lines = $rows->map(function ($row) {
            $line = "{$row['slug']} — {$row['name']}";
            if ($row['category'] !== '') {
                $line .= " [{$row['category']}]";
            }
            if ($row['slogan'] !== '') {
                $line .= ": {$row['slogan']}";
            }

            return $line;
        })->implode("\n");

        $shown = $rows->count();
        $header = $shown < $total
            ? "Showing {$shown} of {$total} matching templates:\n"
            : "{$total} templates:\n";

        return $header.$lines;
    }
}
