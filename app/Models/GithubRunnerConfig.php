<?php

namespace App\Models;

use App\Enums\GithubRunnerStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GithubRunnerConfig extends BaseModel
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'labels' => 'array',
            'is_enabled' => 'boolean',
            'max_runners' => 'integer',
            'capacity_wait_timeout' => 'integer',
        ];
    }

    public function organization(): Attribute
    {
        return Attribute::make(
            get: fn () => $this->githubApp?->organization,
        );
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function githubApp(): BelongsTo
    {
        return $this->belongsTo(GithubApp::class);
    }

    public function executions(): HasMany
    {
        return $this->hasMany(GithubRunnerExecution::class);
    }

    public function activeRunnerCount(): int
    {
        return $this->executions()
            ->whereIn('status', [
                GithubRunnerStatus::Queued->value,
                GithubRunnerStatus::Provisioning->value,
                GithubRunnerStatus::Running->value,
                GithubRunnerStatus::Cleaning->value,
            ])
            ->count();
    }

    public function hasCapacity(): bool
    {
        return $this->activeRunnerCount() < $this->max_runners;
    }

    public function matchesLabels(array $requestedLabels): bool
    {
        $configLabels = collect($this->labels)->map(fn ($l) => strtolower($l));

        return collect($requestedLabels)
            ->every(fn ($label) => $configLabels->contains(strtolower($label)));
    }
}
