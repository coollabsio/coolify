<?php

/**
 * Generate random traffic analytics data on a dev server.
 *
 * Appends synthetic proxy access-log lines (Traefik or Caddy format, to match the
 * server's proxy) to the log that Sentinel reads, so every Analytics panel gets data:
 * requests, status codes, latency, visitors, countries and cities, browsers, bots,
 * referrers and paths, for the applications on the server.
 *
 * Sentinel counts a line at the time it reads it and ignores the timestamp in the line,
 * so the script sends one batch every --interval seconds for --minutes minutes. All data
 * is "now": the 7 days and 30 days views show it as one day.
 *
 * Requirements: traffic analytics is enabled on the server (Sentinel and the proxy run),
 * and the server can download the GeoIP database for countries and cities.
 *
 * Usage (in the Coolify container):
 *   ./scripts/dev exec php scripts/generate-traffic.php                      # burst of 3000, then 120 min at ~300/min
 *   ./scripts/dev exec php scripts/generate-traffic.php --minutes=0          # burst only
 *   ./scripts/dev exec php scripts/generate-traffic.php --server=<uuid> --rate=600 --burst=5000
 *
 * Options: --server=<id|uuid> (default 0, localhost), --minutes=120, --rate=300 (requests per minute),
 *          --burst=3000, --visitors=2500, --interval=15 (seconds between batches)
 */

use App\Actions\Server\StartSentinel;
use App\Enums\ProxyTypes;
use App\Helpers\SshMultiplexingHelper;
use App\Models\Application;
use App\Models\Server;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Process;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

if (! isDev()) {
    fwrite(STDERR, "This script writes fake traffic and runs only in a development instance.\n");
    exit(1);
}

$options = getopt('', ['server:', 'minutes:', 'rate:', 'burst:', 'visitors:', 'interval:', 'help']);
if (isset($options['help'])) {
    echo "Usage: php scripts/generate-traffic.php [--server=0] [--minutes=120] [--rate=300] [--burst=3000] [--visitors=2500] [--interval=15]\n";
    exit(0);
}

$serverKey = (string) ($options['server'] ?? '0');
$minutes = (float) ($options['minutes'] ?? 120);
$rate = (int) ($options['rate'] ?? 300);
$burst = (int) ($options['burst'] ?? 3000);
$visitorCount = (int) ($options['visitors'] ?? 2500);
$interval = max(1, (int) ($options['interval'] ?? 15));

$server = ctype_digit($serverKey) ? Server::find((int) $serverKey) : Server::where('uuid', $serverKey)->first();
if (! $server) {
    fwrite(STDERR, "Server {$serverKey} not found.\n");
    exit(1);
}

$proxyType = $server->proxyType();
if (! in_array($proxyType, [ProxyTypes::TRAEFIK->value, ProxyTypes::CADDY->value], true)) {
    fwrite(STDERR, "Server {$server->name} uses proxy {$proxyType}. Sentinel reads only Traefik or Caddy access logs.\n");
    exit(1);
}
if (! $server->settings->is_traffic_analytics_enabled) {
    fwrite(STDERR, "Warning: traffic analytics is not enabled on {$server->name}; Sentinel will not read the lines.\n");
}

/** @var array<string, int> $apps uuid => weight */
$apps = [];
/** @var array<string, string> $hosts uuid => request host */
$hosts = [];
$position = 0;
foreach (Application::query()->with('destination.server')->get() as $application) {
    if ($application->destination?->server?->id !== $server->id) {
        continue;
    }
    $apps[$application->uuid] = max(1, 30 - $position * 3);
    $host = parse_url((string) str($application->fqdn)->before(',')->trim(), PHP_URL_HOST);
    $hosts[$application->uuid] = $host ?: $application->uuid.'.example.com';
    $position++;
}
if ($apps === []) {
    fwrite(STDERR, "Server {$server->name} has no applications. Traffic is attributed per application.\n");
    exit(1);
}

$logPath = StartSentinel::trafficLogDirectory($server).'/access.log';
$isCaddy = $proxyType === ProxyTypes::CADDY->value;

echo "Server: {$server->name} ({$proxyType}), ".count($apps)." applications, log: {$logPath}\n";

