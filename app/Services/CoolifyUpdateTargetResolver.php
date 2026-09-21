<?php

namespace App\Services;

use App\Models\Server;
use Closure;
use RuntimeException;

class CoolifyUpdateTargetResolver
{
    private const ROLLING_IMAGE = 'next';

    private const ROLLING_VERSION_PATTERN = '/^\d+\.\d+-rc\.\d+\.[0-9a-f]{7}$/i';

    private Closure $remoteProcess;

    /**
     * @param Closure(array<int, string>, Server): ?string|null $remoteProcess
     */
    public function __construct(?Closure $remoteProcess = null)
    {
        $this->remoteProcess = $remoteProcess ?? static function (array $command, Server $server): ?string {
            return instant_remote_process($command, $server);
        };
    }

    public function isRollingChannel(): bool
    {
        return config('constants.coolify.latest_image') === self::ROLLING_IMAGE;
    }

    public static function isRollingBuildVersion(?string $version): bool
    {
        return is_string($version) && preg_match(self::ROLLING_VERSION_PATTERN, $version) === 1;
    }

    public function resolve(Server $server): string
    {
        if (! $this->isRollingChannel()) {
            throw new RuntimeException('The Coolify instance is not using the rolling update channel.');
        }

        $platform = $this->platformFor($server);
        $command = preg_split(
            '/\R/',
            $this->buildInspectCommand($this->rollingImageReference(), $platform, $server->isNonRoot()),
            flags: PREG_SPLIT_NO_EMPTY
        );
        if ($command === false) {
            throw new RuntimeException('Unable to build the rolling Coolify image inspection command.');
        }

        $output = ($this->remoteProcess)($command, $server);
        $version = trim((string) $output);
        if (! self::isRollingBuildVersion($version)) {
            $version = $this->versionFromInspectJson((string) $output, $platform) ?? $version;
        }

        if (! self::isRollingBuildVersion($version)) {
            throw new RuntimeException("The rolling Coolify image did not expose a valid COOLIFY_VERSION: {$version}");
        }

        return $version;
    }

    public function buildInspectCommand(string $image, string $platform, bool $nonRoot = false): string
    {
        $filter = <<<'JQ'
def normalized_platform:
  sub("/v[0-9]+$"; "");

($platform | normalized_platform) as $target
| if (has("Config") or has("config")) then .
  elif has($platform) then .[$platform]
  elif has($target) then .[$target]
  else first(to_entries[] | select((.key | normalized_platform) == $target) | .value)
  end
| (.Config.Env // .config.Env // [])
| first(.[] | select(startswith("COOLIFY_VERSION=")) | sub("^COOLIFY_VERSION="; ""))
JQ;

        $helperImage = escapeshellarg(coolifyHelperImage().':'.getHelperVersion());
        $inspectOutput = '/tmp/coolify-update-target-$$.json';
        $dockerConfigTest = $nonRoot ? 'sudo test' : 'test';
        $dockerRun = 'docker run --rm -v /var/run/docker.sock:/var/run/docker.sock';
        $inspectArguments = $helperImage.' docker buildx imagetools inspect '.escapeshellarg($image).
            ' --format '.escapeshellarg('{{json .Image}}');

        return 'if '.$dockerConfigTest.' -f /root/.docker/config.json; then'.PHP_EOL.
            '    '.$dockerRun.' -v /root/.docker/config.json:/root/.docker/config.json:ro '.$inspectArguments.' > '.$inspectOutput.PHP_EOL.
            'else'.PHP_EOL.
            '    '.$dockerRun.' '.$inspectArguments.' > '.$inspectOutput.PHP_EOL.
            'fi'.PHP_EOL.
            'jq -r --arg platform '.escapeshellarg($platform).' '.escapeshellarg($filter).' '.$inspectOutput.PHP_EOL.
            'rm -f '.$inspectOutput;
    }

    public function rollingImageReference(): string
    {
        return rtrim(coolifyRegistryUrl(), '/').'/coollabsio/coolify:'.self::ROLLING_IMAGE;
    }

    public function platformFor(Server $server): string
    {
        $architecture = data_get($server->server_metadata, 'arch');
        $architecture = is_string($architecture) && $architecture !== '' && $architecture !== 'Unknown'
            ? $architecture
            : php_uname('m');

        return match (strtolower($architecture)) {
            'x86_64', 'amd64' => 'linux/amd64',
            'aarch64', 'arm64' => 'linux/arm64',
            'armv7l', 'armv7' => 'linux/arm/v7',
            'armv6l', 'armv6' => 'linux/arm/v6',
            'i386', 'i686', '386' => 'linux/386',
            'ppc64le' => 'linux/ppc64le',
            's390x' => 'linux/s390x',
            default => throw new RuntimeException("Unsupported server architecture: {$architecture}"),
        };
    }

    private function versionFromInspectJson(string $output, string $platform): ?string
    {
        try {
            $images = json_decode($output, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($images)) {
            return null;
        }

        $image = $this->selectPlatformImage($images, $platform);
        $environment = data_get($image, 'Config.Env') ?? data_get($image, 'config.Env');
        if (! is_array($environment)) {
            return null;
        }

        foreach ($environment as $entry) {
            if (is_string($entry) && str_starts_with($entry, 'COOLIFY_VERSION=')) {
                return substr($entry, strlen('COOLIFY_VERSION='));
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $images
     * @return array<string, mixed>|null
     */
    private function selectPlatformImage(array $images, string $platform): ?array
    {
        if (array_key_exists('Config', $images) || array_key_exists('config', $images)) {
            return $images;
        }

        $normalizedPlatform = $this->normalizePlatform($platform);
        foreach ([$platform, $normalizedPlatform] as $platformKey) {
            if (isset($images[$platformKey]) && is_array($images[$platformKey])) {
                return $images[$platformKey];
            }
        }

        foreach ($images as $key => $image) {
            if (! is_array($image)) {
                continue;
            }

            if ($this->normalizePlatform((string) $key) === $normalizedPlatform) {
                return $image;
            }

            $imageOs = (string) (data_get($image, 'Platform.os') ?? data_get($image, 'os') ?? '');
            $imageArchitecture = (string) (data_get($image, 'Platform.architecture') ?? data_get($image, 'architecture') ?? '');
            $imagePlatform = $imageOs !== '' && $imageArchitecture !== '' ? "{$imageOs}/{$imageArchitecture}" : '';
            if ($imagePlatform !== '' && $this->normalizePlatform($imagePlatform) === $normalizedPlatform) {
                return $image;
            }
        }

        return null;
    }

    private function normalizePlatform(string $platform): string
    {
        return preg_replace('/\/v\d+$/', '', $platform) ?? $platform;
    }
}
