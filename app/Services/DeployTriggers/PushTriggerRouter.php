<?php

namespace App\Services\DeployTriggers;

use App\Jobs\EvaluatePush;
use App\Models\Application;
use App\Models\InstanceSettings;

/**
 * Decides per application whether a webhook push deploys now or is evaluated first (spec §2).
 */
final class PushTriggerRouter
{
    /** @var array<int, list<int>> team id => application ids */
    private array $deferred = [];

    private function __construct(private readonly PushContext $context) {}

    public static function forPush(PushContext $context): self
    {
        return new self($context);
    }

    public function route(Application $application): TriggerRoute
    {
        if (! $application->git_based()) {
            return TriggerRoute::DeployNow;
        }

        if (filled($application->watch_paths)) {
            return TriggerRoute::Evaluate;
        }

        $enabled = (bool) (InstanceSettings::query()->whereKey(0)->value('is_smart_triggers_enabled') ?? true)
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
