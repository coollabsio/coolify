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
    /** @var list<int> */
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

        $enabled = (bool) InstanceSettings::get()->is_smart_triggers_enabled
            && (bool) ($application->settings?->is_smart_triggers_enabled ?? true);

        return $enabled && $application->isNarrowable() ? TriggerRoute::Evaluate : TriggerRoute::DeployNow;
    }

    public function defer(Application $application): void
    {
        if (! in_array($application->id, $this->deferred, true)) {
            $this->deferred[] = $application->id;
        }
    }

    public function dispatch(): void
    {
        if ($this->deferred !== []) {
            EvaluatePush::dispatch($this->context, $this->deferred);
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
