<?php

namespace Database\Factories;

use App\Models\Subscription;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'team_id' => Team::factory(),
            'stripe_customer_id' => 'cus_'.$this->faker->bothify('??????????'),
            'stripe_subscription_id' => null,
            'stripe_invoice_paid' => false,
            'stripe_cancel_at_period_end' => false,
            'stripe_plan_id' => null,
            'stripe_past_due' => false,
            'stripe_trial_already_ended' => false,
        ];
    }

    public function paid(): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_invoice_paid' => true,
            'stripe_subscription_id' => 'sub_'.$this->faker->bothify('??????????'),
        ]);
    }
}
