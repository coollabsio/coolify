<?php

namespace App\Livewire\Node;

use App\Actions\Node\AssignNodeToCluster;
use App\Actions\Node\CreateNodeCluster;
use App\Actions\Node\InspectNodeHost;
use App\Actions\Node\ValidateNodeCallback;
use App\Jobs\OnboardNodeJob;
use App\Models\Node;
use App\Models\NodeCluster;
use App\Models\PrivateKey;
use App\Rules\PrivateIpv4Cidr;
use App\Rules\ValidServerIp;
use App\Support\ValidationPatterns;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

class Onboarding extends Component
{
    use AuthorizesRequests;

    public int $step = 1;

    public string $name = '';

    public string $ip = '';

    public string $user = 'root';

    public int $port = 22;

    public ?int $privateKeyId = null;

    public string $coolifyUrl = '';

    public string $clusterMode = 'existing';

    public string $clusterUuid = '';

    public string $clusterName = '';

    #[Url(as: 'node', except: '')]
    public ?string $nodeUuid = null;

    /** @var array<string, mixed> */
    public array $inspection = [];

    public function mount(): void
    {
        abort_unless(isDev() && config('constants.sentinel.host_enabled', false), 404);
        $this->authorize('create', Node::class);

        if ($this->nodeUuid !== null) {
            $this->restoreOnboardingNode();

            return;
        }

        $this->name = generate_random_name();
        $this->coolifyUrl = rtrim((string) config('app.url'), '/');
        $this->privateKeyId = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->value('id');
        $firstCluster = NodeCluster::query()->where('team_id', currentTeam()->id)->orderBy('name')->first();
        if ($firstCluster === null) {
            $this->clusterMode = 'new';
            $this->clusterName = 'My cluster';
        } else {
            $this->clusterUuid = $firstCluster->uuid;
        }
    }

    public function connect(): void
    {
        $this->authorize('create', Node::class);
        $validated = $this->validate([
            'name' => ValidationPatterns::nameRules(),
            'ip' => ['required', 'string', new ValidServerIp, Rule::unique('nodes', 'ip')->where('team_id', currentTeam()->id)],
            'user' => ValidationPatterns::serverUsernameRules(),
            'port' => ['required', 'integer', 'between:1,65535'],
            'privateKeyId' => ['required', 'integer'],
            'coolifyUrl' => ['required', 'url', 'starts_with:http://,https://'],
        ]);
        $privateKey = PrivateKey::ownedAndOnlySShKeys()->whereKey($validated['privateKeyId'])->firstOrFail();
        $callbackUrl = $this->callbackUrlFor($validated['ip'], $validated['coolifyUrl']);
        $this->coolifyUrl = $callbackUrl;
        $node = Node::query()->create([
            'team_id' => currentTeam()->id,
            'private_key_id' => $privateKey->id,
            'name' => $validated['name'],
            'ip' => $validated['ip'],
            'user' => $validated['user'],
            'port' => $validated['port'],
            'sentinel_url' => $callbackUrl,
            'metadata' => ['onboarding' => ['status' => 'inspecting', 'step' => 'connect', 'label' => 'Inspecting server']],
        ]);

        try {
            $this->inspection = InspectNodeHost::run($node);
        } catch (Throwable $exception) {
            $node->delete();
            report($exception);
            $this->addError('ip', 'Coolify could not connect to this server. Check the address, SSH key, user, and port.');

            return;
        }

        try {
            ValidateNodeCallback::run($node, $callbackUrl);
        } catch (Throwable $exception) {
            $node->delete();
            report($exception);
            $this->addError('coolifyUrl', 'This server could not reach Coolify through the callback URL. Check the URL, protocol, port, DNS, and firewall.');

            return;
        }

        $node->update(['metadata' => [...$this->inspection, 'onboarding' => ['status' => 'review', 'step' => 'review', 'label' => 'Ready to install']]]);
        $this->nodeUuid = $node->uuid;
        $this->step = 2;
    }

