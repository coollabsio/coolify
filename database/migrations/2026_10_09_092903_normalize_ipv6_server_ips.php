<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

return new class extends Migration
{
    /**
     * Store every IPv6 server address in its short lower-case form, repair Hetzner servers whose
     * IPv6 network (2a01:4f8:c016:bd23::/64) was saved as 2a01:4f8:c016:bd23::64, and add the
     * missing brackets to Sentinel URLs that were saved as http://2a01:4f8::1:8000.
     */
    public function up(): void
    {
        DB::table('servers')
            ->select(['id', 'team_id', 'ip', 'hetzner_server_id'])
            ->where('ip', 'like', '%:%')
            ->orderBy('id')
            ->chunkById(100, function ($servers): void {
                foreach ($servers as $server) {
                    $this->normalizeServerIp($server);
                }
            });

        DB::table('server_settings')
            ->select(['id', 'sentinel_custom_url'])
            ->where('sentinel_custom_url', 'like', '%://%:%:%')
            ->orderBy('id')
            ->chunkById(100, function ($settings): void {
                foreach ($settings as $setting) {
                    $this->bracketSentinelUrlHost($setting);
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function normalizeServerIp(object $server): void
    {
        $ip = (string) $server->ip;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return;
        }

        $newIp = normalizeIpAddress($ip);
        // A Hetzner server that really uses ::64 is reachable; the broken network address is not.
        $isReachable = (bool) DB::table('server_settings')->where('server_id', $server->id)->value('is_reachable');
        if (! is_null($server->hetzner_server_id) && str_ends_with($ip, '::64') && ! $isReachable) {
            $newIp = hetznerServerIpv6(substr($ip, 0, -strlen('::64')).'::/64') ?? $newIp;
        }

        if ($newIp === $ip) {
            return;
        }

        // Coolify does not allow the same server IP twice, in any team.
        $duplicateExists = DB::table('servers')
            ->whereNull('deleted_at')
            ->where('id', '!=', $server->id)
            ->where('ip', $newIp)
            ->exists();
        if ($duplicateExists) {
            Log::warning('Skipped normalizing a server IPv6 address because another server already uses it.', [
                'server_id' => $server->id,
                'team_id' => $server->team_id,
                'ip' => $ip,
                'normalized_ip' => $newIp,
            ]);

            return;
        }

        DB::table('servers')->where('id', $server->id)->update(['ip' => $newIp]);
    }

    private function bracketSentinelUrlHost(object $setting): void
    {
        if (preg_match('#\A(https?)://([0-9A-Fa-f:.]+):(\d+)(/.*)?\z#', (string) $setting->sentinel_custom_url, $matches) !== 1) {
            return;
        }
        if (filter_var($matches[2], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return;
        }

        DB::table('server_settings')->where('id', $setting->id)->update([
            'sentinel_custom_url' => $matches[1].'://'.formatHostForUrl($matches[2]).':'.$matches[3].($matches[4] ?? ''),
        ]);
    }
};
