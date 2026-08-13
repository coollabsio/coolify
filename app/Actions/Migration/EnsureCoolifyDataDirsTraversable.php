<?php

namespace App\Actions\Migration;

use App\Models\Server;
use Lorisleiva\Actions\Concerns\AsAction;

class EnsureCoolifyDataDirsTraversable
{
    use AsAction;

    /**
     * Coolify's installer runs `chmod -R 700 /data/coolify`, so a non-root SSH user
     * cannot `cd` into `/data/coolify/services/<uuid>`. Directory execute (traverse)
     * is enough; files stay unreadable to others.
     */
    public function handle(Server $target): void
    {
        instant_remote_process(self::commands(), $target);
    }

    /**
     * @return list<string>
     */
    public static function commands(): array
    {
        $script = <<<'SH'
if [ -d /data/coolify ]; then
  find /data/coolify -type d -exec chmod a+x {} +
fi
SH;

        return [
            'sh -c '.escapeshellarg($script),
        ];
    }
}
