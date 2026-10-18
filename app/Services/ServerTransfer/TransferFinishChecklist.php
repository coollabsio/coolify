<?php

namespace App\Services\ServerTransfer;

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\ManagedDnsRecord;
use App\Models\ManagedDnsRecordReference;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\ServiceApplication;
use App\Models\User;
use App\Services\Dns\CloudflareDnsProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * The steps that finish a transfer on the instance that manages the server now: webhooks, DNS, Sentinel, backups,
 * and runners. Coolify checks each step where it can, and fixes some. The list is saved in
 * server_metadata.transfer.finish, so its state survives a reload and is the same for every user of the team.
 *
 * An item is {key, type, subject, label, status, message, checked_at}. Status: pending (not checked yet), ok,
 * action (the user must do something), done (the user marked it), or unchecked (Coolify cannot check it).
 */
class TransferFinishChecklist
{
    public const OPEN_STATUSES = ['pending', 'action', 'unchecked'];

    /** Types that the user can only mark as done. */
    private const MANUAL_TYPES = ['github_app_urls'];

    /** Types that Coolify can fix with one click. */
    private const FIX_LABELS = [
        'github_app_webhook' => 'Set webhook URL',
        'sentinel' => 'Restart Sentinel',
        'dns_record' => 'Take ownership',
    ];

