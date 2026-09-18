<?php

namespace App\Ai\Docs;

class DocsIndex
{
    private const K1 = 1.2;

    private const B = 0.75;

    private const PAGE_MAX_CHARS = 12000;

    private const SNIPPET_CHARS = 240;

    /**
     * @param  array<string, mixed>  $data  normalized index (see class-level shape)
     */
    public function __construct(private array $data) {}

    public function isValid(): bool
    {
        foreach (['n', 'avgFieldLength', 'tokenOccurrences', 'frequencies', 'fieldLengths', 'docs'] as $key) {
            if (! array_key_exists($key, $this->data)) {
                return false;
            }
        }

        return is_array($this->data['tokenOccurrences'])
            && is_array($this->data['frequencies'])
            && is_array($this->data['docs'])
            && (float) $this->data['avgFieldLength'] > 0
            && (int) $this->data['n'] > 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, int $limit = 5): array
    {
        if (! $this->isValid()) {
            return [];
        }

        $tokens = self::tokenize($query);
        if ($tokens === []) {
            return [];
        }

        $n = (int) $this->data['n'];
        $avg = (float) $this->data['avgFieldLength'];

        $docScores = [];
        foreach ($tokens as $token) {
            $nt = $this->data['tokenOccurrences'][$token] ?? 0;
            if ($nt <= 0) {
                continue;
            }
            $idf = log(1 + (($n - $nt + 0.5) / ($nt + 0.5)));

            foreach ($this->data['frequencies'] as $docId => $tokenFreqs) {
                $normalized = $tokenFreqs[$token] ?? null;
                if ($normalized === null) {
                    continue;
                }
                $fieldLength = (float) ($this->data['fieldLengths'][$docId] ?? $avg);
                $rawTf = $normalized * $fieldLength;
                $denom = $rawTf + self::K1 * (1 - self::B + self::B * ($fieldLength / $avg));
                $docScores[$docId] = ($docScores[$docId] ?? 0) + $idf * (($rawTf * (self::K1 + 1)) / max($denom, 1e-9));
            }
        }

        if ($docScores === []) {
            return [];
        }

        // Aggregate chunk scores by page; keep the best chunk for the snippet.
        $pages = [];
        foreach ($docScores as $docId => $score) {
            $doc = $this->data['docs'][$docId] ?? null;
            if (! $doc) {
                continue;
            }
            $pageId = $doc['pageId'] ?? $docId;
            if (! isset($pages[$pageId])) {
                $pages[$pageId] = [
                    'page_id' => $pageId,
                    'title' => $doc['title'] ?? $pageId,
                    'url' => $doc['url'] ?? '',
                    'snippet' => self::snippet($doc['content'] ?? ''),
                    'score' => 0.0,
                    'best' => -INF,
                ];
            }
            $pages[$pageId]['score'] += $score;
            if ($score > $pages[$pageId]['best']) {
                $pages[$pageId]['best'] = $score;
                $pages[$pageId]['snippet'] = self::snippet($doc['content'] ?? '');
            }
        }

        usort($pages, fn ($a, $b) => $b['score'] <=> $a['score']);

        return array_map(
            fn ($p) => ['page_id' => $p['page_id'], 'title' => $p['title'], 'url' => $p['url'], 'snippet' => $p['snippet'], 'score' => $p['score']],
            array_slice(array_values($pages), 0, max($limit, 1)),
        );
    }

    public function page(string $pageId): string
    {
        if (! $this->isValid()) {
            return '';
        }

        $chunks = [];
        foreach ($this->data['docs'] as $doc) {
            if (($doc['pageId'] ?? null) === $pageId) {
                $chunks[] = trim((string) ($doc['content'] ?? ''));
            }
        }

        return mb_substr(trim(implode("\n\n", array_filter($chunks))), 0, self::PAGE_MAX_CHARS);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<int, string>
     */
    public static function tokenize(string $text): array
    {
        $text = mb_strtolower(trim($text));
        $folded = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
        $text = $folded !== false ? $folded : $text;
        $parts = preg_split('/[^a-z0-9]+/', $text, -1, PREG_SPLIT_NO_EMPTY);

        return $parts ?: [];
    }

    private static function snippet(string $content): string
    {
        $content = trim(preg_replace('/\s+/', ' ', $content));

        return mb_substr($content, 0, self::SNIPPET_CHARS);
    }
}
