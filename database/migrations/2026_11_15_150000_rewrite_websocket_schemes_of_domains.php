<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Older versions saved ws:// and wss:// domains. The proxy routes WebSockets over http:// and https://,
     * and the Compose parser and the proxy labels fail on other schemes, so rewrite the saved domains.
     */
    public function up(): void
    {
        $this->rewrite('applications', ['fqdn', 'docker_compose_domains']);
        $this->rewrite('application_previews', ['fqdn', 'docker_compose_domains']);
        $this->rewrite('service_applications', ['fqdn']);
    }

    public function down(): void
    {
        // The rewritten domains cannot be told apart from domains saved with http:// or https://.
    }

    /**
     * @param  array<int, string>  $columns
     */
    private function rewrite(string $table, array $columns): void
    {
        DB::table($table)
            ->select(['id', ...$columns])
            ->where(function ($query) use ($columns): void {
                foreach ($columns as $column) {
                    $query->orWhereRaw("lower({$column}) like '%ws:%'")
                        ->orWhereRaw("lower({$column}) like '%wss:%'");
                }
            })
            ->chunkById(100, function ($rows) use ($table, $columns): void {
                foreach ($rows as $row) {
                    $changes = [];
                    foreach ($columns as $column) {
                        $value = $row->{$column};
                        if (! is_string($value)) {
                            continue;
                        }
                        $rewritten = $column === 'docker_compose_domains'
                            ? $this->rewriteComposeDomains($value)
                            : $this->rewriteUrls($value);
                        if ($rewritten !== $value) {
                            $changes[$column] = $rewritten;
                        }
                    }
                    if ($changes !== []) {
                        DB::table($table)->where('id', $row->id)->update($changes);
                    }
                }
            });
    }

    private function rewriteComposeDomains(string $json): string
    {
        $domains = json_decode($json, true);
        if (! is_array($domains)) {
            return $json;
        }

        $changed = false;
        array_walk_recursive($domains, function (mixed &$value) use (&$changed): void {
            if (is_string($value)) {
                $rewritten = $this->rewriteUrls($value);
                $changed = $changed || $rewritten !== $value;
                $value = $rewritten;
            }
        });

        return $changed ? (json_encode($domains) ?: $json) : $json;
    }

    private function rewriteUrls(string $urls): string
    {
        return preg_replace_callback(
            '~(^|,)(\s*)ws(s?)://~i',
            fn (array $match): string => $match[1].$match[2].($match[3] === '' ? 'http' : 'https').'://',
            $urls,
        ) ?? $urls;
    }
};
