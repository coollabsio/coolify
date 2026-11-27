<?php

namespace App\Actions\Node;

use App\Models\NodeCluster;
use App\Models\NodeWorkload;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Lorisleiva\Actions\Concerns\AsAction;

/**
 * Saves the proxy options of the domains of a cluster workload (decision 0031): basic auth, an IP
 * allow list, request and response headers, compression, and the path strip. The ingress Nodes
 * apply them without a redeploy. Sentinel checks the same rules again.
 */
class UpdateNodeWorkloadProxyOptions
{
    use AsAction;

    public const MAX_ALLOWED_IPS = 32;

    public const MAX_HEADERS = 16;

    public const MAX_HEADER_VALUE = 1024;

    /** Bcrypt only, with the cost that the shared ingress allows (10 to 12). */
    public const BCRYPT_COST = 10;

    /** Connection, framing, proxy, and forwarding headers. Caddy or the proxy sets them. */
    public const FORBIDDEN_HEADERS = [
        'host', 'connection', 'upgrade', 'keep-alive', 'transfer-encoding', 'content-length', 'content-encoding',
        'te', 'trailer', 'forwarded', 'via', 'x-real-ip', 'alt-svc', 'http2-settings',
    ];

    public const FORBIDDEN_HEADER_PREFIXES = ['proxy-', 'x-forwarded-'];

    /**
     * @param  array{is_http_basic_auth_enabled?: mixed, http_basic_auth_username?: mixed, http_basic_auth_password?: mixed, ingress_allowed_ips?: mixed, ingress_request_headers?: mixed, ingress_response_headers?: mixed, is_gzip_enabled?: mixed, is_stripprefix_enabled?: mixed}  $input  Changed options only. A password is set only when it is not empty.
     *
     * @throws ValidationException with the key of each field.
     */
    public function handle(NodeWorkload $workload, array $input, User $user): NodeWorkload
    {
        $values = self::validated($workload, $input, $user);
        $changed = DB::transaction(fn (): bool => self::apply($workload, $values));

        $workload->refresh();
        if ($changed) {
            NodeCluster::query()
                ->whereKey($workload->clusterIds())
                ->get()
                ->each(fn (NodeCluster $cluster) => QueueNodeClusterNetworkRevision::run($cluster, $user));
        }

        return $workload;
    }

