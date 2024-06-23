<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Visus\Cuid2\Cuid2;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ApplicationPreview>
 */
class ApplicationPreviewFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) new Cuid2(7),
            'pull_request_id' => 0,
            'pull_request_html_url' => $this->faker->url,
            'pull_request_issue_comment_id' => $this->faker->uuid,
            'fqdn' => $this->faker->domainName,
            'status' => $this->faker->word,
            'git_type' => 'github',
        ];
    }
}
