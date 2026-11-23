<?php

namespace App\Services;

use App\Helpers\SshMultiplexingHelper;
use App\Models\Server;
use App\Models\User;
use App\Support\ValidationPatterns;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

class TerminalSessionService
{
    private const TOKEN_TTL_SECONDS = 60;

    /**
     * Lifetime of a live session record: the 8 h cap of the terminal server plus a margin.
     */
    private const LIVE_SESSION_TTL_SECONDS = 8 * 60 * 60 + 600;

    /**
     * @param  'shell'|'attach'  $mode  Container terminal mode: docker exec or docker attach.
     * @param  string|null  $clientIp  Browser IP. Later requests come from the terminal server, not the browser.
     * @param  string|null  $userAgent  Browser user agent, for the same reason.
     */
    public function issue(User $user, Server $server, ?string $container = null, string $mode = 'shell', ?string $clientIp = null, ?string $userAgent = null): string
    {
        $token = Str::random(64);

        Cache::put($this->cacheKey($token), [
            'user_id' => $user->id,
            'team_id' => $user->currentTeam()->id,
            'server_uuid' => $server->uuid,
            'container' => $container,
            'mode' => $mode === 'attach' ? 'attach' : 'shell',
            'client_ip' => $clientIp,
            'user_agent' => $userAgent,
        ], now()->addSeconds(self::TOKEN_TTL_SECONDS));

        return $token;
    }

    /**
     * Redeem a single-use token. The session id is a secret for the terminal server only:
     * it uses it to check the session again while it runs and to report its end.
     *
     * @return array{command: string, session_id: string}
     */
    public function redeem(User $user, string $token): array
    {
        $payload = Cache::lock($this->cacheKey($token).':lock', 5)
            ->get(fn () => Cache::pull($this->cacheKey($token)));

        if (! is_array($payload)
            || data_get($payload, 'user_id') !== $user->id
            || data_get($payload, 'team_id') !== $user->currentTeam()->id
            || ! is_string(data_get($payload, 'server_uuid'))
            || ! array_key_exists('container', $payload)) {
            throw new AccessDeniedHttpException('Invalid or expired terminal token.');
        }

        $server = Server::ownedByCurrentTeam()
            ->whereUuid(data_get($payload, 'server_uuid'))
            ->with('privateKey', 'settings', 'team.subscription')
            ->firstOrFail();

        if (! $server->isTerminalEnabled()
            || ! $this->serverTeamHasSubscription($server)
            || $server->isForceDisabled()
            || $server->isTransferredAway()
            || ! $server->privateKey
            || $server->privateKey->team_id !== $user->currentTeam()->id) {
            throw new AccessDeniedHttpException('Terminal target is not authorized.');
        }

        $command = $this->shellCommand();
        $container = $payload['container'];

        if ($container !== null) {
            if (! is_string($container)
                || ! ValidationPatterns::isValidContainerName($container)
                || getContainerStatus($server, $container) !== 'running') {
                throw new AccessDeniedHttpException('Terminal container is not authorized.');
            }

            $command = $this->containerCommand($server, $container, data_get($payload, 'mode') === 'attach' ? 'attach' : 'shell');
        }

        $sshCommand = SshMultiplexingHelper::generateSshCommand(
            $server,
            $command,
            commandTimeout: (int) config('constants.terminal.command_timeout')
        );

        $sessionId = Str::random(64);
        $session = [
            'user_id' => $user->id,
            'team_id' => $server->team_id,
            'server_uuid' => $server->uuid,
            'server_name' => $server->name,
            'container' => $container,
            'mode' => $container === null ? 'host' : (data_get($payload, 'mode') === 'attach' ? 'attach' : 'shell'),
            'client_ip' => data_get($payload, 'client_ip'),
            'user_agent' => data_get($payload, 'user_agent'),
            'started_at' => now()->getTimestamp(),
        ];
        Cache::put($this->liveSessionKey($sessionId), $session, now()->addSeconds(self::LIVE_SESSION_TTL_SECONDS));

        auditLog('terminal.session.started', $this->auditContext($session, 'started'));

        return ['command' => $sshCommand, 'session_id' => $sessionId];
    }