    /**
     * Build the list for a server that this instance manages now. It replaces an earlier list.
     *
     * @return list<array<string, mixed>>
     */
    public function start(Server $server): array
    {
        $items = $this->buildItems($server);
        $this->save($server, [
            'started_at' => now()->toIso8601String(),
            'finished_at' => null,
            'finished_by' => null,
            'skipped' => [],
            'items' => $items,
        ]);

        return $items;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function of(Server $server): ?array
    {
        $finish = data_get($server->server_metadata, 'transfer.finish');

        return is_array($finish) ? $finish : null;
    }

    public function isOpen(Server $server): bool
    {
        $finish = $this->of($server);

        return $finish !== null && $finish['finished_at'] === null && ! $server->isTransferredAway();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function openItems(Server $server): array
    {
        return array_values(array_filter((array) data_get($this->of($server), 'items', []), fn (array $item) => in_array($item['status'], self::OPEN_STATUSES, true)));
    }

    public function canCheck(array $item): bool
    {
        return ! in_array($item['type'], self::MANUAL_TYPES, true);
    }

    public function fixLabel(array $item): ?string
    {
        return self::FIX_LABELS[$item['type']] ?? null;
    }

    public function check(Server $server, string $key): array
    {
        return $this->update($server, $key, fn (array $item) => $this->runCheck($server, $item));
    }

    public function checkAll(Server $server): void
    {
        foreach ($this->openItems($server) as $item) {
            if ($this->canCheck($item)) {
                $this->check($server, $item['key']);
            }
        }
    }

    public function fix(Server $server, string $key): array
    {
        return $this->update($server, $key, function (array $item) use ($server) {
            if ($this->fixLabel($item) === null) {
                throw new RuntimeException('Coolify cannot fix this step. Do it by hand, then mark it as done.');
            }
            $this->runFix($server, $item);

            return $this->runCheck($server, $item);
        });
    }

    public function markDone(Server $server, string $key): array
    {
        return $this->update($server, $key, fn (array $item) => ['done', 'Marked as done by '.(auth()->user()?->name ?? 'a user').'.']);
    }

    /**
     * The user finished the transfer. Open steps are not checked again; they are kept in the list as skipped.
     */
    public function finish(Server $server, User $user): void
    {
        $finish = $this->of($server) ?? throw new RuntimeException('This server has no transfer to finish.');
        $finish['finished_at'] = now()->toIso8601String();
        $finish['finished_by'] = $user->id;
        $finish['skipped'] = array_column($this->openItems($server), 'key');
        $this->save($server, $finish);
    }

    /**
     * Webhook controllers call this when a verified webhook arrives, so a check can see that the new URL works.
     */
    public static function recordWebhook(string $kind, ?string $uuid): void
    {
        if (filled($uuid)) {
            Cache::put("transfer-finish:webhook:{$kind}:{$uuid}", now()->toIso8601String(), now()->addDays(60));
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function buildItems(Server $server): array
    {
        $applications = $server->applications()->unique('id')->values();
        $serviceApplications = $server->services()->with('applications')->get()->flatMap->applications;
        $runnerConfig = $server->githubRunnerConfig()->with('githubApp')->first();
        $items = [
            $this->item('server_connection', 'server', 'Connection to the server'),
            $this->item('sentinel', 'server', 'Sentinel sends its data to this instance'),
        ];

        $githubApps = $applications->map(fn (Application $application) => $application->source)
            ->push($runnerConfig?->githubApp)
            ->filter(fn ($source) => $source instanceof GithubApp && ! $source->is_system_wide && ! $source->is_public)
            ->unique('id');
        foreach ($githubApps as $githubApp) {
            $items[] = $this->item('github_app_webhook', $githubApp->uuid, "Webhook URL of GitHub App {$githubApp->name}");
            $items[] = $this->item('github_app_urls', $githubApp->uuid, "Setup URL and Callback URL of GitHub App {$githubApp->name}",
                'GitHub has no API for these. In the settings of the GitHub App, set the Setup URL to '.$this->instanceUrl().'/webhooks/source/github/install '
                .'and the Callback URL to '.$this->instanceUrl().'/login/github/app.');
        }

        $gitlabApps = $applications->map(fn (Application $application) => $application->source)
            ->filter(fn ($source) => $source instanceof GitlabApp && ! $source->is_system_wide)
            ->unique('id');
        foreach ($gitlabApps as $gitlabApp) {
            $items[] = $this->item('gitlab_webhook', $gitlabApp->uuid, "GitLab webhooks of GitLab App {$gitlabApp->name}",
                "In GitLab, set the webhook URL of each project that uses it to {$this->instanceUrl()}/webhooks/source/gitlab/events, then push once.");
        }

        foreach ($applications as $application) {
            // A Docker image application keeps a default git_repository, but it never deploys from git.
            if ($application->build_pack !== 'dockerimage' && filled($application->git_repository) && (! $application->source || data_get($application->source, 'is_public') === true)) {
                $items[] = $this->item('manual_webhook', $application->uuid, "Manual webhook of {$application->name}",
                    "If its repository has a webhook to Coolify, change the URL to {$this->instanceUrl()}/webhooks/source/<github|gitlab|bitbucket|gitea>/events/manual, then push once.");
            }
        }

        foreach ($this->dnsRecords($applications, $serviceApplications) as $record) {
            $items[] = $this->item('dns_record', $record->uuid, "DNS record {$record->name}");
        }

        $hosts = $applications->flatMap(fn (Application $application) => $application->fqdns)
            ->concat($serviceApplications->flatMap(fn (ServiceApplication $application) => explode(',', (string) $application->fqdn)))
            ->map(fn ($url) => strtolower((string) parse_url(trim((string) $url), PHP_URL_HOST)))
            ->filter()
            ->unique();
        foreach ($hosts as $host) {
            $items[] = $this->item('domain', $host, "Domain {$host} points to the server");
        }

        foreach ($this->s3Storages($server) as $storage) {
            $items[] = $this->item('s3_storage', $storage->uuid, "S3 storage {$storage->name} works from this instance");
        }

        if ($runnerConfig) {
            $items[] = $this->item('github_runners', $runnerConfig->uuid, 'GitHub runners on this server',
                'They are disabled after a transfer. When the GitHub App sends its webhooks here, enable them on the GitHub runners page of the server.');
        }

        return $items;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function runCheck(Server $server, array $item): array
    {
        $startedAt = Carbon::parse((string) data_get($this->of($server), 'started_at', now()->toIso8601String()));

        return match ($item['type']) {
            'server_connection' => ($result = $server->validateConnection())['uptime']
                ? ['ok', 'This instance connects to the server over SSH.']
                : ['action', 'This instance cannot connect to the server: '.$result['error']],
            'sentinel' => $server->sentinel_updated_at && Carbon::parse($server->sentinel_updated_at)->isAfter($startedAt)
                ? ['ok', 'Sentinel sent data at '.Carbon::parse($server->sentinel_updated_at)->format('H:i').'.']
                : ['action', 'Sentinel did not send data to this instance since the transfer.'],
            'github_app_webhook' => $this->checkGithubAppWebhook($item['subject'], $startedAt),
            'gitlab_webhook' => $this->checkWebhookReceipt('gitlab_app', $item['subject'], $startedAt),
            'manual_webhook' => $this->checkWebhookReceipt('application', $item['subject'], $startedAt),
            'dns_record' => $this->checkDnsRecord($item['subject']),
            'domain' => $this->checkDomain($server, $item['subject']),
            's3_storage' => $this->checkS3Storage($item['subject']),
            'github_runners' => $server->githubRunnerConfig()->value('is_enabled')
                ? ['ok', 'GitHub runners are enabled.']
                : ['action', 'GitHub runners are disabled. Enable them on the GitHub runners page of the server.'],
            default => throw new RuntimeException('Coolify cannot check this step. Do it by hand, then mark it as done.'),
        };
    }

    private function runFix(Server $server, array $item): void
    {
        match ($item['type']) {
            'github_app_webhook' => $this->githubAppRequest(GithubApp::where('uuid', $item['subject'])->firstOrFail(), 'patch', '/app/hook/config', ['url' => $this->githubWebhookUrl()]),
            'sentinel' => $server->restartSentinel(),
            'dns_record' => app(CloudflareDnsProvider::class)->reclaimRecord(ManagedDnsRecord::where('uuid', $item['subject'])->firstOrFail())
                ? ManagedDnsRecord::where('uuid', $item['subject'])->update(['owned' => true])
                : throw new RuntimeException('Coolify could not take ownership: the record changed at Cloudflare, or Cloudflare cannot be reached.'),
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkGithubAppWebhook(string $uuid, Carbon $startedAt): array
    {
        $githubApp = GithubApp::where('uuid', $uuid)->first();
        if (! $githubApp) {
            return ['unchecked', 'The GitHub App does not exist on this instance anymore.'];
        }

        try {
            $url = (string) data_get($this->githubAppRequest($githubApp, 'get', '/app/hook/config'), 'url');
        } catch (Throwable $e) {
            return $this->checkWebhookReceipt('github_app', $uuid, $startedAt, 'GitHub cannot be asked ('.$e->getMessage().'). ');
        }

        return rtrim($url, '/') === $this->githubWebhookUrl()
            ? ['ok', "GitHub sends webhooks to {$url}."]
            : ['action', 'GitHub sends webhooks to '.($url ?: 'no URL').'. It must be '.$this->githubWebhookUrl().'.'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkWebhookReceipt(string $kind, string $uuid, Carbon $startedAt, string $prefix = ''): array
    {
        $receivedAt = Cache::get("transfer-finish:webhook:{$kind}:{$uuid}");
        if (is_string($receivedAt) && Carbon::parse($receivedAt)->isAfter($startedAt)) {
            return ['ok', $prefix.'This instance received a webhook at '.Carbon::parse($receivedAt)->format('H:i').'.'];
        }

        return ['action', $prefix.'This instance did not receive a webhook since the transfer. Change the webhook URL, then push once.'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkDnsRecord(string $uuid): array
    {
        $record = ManagedDnsRecord::where('uuid', $uuid)->first();
        if (! $record) {
            return ['unchecked', 'This instance does not track this record anymore.'];
        }
        if ($record->owned && app(CloudflareDnsProvider::class)->reclaimRecord($record)) {
            return ['ok', "This instance manages {$record->name} at Cloudflare."];
        }

        return ['action', $record->owned
            ? "Cloudflare does not confirm {$record->name}: the record changed or cannot be read."
            : "This instance does not own {$record->name} yet, so it does not change it. Take ownership, or mark it as done."];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkDomain(Server $server, string $host): array
    {
        $serverIps = filter_var($server->ip, FILTER_VALIDATE_IP) ? [$server->ip] : (gethostbynamel($server->ip) ?: []);
        $records = @dns_get_record($host, DNS_A | DNS_AAAA) ?: [];
        $ips = array_values(array_filter(array_map(fn (array $record) => $record['ip'] ?? $record['ipv6'] ?? null, $records)));
        if ($ips === []) {
            return ['action', "{$host} does not resolve."];
        }
        if (array_intersect($ips, $serverIps) !== []) {
            return ['ok', "{$host} resolves to the server."];
        }

        return ['action', "{$host} resolves to ".implode(', ', $ips).', not to the server ('.implode(', ', $serverIps ?: [$server->ip]).'). If a proxy or load balancer is in front, mark it as done.'];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function checkS3Storage(string $uuid): array
    {
        $storage = S3Storage::where('uuid', $uuid)->first();
        if (! $storage) {
            return ['unchecked', 'The S3 storage does not exist on this instance anymore.'];
        }
        try {
            $storage->testConnection(shouldSave: true);
        } catch (Throwable $e) {
            return ['action', 'This instance cannot use the S3 storage: '.$e->getMessage()];
        }

        return $storage->fresh()->is_usable ? ['ok', 'This instance can use the S3 storage.'] : ['action', 'This instance cannot use the S3 storage.'];
    }

    private function githubAppRequest(GithubApp $githubApp, string $method, string $path, array $data = []): array
    {
        $response = Http::GitSource($githubApp->api_url)
            ->withHeaders(['Authorization' => 'Bearer '.generateGithubJwt($githubApp), 'Accept' => 'application/vnd.github+json'])
            ->{$method}(rtrim($githubApp->api_url, '/').$path, $data);
        if (! $response->successful()) {
            throw new RuntimeException('GitHub answered with HTTP '.$response->status().': '.data_get($response->json(), 'message', 'no details'));
        }

        return (array) $response->json();
    }

    /**
     * @param  Collection<int, Application>  $applications
     * @param  Collection<int, ServiceApplication>  $serviceApplications
     * @return Collection<int, ManagedDnsRecord>
     */
    private function dnsRecords(Collection $applications, Collection $serviceApplications): Collection
    {
        $ids = ManagedDnsRecordReference::query()
            ->where(function ($query) use ($applications, $serviceApplications) {
                $query->where(fn ($q) => $q->where('resource_type', (new Application)->getMorphClass())->whereIn('resource_id', $applications->pluck('id')))
                    ->orWhere(fn ($q) => $q->where('resource_type', (new ServiceApplication)->getMorphClass())->whereIn('resource_id', $serviceApplications->pluck('id')));
            })
            ->pluck('managed_dns_record_id');

        return ManagedDnsRecord::query()->whereIn('id', $ids)->orderBy('name')->get();
    }

    /**
     * @return Collection<int, S3Storage>
     */
    private function s3Storages(Server $server): Collection
    {
        $ids = collect($server->databases())
            ->flatMap(fn ($database) => method_exists($database, 'scheduledBackups') ? $database->scheduledBackups()->get() : [])
            ->flatMap(fn ($backup) => [$backup->s3_storage_id, ...$backup->s3Storages()->pluck('s3_storages.id')->all()])
            ->filter()
            ->unique();

        return S3Storage::query()->whereIn('id', $ids)->where('team_id', $server->team_id)->get();
    }

    /**
     * @param  callable(array<string, mixed>): array{0: string, 1: string}  $change
     * @return array<string, mixed>
     */
    private function update(Server $server, string $key, callable $change): array
    {
        $finish = $this->of($server) ?? throw new RuntimeException('This server has no transfer to finish.');
        $index = array_search($key, array_column($finish['items'], 'key'), true);
        if ($index === false) {
            throw new RuntimeException('This step is not in the list.');
        }

        try {
            [$status, $message] = $change($finish['items'][$index]);
        } catch (Throwable $e) {
            [$status, $message] = ['action', $e->getMessage()];
        }

        return DB::transaction(function () use ($server, $key, $status, $message) {
            $finish = (array) data_get(Server::query()->lockForUpdate()->findOrFail($server->id)->server_metadata, 'transfer.finish');
            $index = array_search($key, array_column($finish['items'], 'key'), true);
            $finish['items'][$index] = [...$finish['items'][$index], 'status' => $status, 'message' => $message, 'checked_at' => now()->toIso8601String()];
            $this->save($server, $finish);

            return $finish['items'][$index];
        });
    }

    /**
     * Writes only transfer.finish, on the current row: callers can hold an older copy of the server, for example
     * from before the claim, and must not write its other metadata back.
     */
    private function save(Server $server, array $finish): void
    {
        DB::transaction(function () use ($server, $finish) {
            $locked = Server::query()->lockForUpdate()->findOrFail($server->id);
            $metadata = $locked->server_metadata ?? [];
            $metadata['transfer']['finish'] = $finish;
            $locked->server_metadata = $metadata;
            $locked->save();
            $server->server_metadata = $metadata;
            $server->syncOriginalAttribute('server_metadata');
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function item(string $type, string $subject, string $label, ?string $message = null): array
    {
        return [
            'key' => "{$type}:{$subject}",
            'type' => $type,
            'subject' => $subject,
            'label' => $label,
            'status' => 'pending',
            'message' => $message,
            'checked_at' => null,
        ];
    }

    private function instanceUrl(): string
    {
        return rtrim((string) (instanceSettings()->fqdn ?: config('app.url')), '/');
    }

    private function githubWebhookUrl(): string
    {
        return $this->instanceUrl().'/webhooks/source/github/events';
    }
}