/** Large residential ISP ranges; GeoIP resolves the real country and city. */
$networks = [
    '73.0.0.0/8' => 22,      // US Comcast
    '98.0.0.0/10' => 6,      // US
    '91.0.0.0/10' => 12,     // DE Telekom
    '90.0.0.0/9' => 8,       // FR Orange
    '86.128.0.0/10' => 7,    // GB BT
    '93.32.0.0/11' => 6,     // IT Fastweb
    '83.32.0.0/11' => 5,     // ES Telefonica
    '77.160.0.0/12' => 4,    // NL KPN
    '84.0.0.0/14' => 4,      // HU Magyar Telekom
    '126.0.0.0/8' => 5,      // JP SoftBank
    '121.128.0.0/10' => 3,   // KR KT
    '49.36.0.0/14' => 6,     // IN Jio
    '177.0.0.0/8' => 5,      // BR
    '1.120.0.0/13' => 3,     // AU Telstra
    '70.24.0.0/13' => 3,     // CA Bell
    '78.56.0.0/13' => 2,     // LT
    '81.240.0.0/12' => 2,    // BE Proximus
    '83.0.0.0/11' => 2,      // PL
];

$userAgents = [
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' => 30,
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Safari/605.1.15' => 12,
    'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36' => 12,
    'Mozilla/5.0 (iPhone; CPU iPhone OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1' => 14,
    'Mozilla/5.0 (Linux; Android 15; Pixel 9) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Mobile Safari/537.36' => 10,
    'Mozilla/5.0 (X11; Linux x86_64; rv:144.0) Gecko/20100101 Firefox/144.0' => 5,
    'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/141.0.0.0 Safari/537.36 Edg/141.0.0.0' => 5,
    'Mozilla/5.0 (iPad; CPU OS 18_4 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.4 Mobile/15E148 Safari/604.1' => 2,
    'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' => 3,
    'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' => 1,
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)' => 2,
    'Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; ClaudeBot/1.0; +claudebot@anthropic.com)' => 1,
    'curl/8.14.1' => 1,
];

$paths = [
    '/' => 30, '/about' => 5, '/pricing' => 6, '/blog' => 5, '/blog/launch-week' => 3,
    '/blog/self-hosting-guide' => 2, '/docs' => 6, '/docs/getting-started' => 4, '/login' => 4,
    '/dashboard' => 6, '/api/users' => 5, '/api/items' => 6, '/api/items/42' => 3, '/api/health' => 4,
    '/assets/app.js' => 8, '/assets/app.css' => 7, '/favicon.ico' => 5, '/robots.txt' => 1,
    '/wp-login.php' => 1, '/.env' => 1,
];

$referers = [
    '' => 45, 'https://www.google.com/' => 25, 'https://news.ycombinator.com/' => 6, 'https://x.com/' => 5,
    'https://github.com/' => 6, 'https://www.reddit.com/' => 5, 'https://duckduckgo.com/' => 4, 'https://t.co/' => 4,
];

$statuses = [200 => 780, 304 => 80, 301 => 30, 302 => 20, 404 => 50, 401 => 10, 403 => 10, 500 => 10, 502 => 6, 503 => 4];
$protocols = ['HTTP/2.0' => 60, 'HTTP/1.1' => 30, 'HTTP/3.0' => 10];

/**
 * @param  array<string|int, int|float>  $weights  value => weight
 */
function pickWeighted(array $weights): string|int
{
    $roll = mt_rand() / mt_getrandmax() * array_sum($weights);
    foreach ($weights as $value => $weight) {
        $roll -= $weight;
        if ($roll <= 0) {
            return $value;
        }
    }

    return array_key_last($weights);
}

function randomIp(string $cidr): string
{
    [$network, $bits] = explode('/', $cidr);
    $size = 2 ** (32 - (int) $bits);

    return long2ip(ip2long($network) + random_int(1, $size - 2));
}

function logNormal(float $mu, float $sigma): float
{
    $u1 = max(mt_rand() / mt_getrandmax(), 1e-12);
    $u2 = mt_rand() / mt_getrandmax();

    return exp($mu + $sigma * sqrt(-2 * log($u1)) * cos(2 * M_PI * $u2));
}

// A fixed pool of visitors (IP + browser + favorite app), with a few heavy users.
$visitors = [];
$visitorWeights = [];
for ($i = 0; $i < $visitorCount; $i++) {
    $visitors[] = [
        'ip' => randomIp(pickWeighted($networks)),
        'ua' => pickWeighted($userAgents),
        'app' => pickWeighted($apps),
    ];
    $visitorWeights[$i] = (1 - mt_rand() / (mt_getrandmax() + 1)) ** (-1 / 1.3);
}

