<?php

namespace App\Ai\Docs;

use App\Ai\Docs\Exceptions\DocsIndexShapeException;
use App\Models\InstanceSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class DocsIndexStore
{
    private const INDEX_KEY = 'ai:docs:index';

    private const HASH_KEY = 'ai:docs:hash';

    public function masterEnabled(): bool
    {
        try {
            return (bool) (InstanceSettings::get()->is_ai_assistant_enabled ?? false);
        } catch (Throwable) {
            return false; // fail closed: no settings, no docs tools/fetch
        }
    }

    public function get(): ?DocsIndex
    {
        if (! $this->masterEnabled()) {
            return null;
        }

        $cached = Cache::get(self::INDEX_KEY);
        if (is_array($cached)) {
            $index = new DocsIndex($cached);
            if ($index->isValid()) {
                return $index;
            }
            Cache::forget(self::INDEX_KEY);
            Cache::forget(self::HASH_KEY);
        }

        $this->refresh();

        $cached = Cache::get(self::INDEX_KEY);

        return is_array($cached) && ($index = new DocsIndex($cached))->isValid() ? $index : null;
    }

    public function refresh(): void
    {
        if (! $this->masterEnabled()) {
            return;
        }

        try {
            $normalized = $this->parse($this->fetch());
            $hash = hash('sha256', json_encode($normalized));

            if (Cache::get(self::HASH_KEY) === $hash) {
                return; // identical content, no rewrite
            }

            Cache::forever(self::INDEX_KEY, $normalized);
            Cache::forever(self::HASH_KEY, $hash);
        } catch (Throwable $e) {
            Log::warning('Coolify docs index refresh failed; keeping existing cache.', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function fetch(): array
    {
        $base = rtrim((string) config('ai.docs.base_url', 'https://coolify.io/docs'), '/');

        return Http::acceptJson()->timeout(30)->get($base.'/api/search')->throw()->json();
    }

    /**
     * Normalize the serialized Orama index into the DocsIndex shape.
     *
     * @param  array<string, mixed>  $raw
     * @return array<string, mixed>
     */
    public function parse(array $raw): array
    {
        $tokenOccurrences = data_get($raw, 'index.tokenOccurrences.content');
        $frequencies = data_get($raw, 'index.frequencies.content');
        $fieldLengths = data_get($raw, 'index.fieldLengths.content');
        $avg = data_get($raw, 'index.avgFieldLength.content');
        $docs = data_get($raw, 'docs.docs');

        if (! is_array($tokenOccurrences) || ! is_array($frequencies) || ! is_array($fieldLengths) || ! is_array($docs) || ! is_numeric($avg)) {
            throw new DocsIndexShapeException('Unexpected Orama index shape from the docs endpoint.');
        }

        $normalizedDocs = [];
        foreach ($docs as $docId => $doc) {
            $normalizedDocs[(string) $docId] = [
                'pageId' => (string) (data_get($doc, 'pageId') ?? data_get($doc, 'page_id') ?? data_get($doc, 'url') ?? $docId),
                'title' => (string) (data_get($doc, 'title') ?? data_get($doc, 'pageTitle') ?? ''),
                'url' => (string) (data_get($doc, 'url') ?? ''),
                'content' => (string) (data_get($doc, 'content') ?? ''),
            ];
        }

        return [
            'n' => count($fieldLengths),
            'avgFieldLength' => (float) $avg,
            'tokenOccurrences' => $tokenOccurrences,
            'frequencies' => $frequencies,
            'fieldLengths' => $fieldLengths,
            'docs' => $normalizedDocs,
        ];
    }
}
