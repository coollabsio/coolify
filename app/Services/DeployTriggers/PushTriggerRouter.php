<?php

namespace App\Services\DeployTriggers;

use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\InstanceSettings;
use App\Services\DeployTriggers\Contract\Sha;

/**
 * Decides per application whether a webhook push deploys now or is evaluated first (spec §2).
 */
final class PushTriggerRouter
{
    /** @var array<int, list<int>> team id => application ids */
    private array $deferred = [];

    private ?bool $instanceEnabled = null;

    /** @var array<int, TriggerRoute> */
    private array $routes = [];

    private function __construct(private readonly PushContext $context) {}

    public function isPullRequest(): bool
    {
        return $this->context->pullRequest !== null;
    }

    public static function forPush(PushContext $context): self
    {
        return new self($context);
    }

    public function route(Application $application): TriggerRoute
    {
        return $this->routes[$application->id] ??= $this->decideRoute($application);
    }

    private function decideRoute(Application $application): TriggerRoute
    {
        if (! $application->git_based() || ! Sha::isValid($this->context->after)) {
            return TriggerRoute::DeployNow;
        }

        if (filled($application->watch_paths)) {
            return TriggerRoute::Evaluate;
        }

        $this->instanceEnabled ??= (bool) (InstanceSettings::query()->whereKey(0)->value('is_smart_triggers_enabled') ?? true);
        $enabled = $this->instanceEnabled
            && (bool) ($application->settings?->is_smart_triggers_enabled ?? true);

        return $enabled && $application->isNarrowable() ? TriggerRoute::Evaluate : TriggerRoute::DeployNow;
    }

    public function defer(Application $application): void
    {
        $teamId = (int) ($application->team()?->id ?? $this->context->teamId);
        if (! in_array($application->id, $this->deferred[$teamId] ?? [], true)) {
            $this->deferred[$teamId][] = $application->id;
        }
    }

    public function dispatch(): void
    {
        foreach ($this->deferred as $teamId => $applicationIds) {
            EvaluatePush::dispatch($this->context->withTeam($teamId), $applicationIds);
        }
    }

    public function hasDeferred(): bool
    {
        return $this->deferred !== [];
    }

    /**
     * @return array{application: string, status: 'evaluating', message: string, application_uuid: string}
     */
    public function responseEntry(Application $application): array
    {
        return [
            'application' => $application->name,
            'status' => 'evaluating',
            'message' => 'Coolify checks whether this push changes files the application uses.',
            'application_uuid' => $application->uuid,
        ];
    }
}