    /**
     * Check again that the user may keep a live session open. Uses the team of the server,
     * not the session team, because the user can switch teams while the terminal runs.
     */
    public function check(User $user, string $sessionId): void
    {
        $session = Cache::get($this->liveSessionKey($sessionId));

        if (! is_array($session) || data_get($session, 'user_id') !== $user->id) {
            throw new AccessDeniedHttpException('Terminal session is not active.');
        }

        $server = Server::query()
            ->where('team_id', data_get($session, 'team_id'))
            ->whereUuid(data_get($session, 'server_uuid'))
            ->with('settings', 'team.subscription')
            ->first();

        if (! $server
            || ! $user->isAdminOfTeam($server->team_id)
            || ($server->team?->is_two_factor_required && blank($user->two_factor_confirmed_at))
            || ! $this->serverTeamHasSubscription($server)
            || ! $server->isTerminalEnabled()
            || $server->isForceDisabled()
            || $server->isTransferredAway()) {
            throw new AccessDeniedHttpException('Terminal session is no longer authorized.');
        }
    }

    /**
     * On cloud, the team of the server must have an active subscription or one that ends at the
     * period end, as in DecideWhatToDoWithUser. The root team is exempt. Self-hosted is not limited.
     */
    private function serverTeamHasSubscription(Server $server): bool
    {
        if (! isCloud() || $server->team_id === 0) {
            return true;
        }

        $subscription = $server->team?->subscription;

        return $subscription !== null
            && isStripe()
            && ((bool) $subscription->stripe_invoice_paid || (bool) $subscription->stripe_cancel_at_period_end);
    }

    /**
     * Record the end of a live session. Called by the terminal server, also after a logout,
     * so it does not need the browser session. Unknown or ended sessions are ignored.
     */
    public function end(string $sessionId, string $reason): void
    {
        $session = Cache::pull($this->liveSessionKey($sessionId));

        if (! is_array($session)) {
            return;
        }

        // The request comes from the terminal server; record the event as the session user.
        auth()->onceUsingId($session['user_id']);

        auditLog('terminal.session.ended', [
            ...$this->auditContext($session, 'ended'),
            'reason' => $reason,
            'duration_seconds' => max(0, now()->getTimestamp() - (int) data_get($session, 'started_at')),
        ]);
    }

    /**
     * @param  array<string, mixed>  $session
     * @return array<string, mixed>
     */
    private function auditContext(array $session, string $action): array
    {
        $target = $session['container'] ?? 'host';

        return [
            'resource' => 'server',
            'team_id' => $session['team_id'],
            'user_id' => $session['user_id'],
            'server_uuid' => $session['server_uuid'],
            'server_name' => $session['server_name'],
            'container' => $session['container'],
            'mode' => $session['mode'],
            'actor_ip' => $session['client_ip'],
            'actor_user_agent' => $session['user_agent'] ?? null,
            'audit_description' => "Terminal session {$action} on {$session['server_name']} ({$target})",
        ];
    }

    /**
     * Build the docker command that runs on the remote server for the given mode.
     *
     * @param  'shell'|'attach'  $mode
     */
    public function containerCommand(Server $server, string $container, string $mode): string
    {
        $escapedContainer = escapeshellarg($container);
        // Add sudo for non-root users to access the Docker socket.
        $sudo = $server->isNonRoot() ? 'sudo ' : '';

        if ($mode === 'attach') {
            $detachKeys = config('constants.terminal.detach_keys');
            $historyLines = (int) config('constants.terminal.console_history_lines');

            // Print recent output first so the console is not blank, then attach for live output.
            $primeHistory = $historyLines > 0
                ? "{$sudo}docker logs --tail {$historyLines} {$escapedContainer} 2>&1; "
                : '';

            // Attach to the container's main process. --detach-keys lets the user leave with
            // Ctrl-P, Ctrl-Q and --sig-proxy=false stops the client forwarding signals to the app.
            return "{$primeHistory}exec {$sudo}docker attach --detach-keys=\"{$detachKeys}\" --sig-proxy=false {$escapedContainer}";
        }

        return "{$sudo}docker exec -it {$escapedContainer} sh -c ".escapeshellarg($this->shellCommand());
    }

    private function shellCommand(): string
    {
        return 'PATH=$PATH:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin && '.
            'if [ -f ~/.profile ]; then . ~/.profile; fi && '.
            'if [ -n "$SHELL" ] && [ -x "$SHELL" ] && [ "${SHELL##*/}" != nologin ] && [ "${SHELL##*/}" != false ]; then exec $SHELL; '.
            'elif command -v bash >/dev/null 2>&1; then exec bash; else sh; fi';
    }

    private function cacheKey(string $token): string
    {
        return "terminal-session:{$token}";
    }

    private function liveSessionKey(string $sessionId): string
    {
        return "terminal-live-session:{$sessionId}";
    }
}
