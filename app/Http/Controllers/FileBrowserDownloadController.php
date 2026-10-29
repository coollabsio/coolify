<?php

namespace App\Http\Controllers;

use App\Models\Server;
use App\Services\ContainerFilesystemService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Streams a file browser download straight from the container to the browser.
 * Livewire download responses are buffered in memory and base64-encoded, so
 * FileBrowser::download() issues a one-time token and redirects here instead.
 */
class FileBrowserDownloadController extends Controller
{
    public const TOKEN_TTL_SECONDS = 60;

    public static function cacheKey(string $token): string
    {
        return 'file-browser-download:'.$token;
    }

    public function __invoke(string $token): StreamedResponse
    {
        /** @var array{user_id:int,resource_type:class-string,resource_id:int,server_id:int,container:string,path:string,name:string,is_dir:bool,use_helper?:bool}|null $download */
        $download = Cache::pull(self::cacheKey($token));
        abort_unless(is_array($download) && $download['user_id'] === auth()->id(), 404);

        Gate::authorize('canAccessTerminal');
        $resource = $download['resource_type']::findOrFail($download['resource_id']);
        Gate::authorize('view', $resource);
        abort_unless((int) $resource->environment->project->team_id === (int) auth()->user()->currentTeam()->id, 403);

        $filesystem = new ContainerFilesystemService(Server::findOrFail($download['server_id']), $download['container'], $download['use_helper'] ?? false);

        return response()->streamDownload(function () use ($filesystem, $download) {
            set_time_limit(0);
            $filesystem->streamDownload($download['path'], $download['is_dir'], function (string $chunk) {
                echo $chunk;
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            });
        }, $download['name'], [
            'Content-Type' => 'application/octet-stream',
            // Stop nginx from buffering the response to a temp file on the Coolify host.
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
