<?php

namespace App\Actions\Node;

use App\Models\NodeBuild;
use App\Models\NodeRegistry;
use App\Models\NodeWorkload;
use App\Models\NodeWorkloadRevision;
use App\Rules\SafeWebhookUrl;
use App\Support\CpuArchitecture;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;
use Throwable;

/**
 * The CPU architectures of an image (decision 0020), or null when Coolify cannot read them. Null fits
 * every Node, so a registry that is down or a private image without a login does not block a deploy.
 *
 * - A Coolify build has the architecture of its builder Node (`node_builds.architecture`).
 * - Another image has the platforms of its registry manifest: each `linux` entry of an index, or the
 *   architecture in the config of a single manifest. Coolify reads it over HTTPS with the Docker
 *   Registry v2 API, with the registry login of the workload when it has one, and only from hosts
 *   that SafeWebhookUrl allows. A redirect to another host (a blob CDN) gets no Authorization header.
 *
 * The digest of a tag is cached for 10 minutes (a HEAD request, which Docker Hub does not count as a
 * pull), and the architectures of a digest for 30 days. A failure is cached for 5 minutes.
 */
class ReadImageArchitectures
{
    use AsAction;

    private const ACCEPT = 'application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, '
        .'application/vnd.oci.image.manifest.v1+json, application/vnd.docker.distribution.manifest.v2+json';

    private const REFERENCE_TTL = 600;

    private const DIGEST_TTL = 2_592_000;

    private const FAILURE_TTL = 300;

    private const MAX_REDIRECTS = 3;

    /** How long the application page shows that the last read failed, unless a later read succeeds. */
    private const WARNING_TTL = 604_800;

    /** Why the last read failed, or null after a read that worked. */
    public ?string $lastError = null;

    private string $registry;

    private string $repository;

    /** @var array{username: string, password: string}|null */
    private ?array $login;

    private ?string $authorization;

    /**
     * @param  array{username: string, password: string}|null  $login
     * @return list<string>|null
     */
    public function handle(string $image, ?array $login = null): ?array
    {
        $image = CreateClusterDockerImageWorkload::qualifiedImage(trim($image));
        if (NodeRegistry::isLocalImage($image) || str_starts_with($image, NodeRegistry::HOST.'/')) {
            return self::builtArchitectures($image);
        }
        [$this->registry, $this->repository, $reference] = self::parse($image);
        $this->login = $login;
        $this->authorization = null;
        $this->lastError = null;
        $identity = $login === null ? 'anonymous' : hash_hmac('sha256', $login['username']."\0".$login['password'], (string) config('app.key'));
        $referenceKey = 'image-architectures:ref:'.hash('sha256', "{$this->registry}/{$this->repository}@{$reference}\0{$identity}");
        $cached = Cache::get($referenceKey);
        if (is_array($cached)) {
            $this->lastError = $cached['error'] ?? null;

            return $cached['architectures'];
        }

        try {
            $architectures = $this->read($reference, $identity);
        } catch (Throwable $exception) {
            Log::warning('Coolify could not read the CPU architectures of an image. Every server fits it.', [
                'image' => $image,
                'error' => mb_substr($exception->getMessage(), 0, 300),
            ]);
            $architectures = null;
            $this->lastError = mb_substr($exception->getMessage(), 0, 300);
        }
        Cache::put($referenceKey, ['architectures' => $architectures, 'error' => $this->lastError], $architectures === null ? self::FAILURE_TTL : self::REFERENCE_TTL);

        return $architectures;
    }

    /**
     * The architectures of the image of a revision, read with the registry login of its workload.
     *
     * @return list<string>|null
     */
    public static function forRevision(NodeWorkloadRevision $revision): ?array
    {
        $workload = $revision->workload;
        // A build records the architecture of its builder, also when it is stored in an external registry.
        if ($workload?->buildsFromSource()) {
            return self::builtArchitectures($revision->image);
        }
        try {
            $login = $workload === null || $workload->buildsFromSource() ? null : IssueNodeRegistryCredential::run($workload, $revision->image);
        } catch (RuntimeException) {
            $login = null;
        }

        $reader = app(self::class);
        $architectures = $reader->handle($revision->image, $login);
        if ($workload !== null) {
            $reader->lastError === null
                ? Cache::forget(self::warningKey($workload))
                : Cache::put(self::warningKey($workload), ['image' => $revision->image, 'error' => $reader->lastError], self::WARNING_TTL);
        }

        return $architectures;
    }

