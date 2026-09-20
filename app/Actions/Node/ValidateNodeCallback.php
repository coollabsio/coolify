<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

class ValidateNodeCallback
{
    use AsAction;

    public function handle(Node $node, string $callbackUrl): string
    {
        return instant_remote_process(
            [self::validationScript($callbackUrl)],
            $node,
            timeout: 20,
            disableMultiplexing: true,
        );
    }

    public static function validationScript(string $callbackUrl): string
    {
        $healthUrl = escapeshellarg(rtrim($callbackUrl, '/').'/api/health');

        return <<<SH
set -eu
url={$healthUrl}
if command -v curl >/dev/null; then
    curl --fail --silent --show-error --connect-timeout 5 --max-time 10 --output /dev/null "\$url"
elif command -v wget >/dev/null; then
    wget --quiet --timeout=10 --tries=1 --output-document=/dev/null "\$url"
else
    echo 'The server requires curl or wget to validate the Coolify callback URL.' >&2
    exit 1
fi
SH;
    }
}