    /**
     * Checks the authorization and every rule, and writes nothing.
     *
     * @param  array<string, mixed>  $input  As for handle().
     * @return array<string, mixed> The column values to save with apply().
     *
     * @throws ValidationException with the key of each field.
     */
    public static function validated(NodeWorkload $workload, array $input, User $user): array
    {
        Gate::forUser($user)->authorize('update', $workload);
        if ($workload->isDatabase()) {
            throw ValidationException::withMessages(['ingress' => 'A database has no domain, so it has no proxy options.']);
        }
        $input = collect($input)->only(['is_http_basic_auth_enabled', 'http_basic_auth_username', 'http_basic_auth_password', 'ingress_allowed_ips', 'ingress_request_headers', 'ingress_response_headers', 'is_gzip_enabled', 'is_stripprefix_enabled'])->all();
        if (array_key_exists('ingress_allowed_ips', $input) && is_string($input['ingress_allowed_ips'])) {
            $input['ingress_allowed_ips'] = self::splitList($input['ingress_allowed_ips']);
        }
        foreach (['ingress_request_headers', 'ingress_response_headers'] as $key) {
            if (isset($input[$key]) && is_array($input[$key])) {
                $input[$key] = array_values(array_filter(
                    array_map(fn ($header): array => ['name' => trim((string) ($header['name'] ?? '')), 'value' => trim((string) ($header['value'] ?? ''))], $input[$key]),
                    fn (array $header): bool => $header['name'] !== '' || $header['value'] !== '',
                ));
            }
        }
        if (array_key_exists('http_basic_auth_username', $input)) {
            $input['http_basic_auth_username'] = trim((string) $input['http_basic_auth_username']);
        }

        $validator = Validator::make($input, [
            'is_http_basic_auth_enabled' => ['sometimes', 'boolean'],
            'http_basic_auth_username' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/\A[\x20-\x39\x3b-\x7e]*\z/'],
            'http_basic_auth_password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:72', 'regex:/\A[\x20-\x7e]*\z/'],
            'ingress_allowed_ips' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_ALLOWED_IPS],
            'ingress_allowed_ips.*' => ['string', 'max:64'],
            'ingress_request_headers' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_HEADERS],
            'ingress_response_headers' => ['sometimes', 'nullable', 'array', 'max:'.self::MAX_HEADERS],
            'is_gzip_enabled' => ['sometimes', 'boolean'],
            'is_stripprefix_enabled' => ['sometimes', 'boolean'],
        ], [
            'http_basic_auth_username.regex' => 'The username can have printable ASCII characters, but not ":".',
            'http_basic_auth_password.min' => 'The password must have at least 8 characters.',
            'http_basic_auth_password.max' => 'The password can have at most 72 characters, because bcrypt reads only 72 bytes.',
            'http_basic_auth_password.regex' => 'The password can have printable ASCII characters only.',
            'ingress_allowed_ips.max' => 'Use at most '.self::MAX_ALLOWED_IPS.' IP addresses or ranges.',
            'ingress_request_headers.max' => 'Set at most '.self::MAX_HEADERS.' request headers.',
            'ingress_response_headers.max' => 'Set at most '.self::MAX_HEADERS.' response headers.',
        ]);
        $allowedIps = null;
        $validator->after(function ($validator) use ($input, $workload, &$allowedIps): void {
            if (isset($input['ingress_allowed_ips']) && is_array($input['ingress_allowed_ips']) && ! $validator->errors()->has('ingress_allowed_ips')) {
                $allowedIps = [];
                foreach ($input['ingress_allowed_ips'] as $value) {
                    $range = self::normalizeRange((string) $value);
                    if ($range === null) {
                        $validator->errors()->add('ingress_allowed_ips', "{$value} is not an IP address or a CIDR range, like 203.0.113.0/24.");

                        return;
                    }
                    $allowedIps[] = $range;
                }
                $allowedIps = array_values(array_unique($allowedIps));
            }
            foreach (['ingress_request_headers' => 'request', 'ingress_response_headers' => 'response'] as $key => $label) {
                if (isset($input[$key]) && is_array($input[$key]) && ! $validator->errors()->has($key)) {
                    $error = self::headerError($input[$key], $label);
                    if ($error !== null) {
                        $validator->errors()->add($key, $error);
                    }
                }
            }
            $enabled = (bool) ($input['is_http_basic_auth_enabled'] ?? $workload->is_http_basic_auth_enabled);
            if ($enabled) {
                $username = $input['http_basic_auth_username'] ?? $workload->http_basic_auth_username;
                if (blank($username)) {
                    $validator->errors()->add('http_basic_auth_username', 'Enter a username for basic auth.');
                }
                if (blank($input['http_basic_auth_password'] ?? null) && blank($workload->http_basic_auth_password_hash)) {
                    $validator->errors()->add('http_basic_auth_password', 'Enter a password for basic auth, or generate one.');
                }
            }
        });
        $validator->validate();

        $values = [];
        foreach (['is_http_basic_auth_enabled', 'is_gzip_enabled', 'is_stripprefix_enabled'] as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = (bool) $input[$key];
            }
        }
        if (array_key_exists('http_basic_auth_username', $input)) {
            $values['http_basic_auth_username'] = $input['http_basic_auth_username'] === '' ? null : $input['http_basic_auth_username'];
        }
        if (filled($input['http_basic_auth_password'] ?? null) && $input['http_basic_auth_password'] !== $workload->http_basic_auth_password) {
            // One hash for each password, so the routes do not change on each network revision.
            $values['http_basic_auth_password'] = $input['http_basic_auth_password'];
            $values['http_basic_auth_password_hash'] = password_hash($input['http_basic_auth_password'], PASSWORD_BCRYPT, ['cost' => self::BCRYPT_COST]);
        }
        if (array_key_exists('ingress_allowed_ips', $input)) {
            $values['ingress_allowed_ips'] = ($allowedIps ?? []) === [] ? null : $allowedIps;
        }
        foreach (['ingress_request_headers', 'ingress_response_headers'] as $key) {
            if (array_key_exists($key, $input)) {
                $values[$key] = blank($input[$key]) ? null : $input[$key];
            }
        }

        return $values;
    }

    /**
     * Saves the values of validated(). Call it in a transaction: it locks the workload row.
     *
     * @param  array<string, mixed>  $values
     * @return bool True when the routes of the workload changed, so the clusters need a network revision.
     *
     * @throws ValidationException when a cluster of the workload cannot apply a protected option.
     */
    public static function apply(NodeWorkload $workload, array $values): bool
    {
        $locked = NodeWorkload::query()->lockForUpdate()->findOrFail($workload->id);
        $locked->fill($values);
        if ($locked->hasProtectedIngressOptions()) {
            $unsupported = NodeCluster::query()->whereKey($locked->clusterIds())->get()
                ->first(fn (NodeCluster $cluster): bool => ! $cluster->supportsIngressRouteOptions());
            if ($unsupported !== null) {
                throw ValidationException::withMessages(['ingress' => "Basic auth and the IP allow list need Sentinel with ingress.route_options.v1 on every server of the cluster {$unsupported->name}. Upgrade Sentinel on the servers of the cluster first."]);
            }
        }
        $dirty = $locked->isDirty();
        $locked->save();

        return $dirty && $locked->hasIngressRoutes();
    }

    /** A random password for basic auth: 24 letters and digits. */
    public static function generatePassword(): string
    {
        return Str::password(24, symbols: false);
    }

    /**
     * Addresses and ranges from a text with one or more per line, separated by commas or spaces.
     *
     * @return list<string>
     */
    public static function splitList(string $text): array
    {
        return array_values(array_filter(preg_split('/[\s,]+/', $text) ?: [], fn (string $value): bool => $value !== ''));
    }

    /**
     * The canonical form of an IP address or a CIDR range, with the host bits cleared, as Sentinel
     * accepts it. Null when it is not one.
     */
    public static function normalizeRange(string $value): ?string
    {
        $value = trim($value);
        [$address, $prefix] = str_contains($value, '/') ? explode('/', $value, 2) : [$value, null];
        $binary = @inet_pton($address);
        if ($binary === false || filter_var($address, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $bits = strlen($binary) * 8;
        if ($prefix === null) {
            return (string) inet_ntop($binary);
        }
        if (! ctype_digit($prefix) || strlen($prefix) > 3 || (int) $prefix > $bits) {
            return null;
        }
        $prefix = (int) $prefix;
        if ($prefix === $bits) {
            return (string) inet_ntop($binary);
        }
        $masked = '';
        foreach (str_split($binary) as $index => $byte) {
            $keep = max(0, min(8, $prefix - $index * 8));
            $masked .= chr(ord($byte) & ((0xFF << (8 - $keep)) & 0xFF));
        }

        return inet_ntop($masked).'/'.$prefix;
    }

    /**
     * The first rule that a header list breaks, as Sentinel checks it, or null.
     *
     * @param  list<array{name: string, value: string}>  $headers
     */
    public static function headerError(array $headers, string $label): ?string
    {
        $names = [];
        foreach ($headers as $header) {
            $name = (string) ($header['name'] ?? '');
            $value = (string) ($header['value'] ?? '');
            if ($name === '' || strlen($name) > 64 || preg_match("/\A[A-Za-z0-9!#$%&'*+.^_`|~-]+\z/", $name) !== 1) {
                return "{$name} is not a valid {$label} header name. Use letters, digits, and - like X-Frame-Options.";
            }
            $lower = strtolower($name);
            if (in_array($lower, self::FORBIDDEN_HEADERS, true) || Str::startsWith($lower, self::FORBIDDEN_HEADER_PREFIXES)) {
                return "The {$label} header {$name} cannot be set. The ingress sets connection, framing, proxy, and forwarding headers itself.";
            }
            if (isset($names[$lower])) {
                return "The {$label} header {$name} is set more than once.";
            }
            $names[$lower] = true;
            if (strlen($value) > self::MAX_HEADER_VALUE || preg_match('/\A[\x20-\x7a\x7c\x7e]*\z/', $value) !== 1) {
                return "The value of the {$label} header {$name} can have at most ".self::MAX_HEADER_VALUE.' printable ASCII characters, without { or }.';
            }
        }

        return null;
    }
}