    /**
     * Why Coolify could not read the CPU architectures of the image of the workload at the last
     * check, for the warning on the application page. Reads only the cache.
     *
     * @return array{image: string, error: string}|null
     */
    public static function warning(NodeWorkload $workload): ?array
    {
        $warning = Cache::get(self::warningKey($workload));

        return is_array($warning) ? $warning : null;
    }

    private static function warningKey(NodeWorkload $workload): string
    {
        return "image-architectures:warning:{$workload->id}";
    }

    /**
     * The architectures of the latest revision of a workload. Null for a workload without a revision,
     * such as a Git application before its first build: it builds for the Node it is placed on.
     *
     * @return list<string>|null
     */
    public static function forWorkload(NodeWorkload $workload): ?array
    {
        $revision = $workload->revisions()->latest('id')->first();

        return $revision === null ? null : self::forRevision($revision);
    }

    /**
     * The registry API host, the repository, and the tag or digest of a qualified image reference.
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public static function parse(string $image): array
    {
        [$registry, $path] = explode('/', $image, 2);
        $reference = 'latest';
        if (str_contains($path, '@')) {
            [$path, $reference] = explode('@', $path, 2);
            $path = preg_replace('/:[^\/:]+$/', '', $path) ?? $path;
        } elseif (preg_match('/^(.+):([^\/:]+)$/', $path, $matches) === 1) {
            [, $path, $reference] = $matches;
        }

        return [$registry === 'docker.io' ? 'registry-1.docker.io' : $registry, $path, $reference];
    }

    /** @return list<string>|null */
    private static function builtArchitectures(string $image): ?array
    {
        $digest = str_contains($image, '@') ? substr($image, strrpos($image, '@') + 1) : null;
        $architecture = NodeBuild::query()
            ->when($digest === null, fn ($query) => $query->where('image', $image), fn ($query) => $query->where('image_digest', $digest))
            ->whereNotNull('architecture')
            ->latest('id')
            ->value('architecture');

        return $architecture === null ? null : [$architecture];
    }