$makeLine = function (array $visitor) use ($apps, $hosts, $paths, $referers, $statuses, $protocols, $isCaddy): string {
    $path = pickWeighted($paths);
    $status = in_array($path, ['/wp-login.php', '/.env'], true) ? 404 : pickWeighted($statuses);
    $app = random_int(1, 100) <= 85 ? $visitor['app'] : pickWeighted($apps);
    $isApi = str_starts_with($path, '/api');
    $bytesOut = in_array($status, [301, 302, 304], true) ? 0 : ($isApi ? random_int(80, 900) : (int) logNormal(9, 1.2));
    $bytesIn = random_int(0, 600);
    $durationMs = logNormal(log($isApi ? 60 : 35), 0.9) * ($status >= 500 ? 8 : 1);
    $method = $isApi && random_int(1, 100) <= 20 ? 'POST' : 'GET';
    $protocol = pickWeighted($protocols);
    $referer = pickWeighted($referers);

    if ($isCaddy) {
        $headers = ['User-Agent' => [$visitor['ua']]];
        if ($referer !== '') {
            $headers['Referer'] = [$referer];
        }

        return json_encode([
            'ts' => microtime(true),
            'status' => $status,
            'duration' => $durationMs / 1000,
            'size' => $bytesOut,
            'bytes_read' => $bytesIn,
            'coolify_app_id' => $app,
            'request' => [
                'method' => $method,
                'uri' => $path,
                'host' => $hosts[$app],
                'proto' => $protocol,
                'remote_ip' => $visitor['ip'],
                'client_ip' => $visitor['ip'],
                'headers' => $headers,
                'tls' => ['version' => 772],
            ],
        ], JSON_UNESCAPED_SLASHES);
    }

    return json_encode([
        'StartUTC' => now()->utc()->format('Y-m-d\TH:i:s.u\Z'),
        'RequestMethod' => $method,
        'RequestPath' => $path,
        'RequestHost' => $hosts[$app],
        'DownstreamStatus' => $status,
        'DownstreamContentSize' => $bytesOut,
        'RequestContentSize' => $bytesIn,
        'Duration' => (int) ($durationMs * 1_000_000),
        'RequestProtocol' => $protocol,
        'RequestScheme' => 'https',
        'TLSVersion' => '1.3',
        'RouterName' => "https-0-{$app}@docker",
        'ClientHost' => $visitor['ip'],
        'request_User-Agent' => $visitor['ua'],
        'request_Referer' => $referer,
    ], JSON_UNESCAPED_SLASHES);
};

$batch = function (int $count) use ($visitors, $visitorWeights, $makeLine): string {
    $lines = [];
    for ($i = 0; $i < $count; $i++) {
        $lines[] = $makeLine($visitors[pickWeighted($visitorWeights)]);
    }

    return implode("\n", $lines)."\n";
};

$remoteCommand = $server->isNonRoot()
    ? 'sudo tee -a '.escapeshellarg($logPath).' > /dev/null'
    : 'cat >> '.escapeshellarg($logPath);

$send = function (string $payload) use ($server, $remoteCommand): void {
    Process::input($payload)
        ->timeout(120)
        ->run(SshMultiplexingHelper::generateSshStdinCommand($server, $remoteCommand))
        ->throw();
};

if ($burst > 0) {
    $send($batch($burst));
    echo "Burst: {$burst} requests\n";
}

$start = time();
$end = $start + (int) ($minutes * 60);
while (time() < $end) {
    // A slow wave plus noise and an occasional spike, so the requests chart is not flat.
    $wave = 1 + 0.5 * sin((time() - $start) / 1800 * M_PI);
    $spike = random_int(1, 100) <= 3 ? 4 : 1;
    $count = max(1, (int) round($rate * $interval / 60 * $wave * $spike * (0.85 + random_int(0, 30) / 100)));

    try {
        $send($batch($count));
        echo now()->format('H:i:s')." sent {$count}\n";
    } catch (Throwable $e) {
        fwrite(STDERR, 'Send failed: '.$e->getMessage()."\n");
    }

    sleep($interval);
}
