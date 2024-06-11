<?php

namespace Database\Factories;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;


/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Server>
 */
class ServerFactory extends Factory
{

    public function definition(): array
    {
        return [
            'uuid' => (string) new Cuid2(7),
            'name' => fake()->domainName(),
            'description' => fake()->text(),
            'ip' => fake()->ipv4(),
            'port' => 22,
            'user' => 'root',
            'team_id' => 0,
            'private_key_id' => 0
        ];
    }
}
