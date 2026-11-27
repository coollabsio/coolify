<?php

namespace App\Livewire\Project\ClusterApplication;

use App\Actions\Node\UpdateNodeWorkloadProxyOptions;
use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The proxy options of the domains of a cluster application or stack service (decision 0031), on
 * its Domains page: basic auth, an IP allow list, request and response headers, compression, and
 * the path strip. UpdateNodeWorkloadProxyOptions validates and saves them.
 */
class ProxyOptions extends Component
{
    use AuthorizesRequests;

    #[Locked]
    public NodeWorkload $workload;

    public bool $basicAuthEnabled = false;

    public string $basicAuthUsername = '';

    public string $basicAuthPassword = '';

    public string $allowedIps = '';

    public string $requestHeaders = '';

    public string $responseHeaders = '';

    public bool $gzipEnabled = true;

    public bool $stripPrefixEnabled = true;

    public function mount(NodeWorkload $workload): void
    {
        $this->authorize('view', $workload);
        $this->workload = $workload;
        $this->loadOptions();
    }

    public function save(): void
    {
        $this->authorize('update', $this->workload);
        $this->resetErrorBag();
        $requestHeaders = $this->parseHeaders($this->requestHeaders, 'requestHeaders');
        $responseHeaders = $this->parseHeaders($this->responseHeaders, 'responseHeaders');
        if ($requestHeaders === null || $responseHeaders === null) {
            return;
        }
        $input = [
            'is_http_basic_auth_enabled' => $this->basicAuthEnabled,
            'http_basic_auth_username' => $this->basicAuthUsername,
            'ingress_allowed_ips' => $this->allowedIps,
            'ingress_request_headers' => $requestHeaders,
            'ingress_response_headers' => $responseHeaders,
            'is_gzip_enabled' => $this->gzipEnabled,
            'is_stripprefix_enabled' => $this->stripPrefixEnabled,
        ];
        // The page shows the stored password, so only a new value changes the hash.
        if ($this->basicAuthPassword !== '' && $this->basicAuthPassword !== (string) $this->workload->http_basic_auth_password) {
            $input['http_basic_auth_password'] = $this->basicAuthPassword;
        }
        try {
            $this->workload = UpdateNodeWorkloadProxyOptions::run($this->workload, $input, auth()->user());
        } catch (ValidationException $exception) {
            $fields = [
                'http_basic_auth_username' => 'basicAuthUsername',
                'http_basic_auth_password' => 'basicAuthPassword',
                'ingress_allowed_ips' => 'allowedIps',
                'ingress_request_headers' => 'requestHeaders',
                'ingress_response_headers' => 'responseHeaders',
            ];
            foreach ($exception->errors() as $key => $messages) {
                $this->addError($fields[explode('.', $key)[0]] ?? 'proxyOptions', $messages[0]);
            }

            return;
        }
        $this->loadOptions();
        $this->dispatch('success', 'Proxy options saved. Ingress servers apply them without a redeploy.');
    }

    public function generatePassword(): void
    {
        $this->authorize('update', $this->workload);
        $this->basicAuthPassword = UpdateNodeWorkloadProxyOptions::generatePassword();
    }

    /**
     * `Name: Value` on each line, as the action takes them. Null after an error on the field.
     *
     * @return ?list<array{name: string, value: string}>
     */
    private function parseHeaders(string $text, string $field): ?array
    {
        $headers = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $number => $line) {
            if (trim($line) === '') {
                continue;
            }
            if (! str_contains($line, ':')) {
                $this->addError($field, 'Line '.($number + 1).' has no ":". Write each header as Name: Value.');

                return null;
            }
            [$name, $value] = explode(':', $line, 2);
            $headers[] = ['name' => trim($name), 'value' => trim($value)];
        }

        return $headers;
    }

    private function loadOptions(): void
    {
        $workload = $this->workload;
        $lines = fn (?array $headers): string => collect($headers ?? [])->map(fn (array $header): string => $header['name'].': '.$header['value'])->implode("\n");
        $this->basicAuthEnabled = (bool) $workload->is_http_basic_auth_enabled;
        $this->basicAuthUsername = (string) $workload->http_basic_auth_username;
        // Only a user who may change the options sees the password, like the v4 page.
        $this->basicAuthPassword = auth()->user()?->can('update', $workload) ? (string) $workload->http_basic_auth_password : '';
        $this->allowedIps = implode("\n", $workload->ingress_allowed_ips ?? []);
        $this->requestHeaders = $lines($workload->ingress_request_headers);
        $this->responseHeaders = $lines($workload->ingress_response_headers);
        $this->gzipEnabled = $workload->is_gzip_enabled !== false;
        $this->stripPrefixEnabled = $workload->is_stripprefix_enabled !== false;
    }

    public function render(): View
    {
        $clusters = NodeCluster::query()->whereKey($this->workload->clusterIds())->get();

        return view('livewire.project.cluster-application.proxy-options', [
            'supported' => $clusters->every(fn (NodeCluster $cluster): bool => $cluster->supportsIngressRouteOptions()),
            'hasPasswordHash' => filled($this->workload->http_basic_auth_password_hash),
        ]);
    }
}