    /** @return list<string>|null */
    private function read(string $reference, string $identity): ?array
    {
        $digest = str_starts_with($reference, 'sha256:') ? $reference : null;
        if ($digest === null) {
            $head = $this->request('HEAD', "manifests/{$reference}", ['Accept' => self::ACCEPT]);
            $digest = $head->successful() ? ($head->header('Docker-Content-Digest') ?: null) : null;
        }
        $digestKey = $digest === null ? null : 'image-architectures:digest:'.hash('sha256', "{$this->registry}/{$this->repository}@{$digest}\0{$identity}");
        if ($digestKey !== null && is_array($cached = Cache::get($digestKey))) {
            return $cached;
        }

        $response = $this->request('GET', 'manifests/'.($digest ?? $reference), ['Accept' => self::ACCEPT]);
        $manifest = $response->json();
        if (! $response->successful() || ! is_array($manifest)) {
            throw new RuntimeException("The registry answered {$response->status()} for the manifest.");
        }
        $architectures = $this->manifestArchitectures($manifest);
        if ($architectures !== null && $digestKey !== null) {
            Cache::put($digestKey, $architectures, self::DIGEST_TTL);
        }

        return $architectures;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>|null
     */
    private function manifestArchitectures(array $manifest): ?array
    {
        if (is_array($manifest['manifests'] ?? null)) {
            // Attestation entries have the platform unknown/unknown, which normalizes to null.
            $architectures = collect($manifest['manifests'])
                ->map(fn ($entry): mixed => is_array($entry) ? ($entry['platform'] ?? null) : null)
                ->filter(fn (mixed $platform): bool => is_array($platform) && ($platform['os'] ?? 'linux') === 'linux')
                ->map(fn (array $platform): ?string => CpuArchitecture::normalize($platform['architecture'] ?? null, $platform['variant'] ?? null))
                ->filter()
                ->unique()
                ->values()
                ->all();

            return $architectures === [] ? null : $architectures;
        }
        // Schema 1 has the architecture in the manifest itself.
        $architecture = $manifest['architecture'] ?? null;
        if (! is_string($architecture)) {
            $configDigest = data_get($manifest, 'config.digest');
            if (! is_string($configDigest) || ! str_starts_with($configDigest, 'sha256:')) {
                return null;
            }
            $config = $this->request('GET', "blobs/{$configDigest}", []);
            if (! $config->successful() || ! is_array($config->json())) {
                throw new RuntimeException("The registry answered {$config->status()} for the image config.");
            }
            $architecture = CpuArchitecture::normalize($config->json('architecture'), $config->json('variant'));
        } else {
            $architecture = CpuArchitecture::normalize($architecture);
        }

        return $architecture === null ? null : [$architecture];
    }

    /**
     * A registry API call. A 401 challenge gets one retry with a Bearer token from its realm or with Basic auth.
     *
     * @param  array<string, string>  $headers
     */
    private function request(string $method, string $path, array $headers): Response
    {
        $url = "https://{$this->registry}/v2/{$this->repository}/{$path}";
        $response = $this->send($method, $url, $headers);
        if ($response->status() === 401 && $this->authorization === null && $this->authorize((string) $response->header('WWW-Authenticate'))) {
            $response = $this->send($method, $url, $headers);
        }

        return $response;
    }

    /**
     * Follows redirects itself, so that each target passes the SafeWebhookUrl check and only the
     * registry host gets the Authorization header.
     *
     * @param  array<string, string>  $headers
     */
    private function send(string $method, string $url, array $headers): Response
    {
        for ($redirects = 0; ; $redirects++) {
            $toRegistry = parse_url($url, PHP_URL_HOST) === parse_url("https://{$this->registry}", PHP_URL_HOST);
            $response = self::client($url)
                ->withHeaders([...$headers, ...($this->authorization !== null && $toRegistry ? ['Authorization' => $this->authorization] : [])])
                ->send($method, $url);
            $location = $response->header('Location');
            if (! $response->redirect() || $location === '' || $redirects >= self::MAX_REDIRECTS) {
                return $response;
            }
            $url = str_starts_with($location, '/') ? 'https://'.parse_url($url, PHP_URL_HOST).(($port = parse_url($url, PHP_URL_PORT)) ? ":{$port}" : '').$location : $location;
        }
    }

    /** Answers a 401 challenge. Returns whether a retry can succeed. */
    private function authorize(string $challenge): bool
    {
        if (stripos($challenge, 'basic') === 0) {
            if ($this->login === null) {
                return false;
            }
            $this->authorization = 'Basic '.base64_encode($this->login['username'].':'.$this->login['password']);

            return true;
        }
        preg_match_all('/(\w+)="([^"]*)"/', $challenge, $matches, PREG_SET_ORDER);
        $parameters = collect($matches)->mapWithKeys(fn (array $match): array => [strtolower($match[1]) => $match[2]])->all();
        $realm = $parameters['realm'] ?? '';
        if (stripos($challenge, 'bearer') !== 0 || $realm === '') {
            return false;
        }
        $query = http_build_query(array_filter([
            'service' => $parameters['service'] ?? null,
            'scope' => $parameters['scope'] ?? "repository:{$this->repository}:pull",
        ]));
        $tokenUrl = $realm.(str_contains($realm, '?') ? '&' : '?').$query;
        $request = self::client($tokenUrl);
        if ($this->login !== null) {
            $request = $request->withBasicAuth($this->login['username'], $this->login['password']);
        }
        $response = $request->get($tokenUrl);
        $token = $response->json('token') ?? $response->json('access_token');
        if (! $response->successful() || ! is_string($token) || $token === '') {
            throw new RuntimeException("The registry token service answered {$response->status()}.");
        }
        $this->authorization = 'Bearer '.$token;

        return true;
    }

    /** HTTPS only, pinned to the addresses that SafeWebhookUrl allows, without automatic redirects. */
    private static function client(string $url): PendingRequest
    {
        if (! str_starts_with($url, 'https://')) {
            throw new RuntimeException('The registry sent a URL that is not HTTPS.');
        }

        return Http::withOptions(SafeWebhookUrl::httpClientOptions($url, allowPrivateNetworks: SafeWebhookUrl::gitSourcesMayUsePrivateNetworks()))
            ->connectTimeout(5)
            ->timeout(10);
    }
}
