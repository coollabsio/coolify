<?php

namespace Database\Factories;

use App\Models\AuditEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    protected $model = AuditEvent::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'event' => 'ui.application.updated',
            'source' => 'ui',
            'action' => 'updated',
            'actor_type' => 'user',
            'description' => 'Application updated',
            'metadata' => [],
            'created_at' => now(),
        ];
    }
}
