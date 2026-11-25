<?php

namespace Tests\Support;

use App\Models\Server;
use App\Services\DeployTriggers\AffectedRunner;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;
use App\Services\DeployTriggers\GitAccess;
use Closure;
use Throwable;

/**
 * Replays evaluation output for EvaluatePush tests and records the requests.
 */
final class FakeAffectedRunner implements AffectedRunner
{
    /** @var list<EvaluateRequest> */
    public array $requests = [];

    /** @var list<GitAccess> */
    public array $accesses = [];

    /** @var list<Server> */
    public array $servers = [];

    private function __construct(
        private readonly bool $supported,
        private readonly ?Closure $respond,
        private readonly ?Throwable $failure,
    ) {}

    /**
     * @param  string|Closure(EvaluateRequest): string  $response  fixture path, JSON, or a closure returning JSON
     */
    public static function respondWith(string|Closure $response): self
    {
        $respond = $response instanceof Closure
            ? $response
            : fn (EvaluateRequest $request): string => is_file($response) ? file_get_contents($response) : $response;

        return app()->instance(AffectedRunner::class, new self(true, $respond, null));
    }

    public static function unsupported(): self
    {
        return app()->instance(AffectedRunner::class, new self(false, null, null));
    }

    public static function failing(Throwable $failure): self
    {
        return app()->instance(AffectedRunner::class, new self(true, null, $failure));
    }

    public function supports(Server $server): bool
    {
        return $this->supported;
    }

    public function evaluate(Server $server, EvaluateRequest $request, GitAccess $access): EvaluateResponse
    {
        $this->requests[] = $request;
        $this->servers[] = $server;
        $this->accesses[] = $access;
        if ($this->failure) {
            throw $this->failure;
        }

        return EvaluateResponse::fromJson(($this->respond)($request), $request);
    }
}
