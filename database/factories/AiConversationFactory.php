<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AiConversationFactory extends Factory
{
    protected $model = AiConversation::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'created_by_user_id' => User::factory(),
            'title' => $this->faker->sentence(3),
            'visibility' => AiConversation::VISIBILITY_PRIVATE,
            'status' => AiConversation::STATUS_IDLE,
        ];
    }
}
