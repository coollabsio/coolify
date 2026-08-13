<?php

namespace App\Models;

use App\Enums\InstanceMigrationStatus;
use Database\Factories\InstanceMigrationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InstanceMigration extends BaseModel
{
    /** @use HasFactory<InstanceMigrationFactory> */
    use HasFactory;

    protected $fillable = [
        'uuid',
        'team_id',
        'status',
        'target_ip',
        'target_port',
        'target_user',
        'target_private_key_id',
        'old_host_ip',
        'package_paths',
        'phases',
        'items',
        'error',
        'dashboard_url',
        'dry_run',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => InstanceMigrationStatus::class,
            'package_paths' => 'encrypted:array',
            'phases' => 'array',
            'items' => 'array',
            'dry_run' => 'boolean',
            'target_port' => 'integer',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function targetPrivateKey(): BelongsTo
    {
        return $this->belongsTo(PrivateKey::class, 'target_private_key_id');
    }

    public function markPhase(InstanceMigrationStatus $status, ?string $note = null): void
    {
        $phases = $this->phases ?? [];
        $phases[] = [
            'status' => $status->value,
            'note' => $note,
            'at' => now()->toIso8601String(),
        ];

        $this->update([
            'status' => $status,
            'phases' => $phases,
            'error' => null,
        ]);
    }

    public function markFailed(string $error): void
    {
        $phases = $this->phases ?? [];
        $phases[] = [
            'status' => InstanceMigrationStatus::Failed->value,
            'note' => $error,
            'at' => now()->toIso8601String(),
        ];

        $this->update([
            'status' => InstanceMigrationStatus::Failed,
            'error' => $error,
            'phases' => $phases,
        ]);
    }

    public function markCompleted(?string $dashboardUrl = null): void
    {
        $phases = $this->phases ?? [];
        $phases[] = [
            'status' => InstanceMigrationStatus::Completed->value,
            'note' => 'Instance migration finished',
            'at' => now()->toIso8601String(),
        ];

        $this->update([
            'status' => InstanceMigrationStatus::Completed,
            'dashboard_url' => $dashboardUrl ?? $this->dashboard_url,
            'error' => null,
            'phases' => $phases,
        ]);
    }

    /**
     * @return list<array{status: string, label: string, state: string, note: ?string}>
     */
    public function stepStates(): array
    {
        $ordered = InstanceMigrationStatus::progressSteps();
        $phaseNotes = collect($this->phases ?? [])
            ->keyBy('status')
            ->map(fn (array $phase): ?string => $phase['note'] ?? null);

        $activeIndex = $this->activeStepIndex($ordered);

        return collect($ordered)->values()->map(function (InstanceMigrationStatus $step, int $index) use ($activeIndex, $phaseNotes): array {
            $state = match (true) {
                $this->status === InstanceMigrationStatus::Failed && $index === $activeIndex => 'failed',
                $this->status === InstanceMigrationStatus::Completed || ($activeIndex !== null && $index < $activeIndex) => 'done',
                $index === $activeIndex => 'active',
                default => 'pending',
            };

            return [
                'status' => $step->value,
                'label' => $step->label(),
                'state' => $state,
                'note' => $phaseNotes->get($step->value),
            ];
        })->all();
    }

    public function progressPercent(): int
    {
        if ($this->status === InstanceMigrationStatus::Completed) {
            return 100;
        }

        $steps = $this->stepStates();
        $total = count($steps);
        if ($total === 0) {
            return 0;
        }

        $done = collect($steps)->whereIn('state', ['done', 'failed'])->count();
        $active = collect($steps)->where('state', 'active')->count();

        return (int) min(99, floor((($done + ($active * 0.45)) / $total) * 100));
    }

    /**
     * @param  list<InstanceMigrationStatus>  $ordered
     */
    private function activeStepIndex(array $ordered): ?int
    {
        if ($this->status === InstanceMigrationStatus::Pending) {
            return null;
        }

        if ($this->status === InstanceMigrationStatus::Completed) {
            return count($ordered) - 1;
        }

        if ($this->status === InstanceMigrationStatus::Failed) {
            $failedPhase = collect($this->phases ?? [])
                ->reverse()
                ->first(fn (array $phase): bool => ($phase['status'] ?? null) !== InstanceMigrationStatus::Failed->value);

            if ($failedPhase) {
                foreach ($ordered as $index => $step) {
                    if ($step->value === ($failedPhase['status'] ?? null)) {
                        return $index;
                    }
                }
            }

            return null;
        }

        foreach ($ordered as $index => $step) {
            if ($step === $this->status) {
                return $index;
            }
        }

        return null;
    }
}
