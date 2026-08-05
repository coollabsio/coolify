<?php

namespace App\Actions\Proxy;

use App\Models\Server;
use App\Rules\ValidProxyConfigFilename;
use Illuminate\Support\Facades\Validator;
use Lorisleiva\Actions\Concerns\AsAction;

class ListProxyDynamicConfigurations
{
    use AsAction;

    private const MAX_CONTENT_SIZE = 1_048_576;

    private const TOO_LARGE_MARKER = '__COOLIFY_DYNAMIC_CONFIG_TOO_LARGE__';

    /**
     * @return list<array{filename: string, content?: string}>
     */
    public function handle(Server $server, bool $includeContent = false): array
    {
        $dynamicPath = $server->proxyPath().'/dynamic';
        $escapedDynamicPath = escapeshellarg($dynamicPath);
        $output = instant_remote_process([
            "mkdir -p {$escapedDynamicPath}",
            "find {$escapedDynamicPath} -mindepth 1 -maxdepth 1 -type f -exec basename {} \\;",
        ], $server);

        $filenames = collect(explode("\n", (string) $output))
            ->map(fn (string $filename): string => trim($filename))
            ->filter(fn (string $filename): bool => filled($filename))
            ->filter(fn (string $filename): bool => Validator::make(
                ['filename' => $filename],
                ['filename' => ['required', new ValidProxyConfigFilename]],
            )->passes())
            ->sort()
            ->values();

        return array_values($filenames->map(function (string $filename) use ($dynamicPath, $includeContent, $server): array {
            $configuration = ['filename' => $filename];
            if (! $includeContent) {
                return $configuration;
            }

            $escapedFile = escapeshellarg("{$dynamicPath}/{$filename}");
            $encodedContent = instant_remote_process([
                "size=$(wc -c < {$escapedFile}); if test \"\$size\" -le ".self::MAX_CONTENT_SIZE."; then base64 < {$escapedFile} | tr -d '\\n'; else echo '".self::TOO_LARGE_MARKER."'; fi",
            ], $server);
            if ($encodedContent === self::TOO_LARGE_MARKER) {
                return $configuration;
            }

            $content = base64_decode((string) $encodedContent, true);
            $configuration['content'] = $content === false ? '' : sanitize_utf8_text($content);

            return $configuration;
        })->all());
    }
}
