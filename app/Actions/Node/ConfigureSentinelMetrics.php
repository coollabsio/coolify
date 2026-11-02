<?php

namespace App\Actions\Node;

use App\Models\Node;
use Lorisleiva\Actions\Concerns\AsAction;

class ConfigureSentinelMetrics
{
    use AsAction;

    public function handle(Node $node, bool $enabled): void
    {
        instant_remote_process(
            [InstallSentinel::remoteCommand(self::configurationScript($enabled))],
            $node,
            timeout: 60,
            maxAttempts: 1,
        );
    }

    public static function configurationScript(bool $enabled): string
    {
        $value = $enabled ? 'true' : 'false';

        return <<<'SCRIPT'
set -eu
umask 077
environment=/etc/coolify/sentinel.env
test -f "$environment"
backup="$(mktemp "${environment}.backup.XXXXXX")"
staged="$(mktemp "${environment}.new.XXXXXX")"
trap 'rm -f "$backup" "$staged"' EXIT
cp -p "$environment" "$backup"
sed '/^COLLECTOR_ENABLED=/d; /^STORAGE_ENABLED=/d' "$environment" > "$staged"
SCRIPT
            ."\nprintf 'COLLECTOR_ENABLED=%s\\nSTORAGE_ENABLED=%s\\n' {$value} {$value} >> \"\$staged\"\n"
            .<<<'SCRIPT'
mv -f "$staged" "$environment"
if ! systemctl restart sentinel.service || ! systemctl is-active --quiet sentinel.service; then
    cp -p "$backup" "$environment"
    systemctl restart sentinel.service || true
    exit 1
fi
SCRIPT;
    }
}