    public function install(): void
    {
        $node = $this->onboardingNode();
        $this->authorize('update', $node);
        $validated = $this->validate([
            'clusterMode' => ['required', Rule::in(['existing', 'new'])],
            'clusterUuid' => [
                $this->clusterMode === 'existing' ? 'required' : 'nullable',
                'string',
                Rule::exists('node_clusters', 'uuid')->where('team_id', currentTeam()->id),
            ],
            'clusterName' => [$this->clusterMode === 'new' ? 'required' : 'nullable', 'string', 'max:255'],
        ]);

        $cluster = $validated['clusterMode'] === 'new'
            ? CreateNodeCluster::run(currentTeam(), auth()->user(), $validated['clusterName'])
            : NodeCluster::query()->where('team_id', currentTeam()->id)->where('uuid', $validated['clusterUuid'])->firstOrFail();

        AssignNodeToCluster::run($cluster, $node);
        $this->setQueued($node);
        OnboardNodeJob::dispatch($node->id, auth()->id());
        $this->step = 3;
    }

    public function retry(): void
    {
        $node = $this->onboardingNode();
        $this->authorize('update', $node);
        abort_if($node->cluster === null, 422, 'Select a cluster before retrying installation.');
        $this->setQueued($node);
        OnboardNodeJob::dispatch($node->id, auth()->id());
        $this->step = 3;
    }

    public function refreshStatus(): void
    {
        if ($this->nodeUuid === null) {
            return;
        }
        $node = $this->onboardingNode();
        if (data_get($node->metadata, 'onboarding.status') === 'ready') {
            $this->step = 3;
        }
    }

    public function render(): View
    {
        $privateKeys = PrivateKey::ownedAndOnlySShKeys()->where('id', '!=', 0)->orderBy('name')->get(['id', 'name']);
        $clusters = NodeCluster::query()->where('team_id', currentTeam()->id)->orderBy('name')->get();
        $node = $this->nodeUuid ? Node::query()->where('team_id', currentTeam()->id)->where('uuid', $this->nodeUuid)->first() : null;
        $onboarding = data_get($node?->metadata, 'onboarding', []);

        return view('livewire.node.onboarding', compact('privateKeys', 'clusters', 'node', 'onboarding'));
    }

    private function onboardingNode(): Node
    {
        return Node::query()->where('team_id', currentTeam()->id)->where('uuid', $this->nodeUuid)->firstOrFail();
    }

    private function setQueued(Node $node): void
    {
        $node->refresh();
        $node->update(['metadata' => [
            ...($node->metadata ?? []),
            'onboarding' => ['status' => 'queued', 'step' => 'queued', 'label' => 'Waiting to start', 'error' => null, 'updated_at' => now()->toIso8601String()],
        ]]);
        Cache::forget($node->cacheKey());
    }

    private function restoreOnboardingNode(): void
    {
        $node = $this->onboardingNode();
        $this->authorize('view', $node);
        $onboardingStatus = data_get($node->metadata, 'onboarding.status');

        $this->name = $node->name;
        $this->ip = $node->ip;
        $this->user = $node->user;
        $this->port = $node->port;
        $this->privateKeyId = $node->private_key_id;
        $this->coolifyUrl = $node->sentinel_url ?? '';
        $this->inspection = collect($node->metadata ?? [])->except('onboarding')->all();

        if ($node->cluster !== null) {
            $this->clusterMode = 'existing';
            $this->clusterUuid = $node->cluster->uuid;
        }

        $this->step = match ($onboardingStatus) {
            'review' => 2,
            'queued', 'running', 'failed', 'ready' => 3,
            default => 1,
        };
    }

    private function callbackUrlFor(string $ip, string $configuredUrl): string
    {
        if (! isDev() || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            return rtrim($configuredUrl, '/');
        }

        $range = PrivateIpv4Cidr::range((string) config('development-qemu.subnet'));
        $address = (int) sprintf('%u', ip2long($ip));
        if ($address < $range['start'] || $address > $range['end']) {
            return rtrim($configuredUrl, '/');
        }

        $gateway = config('development-qemu.gateway');
        $port = config('development-qemu.coolify_host_port');

        return "http://{$gateway}:{$port}";
    }
}
